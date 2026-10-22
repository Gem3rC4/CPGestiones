<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\OutboxEvent;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class OutboxService
{
    private const MAX_ATTEMPTS = 8;

    public function deliver(): int
    {
        $sent = 0;
        // URLs and secrets come from the panel (Integraciones) or, as a fallback, from .env.
        $settings = app(IntegrationSettings::class);
        $targets = ['banca' => $settings->get('banca'), 'banca_abonos' => $settings->get('banca_abonos'), 'riesgos' => $settings->get('riesgos')];
        OutboxEvent::where('status', 'enviando')->where('lease_until', '<', now()->toIso8601String())->update(['status' => 'pendiente']);
        // _id breaks ties in the same second: an account is always registered before its opening credit.
        foreach (OutboxEvent::where('status', 'pendiente')->orderBy('created_at')->orderBy('_id')->limit(50)->get() as $event) {
            $system = match ($event->type) {
                'riesgo.solicitado' => 'riesgos',
                'banca.abono', 'banca.cuenta' => 'banca_abonos',
                default => 'banca',
            };
            $target = $targets[$system];
            $url = $target['url'];
            if (! $url || ! $target['secret']) {
                continue;
            }
            if ($event->next_attempt && $event->next_attempt > now()->toIso8601String()) {
                continue;
            }
            if (! str_starts_with($url, 'https://') && ! app()->environment('local', 'testing')) {
                throw new \RuntimeException('Las integraciones requieren HTTPS.');
            }
            $lease = bin2hex(random_bytes(16));
            if (OutboxEvent::where('_id', $event->id)->where('status', 'pendiente')->update(['status' => 'enviando', 'lease' => $lease, 'lease_until' => now()->addMinutes(2)->toIso8601String()]) !== 1) {
                continue;
            }
            try {
                if ($event->type === 'banca.cuenta') {
                    $outcome = $this->sendAccount($event, self::accountsUrl($url), $target['secret']);
                } elseif ($system === 'banca_abonos') {
                    $outcome = $this->sendCredit($event, $url, $target['secret']);
                } else {
                    $this->sendEvent($event, $url, $target['secret'], $system === 'riesgos');
                    $outcome = ['status' => 'enviado'];
                }
            } catch (\Throwable) {
                $outcome = ['status' => 'reintentar', 'error' => 'El destino no confirmó la recepción.'];
            }
            $attempt = (int) $event->attempts + 1;
            if ($outcome['status'] === 'enviado') {
                OutboxEvent::where('_id', $event->id)->where('lease', $lease)->update(['status' => 'enviado', 'sent_at' => now()->toIso8601String(), 'attempts' => $attempt, 'respuesta' => $outcome['respuesta'] ?? null, 'last_error' => null]);
                $sent++;
            } else {
                $failed = $outcome['status'] === 'rechazado' || $attempt >= self::MAX_ATTEMPTS;
                OutboxEvent::where('_id', $event->id)->where('lease', $lease)->update(['status' => $failed ? 'fallido' : 'pendiente', 'attempts' => $attempt, 'next_attempt' => now()->addSeconds(min(3600, 2 ** $attempt * 15))->toIso8601String(), 'last_error' => $outcome['error']]);
            }
        }

        return $sent;
    }

    /**
     * Failed events go back to the queue (for example after fixing the key or once the customer exists in BancaVirtual).
     */
    public function retryFailed(?string $id = null): int
    {
        return OutboxEvent::where('status', 'fallido')->when($id, fn ($q) => $q->where('_id', $id))->update(['status' => 'pendiente', 'attempts' => 0, 'next_attempt' => null]);
    }

    private function sendEvent(OutboxEvent $event, string $url, string $secret, bool $risk): void
    {
        $body = json_encode(['event_id' => $event->event_id, 'type' => $event->type, 'data' => $event->payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) time();
        $http = Http::timeout(15)->connectTimeout(5)->withoutRedirecting()->acceptJson()->withHeaders(['Idempotency-Key' => $event->event_id]);
        $http = $risk ? $http->withToken($secret) : $http->withHeaders(['X-CP-Timestamp' => $timestamp, 'X-CP-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, $secret)]);
        $response = $http->withBody($body, 'application/json')->post($url);
        $response->throw();
        if ($response->status() >= 300) {
            throw new \RuntimeException('El destino no aceptó el evento.');
        }
    }

    /**
     * The accounts route of BancaVirtual sits next to its abonos route (…/admin/abonos → …/admin/cuentas).
     */
    public static function accountsUrl(string $creditsUrl): string
    {
        $base = rtrim($creditsUrl, '/');

        return (str_ends_with($base, '/abonos') ? substr($base, 0, -7) : dirname($base)).'/cuentas';
    }

    /**
     * POST /admin/cuentas of BancaVirtual: registers the account opened here with the SAME number, linked to
     * the customer's DPI and e-mail. It is idempotent there: an existing account of that type is returned.
     */
    private function sendAccount(OutboxEvent $event, string $url, string $key): array
    {
        $p = $event->payload;
        $customer = Customer::find($p['cliente_id']);
        $dpi = $customer?->dpi;
        if (! is_string($dpi) || ! preg_match('/^[0-9]{13}$/D', $dpi) || ! $customer->email) {
            return ['status' => 'rechazado', 'error' => 'El cliente no tiene DPI y correo válidos para registrarlo en BancaVirtual.'];
        }
        $type = ($p['cuenta_tipo'] ?? 'ahorro') === 'monetaria' ? 'MONETARIA' : 'AHORRO';
        $response = Http::timeout(30)->connectTimeout(10)->withoutRedirecting()->acceptJson()->withHeaders(['X-Service-Key' => $key])
            ->post($url, array_filter(['dpi' => $dpi, 'correo' => mb_strtolower($customer->email), 'tipo' => $type, 'numero' => $p['numero'],
                'alias' => $type === 'AHORRO' ? 'Cuenta de ahorro' : 'Cuenta monetaria', 'nombre' => mb_substr((string) $customer->nombres, 0, 80) ?: null, 'apellido' => mb_substr((string) $customer->apellidos, 0, 80) ?: null]));
        $status = $response->status();
        $message = $this->messageOf($response);
        if ($response->successful()) {
            $remote = (string) $response->json('cuenta.numero');
            if ($remote !== $p['numero']) {
                return ['status' => 'rechazado', 'error' => 'BancaVirtual ya tenía otra cuenta '.strtolower($type).' para este DPI (número '.$remote.'); no registró el número '.$p['numero'].'.'];
            }

            return ['status' => 'enviado', 'respuesta' => ['cuenta' => $remote, 'creada' => (bool) $response->json('creada'), 'saldo' => $response->json('cuenta.saldo')]];
        }

        return match (true) {
            $status === 401 || $status === 403 => ['status' => 'reintentar', 'error' => 'BancaVirtual rechazó la llave de servicio. Revísala en Integraciones.'],
            in_array($status, [400, 409, 422], true) => ['status' => 'rechazado', 'error' => 'BancaVirtual no registró la cuenta'.($message ? ': '.$message : '.')],
            default => ['status' => 'reintentar', 'error' => 'BancaVirtual respondió con un error HTTP '.$status.'.'],
        };
    }

    /**
     * POST /admin/abonos of BancaVirtual. The account is located by its number, which is the same in both
     * systems; accounts opened before numbers were shared are located by DPI and account type instead.
     * The reference is unique per credited movement, so a retry never credits twice.
     */
    private function sendCredit(OutboxEvent $event, string $url, string $key): array
    {
        $p = $event->payload;
        $dpi = Customer::find($p['cliente_id'])?->dpi;
        if (! is_string($dpi) || ! preg_match('/^[0-9]{13}$/D', $dpi)) {
            return ['status' => 'rechazado', 'error' => 'El cliente no tiene un DPI válido para ubicarlo en BancaVirtual.'];
        }
        $type = ($p['cuenta_tipo'] ?? 'ahorro') === 'monetaria' ? 'MONETARIA' : 'AHORRO';
        $number = $p['cuenta_numero'] ?? null;
        $response = $this->postCredit($url, $key, $p, $number ? ['cuentaNumero' => $number] : ['dpi' => $dpi, 'tipoCuenta' => $type]);
        if ($response->status() === 404 && $number) {
            $response = $this->postCredit($url, $key, $p, ['dpi' => $dpi, 'tipoCuenta' => $type]);
        }
        $status = $response->status();
        if ($response->successful()) {
            return ['status' => 'enviado', 'respuesta' => array_intersect_key((array) $response->json(), array_flip(['duplicado', 'cuenta', 'monto', 'saldoDespues']))];
        }
        $message = $this->messageOf($response);

        return match (true) {
            // The person may not have entered BancaVirtual yet: keep retrying, it may appear later.
            $status === 404 => ['status' => 'reintentar', 'error' => 'BancaVirtual no encontró la cuenta del cliente'.($message ? ': '.$message : '.')],
            $status === 401 || $status === 403 => ['status' => 'reintentar', 'error' => 'BancaVirtual rechazó la llave de servicio. Revísala en Integraciones.'],
            in_array($status, [400, 422], true) => ['status' => 'rechazado', 'error' => 'BancaVirtual no aceptó el abono'.($message ? ': '.$message : '.')],
            default => ['status' => 'reintentar', 'error' => 'BancaVirtual respondió con un error HTTP '.$status.'.'],
        };
    }

    private function postCredit(string $url, string $key, array $p, array $destination): Response
    {
        return Http::timeout(30)->connectTimeout(10)->withoutRedirecting()->acceptJson()->withHeaders(['X-Service-Key' => $key])
            ->post($url, $destination + ['monto' => round($p['monto_centavos'] / 100, 2), 'concepto' => mb_substr($p['concepto'], 0, 100), 'referencia' => $p['referencia'], 'origen' => $p['origen']]);
    }

    private function messageOf(Response $response): ?string
    {
        $message = $response->json('message');
        if (is_array($message)) {
            $message = implode('. ', array_filter($message, 'is_string'));
        }

        return is_string($message) && $message !== '' ? mb_substr($message, 0, 200) : null;
    }
}
