<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Connection data for the systems Gestiones calls: Banca Virtual (signed webhook) and CP Analítica
 * (bearer token). An administrator sets the real URL from the panel; .env values remain the fallback.
 */
class IntegrationSettings
{
    public const SYSTEMS = ['banca' => 'CP Banca Virtual', 'banca_abonos' => 'Abonos a cuentas de BancaVirtual', 'riesgos' => 'CP Analítica'];

    private const ENV = ['banca' => ['operations.bank_url', 'operations.bank_secret'], 'banca_abonos' => ['operations.bank_credits_url', 'operations.bank_credits_key'], 'riesgos' => ['operations.risk_url', 'operations.risk_token']];

    /**
     * @return array{url: ?string, secret: ?string, source: ?string}
     */
    public function get(string $system): array
    {
        $stored = IntegrationSetting::where('sistema', $system)->first();
        if ($stored && $stored->url) {
            return ['url' => $stored->url, 'secret' => $stored->getRawOriginal('secreto') ? Crypt::decryptString($stored->getRawOriginal('secreto')) : null, 'source' => 'panel'];
        }
        [$urlKey, $secretKey] = self::ENV[$system];
        $url = config($urlKey);
        $secret = config($secretKey);

        return ['url' => $url ?: null, 'secret' => $secret ?: null, 'source' => $url ? 'entorno' : null];
    }

    public function overview(): array
    {
        $result = [];
        foreach (self::SYSTEMS as $system => $name) {
            $config = $this->get($system);
            $stored = IntegrationSetting::where('sistema', $system)->first();
            $result[] = ['sistema' => $system, 'nombre' => $name, 'url' => $config['url'], 'secreto_configurado' => $config['secret'] !== null, 'origen' => $config['source'],
                'ultima_prueba' => $stored?->ultima_prueba, 'actualizado_el' => $stored?->updated_at?->toIso8601String()];
        }

        return $result;
    }

    public function save(string $system, array $data, string $actor): array
    {
        $this->assertSafeUrl($data['url']);
        $setting = IntegrationSetting::firstOrNew(['sistema' => $system]);
        $setting->url = $data['url'];
        if (isset($data['secreto']) && $data['secreto'] !== '') {
            $setting->secreto = Crypt::encryptString($data['secreto']);
        }
        if (! $setting->getAttribute('secreto') && ! $this->get($system)['secret']) {
            throw new BusinessException(match ($system) {
                'banca' => 'Indica el secreto compartido para firmar los avisos.',
                'banca_abonos' => 'Indica la llave de servicio (X-Service-Key) de BancaVirtual.',
                default => 'Indica el token que entregó CP Analítica.',
            }, 'SECRET_REQUIRED', 422);
        }
        $setting->actualizado_por = $actor;
        $setting->save();
        app(SecurityService::class)->record('integracion_configurada', $actor, ['sistema' => $system, 'host' => parse_url($data['url'], PHP_URL_HOST)]);

        return collect($this->overview())->firstWhere('sistema', $system);
    }

    /**
     * Sends a harmless signed "conexion.prueba" event so the team can confirm the URL during integration.
     */
    public function test(string $system, string $actor): array
    {
        $config = $this->get($system);
        if (! $config['url'] || ! $config['secret']) {
            throw new BusinessException($system === 'banca_abonos' && $config['url']
                ? 'Falta guardar la llave de servicio: pégala y pulsa Guardar (o Guardar y probar).'
                : 'Primero configura la URL y el secreto de '.self::SYSTEMS[$system].' y pulsa Guardar.', 'INTEGRATION_NOT_CONFIGURED', 422);
        }
        $this->assertSafeUrl($config['url']);
        if ($system === 'banca_abonos') {
            return $this->testCredits($config, $actor);
        }
        $body = json_encode(['event_id' => (string) Str::uuid(), 'type' => 'conexion.prueba', 'data' => ['origen' => 'CP Gestiones', 'enviado_el' => now()->toIso8601String()]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) time();
        $request = Http::timeout(15)->connectTimeout(5)->withoutRedirecting()->acceptJson();
        $request = $system === 'banca'
            ? $request->withHeaders(['X-CP-Timestamp' => $timestamp, 'X-CP-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, $config['secret'])])
            : $request->withToken($config['secret']);
        $started = microtime(true);
        try {
            $response = $request->withBody($body, 'application/json')->post($config['url']);
            $result = ['conectado' => $response->successful(), 'http_status' => $response->status(), 'mensaje' => $response->successful() ? 'El sistema respondió correctamente.' : 'El sistema respondió con un error HTTP '.$response->status().'.'];
        } catch (\Throwable) {
            $result = ['conectado' => false, 'http_status' => null, 'mensaje' => 'No se pudo conectar con la URL (sin respuesta o tiempo agotado).'];
        }
        $result['milisegundos'] = (int) round((microtime(true) - $started) * 1000);
        $result['probado_el'] = now()->toIso8601String();
        IntegrationSetting::where('sistema', $system)->update(['ultima_prueba' => $result]);
        app(SecurityService::class)->record('integracion_probada', $actor, ['sistema' => $system, 'conectado' => $result['conectado']]);

        return $result;
    }

    /**
     * Checks URL and key without moving money: an empty request must be rejected for its data (400/422),
     * which proves the key was accepted; 401 means the key is wrong.
     */
    private function testCredits(array $config, string $actor): array
    {
        $started = microtime(true);
        try {
            $response = Http::timeout(30)->connectTimeout(10)->withoutRedirecting()->acceptJson()->withHeaders(['X-Service-Key' => $config['secret']])->post($config['url'], ['prueba' => true]);
            $status = $response->status();
            $result = match (true) {
                in_array($status, [400, 422], true) => ['conectado' => true, 'mensaje' => 'BancaVirtual aceptó la llave. No se acreditó dinero en la prueba.'],
                $status === 401 || $status === 403 => ['conectado' => false, 'mensaje' => 'BancaVirtual rechazó la llave (HTTP '.$status.'). Revisa la llave de servicio.'],
                $response->successful() => ['conectado' => true, 'mensaje' => 'BancaVirtual respondió correctamente.'],
                default => ['conectado' => false, 'mensaje' => 'BancaVirtual respondió con un error HTTP '.$status.'.'],
            };
            $result['http_status'] = $status;
        } catch (\Throwable) {
            $result = ['conectado' => false, 'http_status' => null, 'mensaje' => 'No se pudo conectar con la URL (sin respuesta o tiempo agotado). El servidor gratuito puede tardar en despertar: vuelve a probar.'];
        }
        $result['milisegundos'] = (int) round((microtime(true) - $started) * 1000);
        $result['probado_el'] = now()->toIso8601String();
        IntegrationSetting::where('sistema', 'banca_abonos')->update(['ultima_prueba' => $result]);
        app(SecurityService::class)->record('integracion_probada', $actor, ['sistema' => 'banca_abonos', 'conectado' => $result['conectado']]);

        return $result;
    }

    private function assertSafeUrl(string $url): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== 'https' && ! app()->environment('local', 'testing')) {
            throw new BusinessException('Las integraciones requieren una URL https.', 'HTTPS_REQUIRED', 422);
        }
    }
}
