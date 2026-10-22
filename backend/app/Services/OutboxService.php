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
        foreach (OutboxEvent::where('status', 'pendiente')->orderBy('created_at')->limit(50)->get() as $event) {
            $system = match ($event->type) {
                'riesgo.solicitado' => 'riesgos',
                'banca.abono' => 'banca_abonos',
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
                if ($system === 'banca_abonos') {
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
     * POST /admin/abonos of BancaVirtual. The customer is located by DPI and account type (BancaVirtual has its
     * own account numbers). The reference is unique per credited movement, so a retry never credits twice.
     */
    private function sendCredit(OutboxEvent $event, string $url, string $key): array
    {
        $p = $event->payload;
        $dpi = Customer::find($p['cliente_id'])?->dpi;
        if (! is_string($dpi) || ! preg_match('/^[0-9]{13}$/D', $dpi)) {
            return ['status' => 'rechazado', 'error' => 'El cliente no tiene un DPI válido para ubicarlo en BancaVirtual.'];
        }
        $type = ($p['cuenta_tipo'] ?? 'ahorro') === 'monetaria' ? 'MONETARIA' : 'AHORRO';
        $response = $this->postCredit($url, $key, $p, $dpi, $type);
        // The person may only have the savings account BancaVirtual opens by default.
        if ($response->status() === 404 && $type === 'MONETARIA') {
            $response = $this->postCredit($url, $key, $p, $dpi, 'AHORRO');
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

    private function postCredit(string $url, string $key, array $p, string $dpi, string $type): Response
    {
        return Http::timeout(30)->connectTimeout(10)->withoutRedirecting()->acceptJson()->withHeaders(['X-Service-Key' => $key])
            ->post($url, ['dpi' => $dpi, 'tipoCuenta' => $type, 'monto' => round($p['monto_centavos'] / 100, 2), 'concepto' => $p['concepto'], 'referencia' => $p['referencia'], 'origen' => $p['origen']]);
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
