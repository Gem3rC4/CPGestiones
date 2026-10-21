<?php

namespace App\Services;

use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * External API (outside the three CP systems): reference exchange rate GTQ per USD.
 * 1. Banco de Guatemala SOAP service (official rate of the day).
 * 2. open.er-api.com as fallback.
 * The last good value is kept, so the panel and reports still show a rate (flagged as stale) if both fail.
 */
class ExchangeRateService
{
    private const CACHE_KEY = 'tipo_cambio_usd';

    public function current(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }
        $rate = $this->fromBanguat() ?? $this->fromFallback();
        if ($rate) {
            IntegrationSetting::updateOrCreate(['sistema' => 'tipo_cambio'], ['ultimo' => $rate]);
            Cache::put(self::CACHE_KEY, $rate, now()->addMinutes(config('operations.exchange.cache_minutes')));

            return $rate;
        }
        $last = IntegrationSetting::where('sistema', 'tipo_cambio')->first()?->ultimo;

        return $last ? ['desactualizado' => true] + $last : ['disponible' => false, 'mensaje' => 'El servicio de tipo de cambio no respondió.'];
    }

    private function fromBanguat(): ?array
    {
        $envelope = '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><TipoCambioDia xmlns="http://www.banguat.gob.gt/variables/ws/" /></soap:Body></soap:Envelope>';
        try {
            $response = Http::timeout(8)->withHeaders(['Content-Type' => 'text/xml; charset=utf-8', 'SOAPAction' => '"http://www.banguat.gob.gt/variables/ws/TipoCambioDia"'])
                ->withBody($envelope, 'text/xml; charset=utf-8')->post(config('operations.exchange.banguat_url'));
            if ($response->successful() && preg_match('#<fecha>([0-9/]+)</fecha>\s*<referencia>([0-9.]+)</referencia>#', $response->body(), $match)) {
                return $this->result((float) $match[2], 'Banco de Guatemala', $match[1]);
            }
        } catch (Throwable $e) {
            report($e);
        }

        return null;
    }

    private function fromFallback(): ?array
    {
        try {
            $response = Http::timeout(8)->acceptJson()->get(config('operations.exchange.fallback_url'));
            $rate = $response->json('rates.GTQ');
            if ($response->successful() && is_numeric($rate)) {
                return $this->result((float) $rate, 'ExchangeRate-API (respaldo)', now()->timezone(config('operations.timezone'))->format('d/m/Y'));
            }
        } catch (Throwable $e) {
            report($e);
        }

        return null;
    }

    private function result(float $rate, string $source, string $date): array
    {
        return ['disponible' => true, 'moneda' => 'USD', 'quetzales_por_dolar' => round($rate, 5), 'fecha' => $date, 'fuente' => $source, 'consultado_el' => now()->toIso8601String(), 'desactualizado' => false];
    }
}
