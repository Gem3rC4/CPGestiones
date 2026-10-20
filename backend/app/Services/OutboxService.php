<?php

namespace App\Services;

use App\Models\OutboxEvent;
use Illuminate\Support\Facades\Http;

class OutboxService
{
    public function deliver(): int
    {
        $sent = 0;
        OutboxEvent::where('status', 'enviando')->where('lease_until', '<', now()->toIso8601String())->update(['status' => 'pendiente']);
        foreach (OutboxEvent::where('status', 'pendiente')->orderBy('created_at')->limit(50)->get() as $event) {
            $risk = $event->type === 'riesgo.solicitado';
            $url = config($risk ? 'operations.risk_url' : 'operations.bank_url');
            if (! $url || (! $risk && ! config('operations.bank_secret')) || ($risk && ! config('operations.risk_token'))) {
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
                $body = json_encode(['event_id' => $event->event_id, 'type' => $event->type, 'data' => $event->payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $timestamp = (string) time();
                $http = Http::timeout(15)->connectTimeout(5)->withoutRedirecting()->acceptJson()->withHeaders(['Idempotency-Key' => $event->event_id]);
                if ($risk) {
                    $http = $http->withToken(config('operations.risk_token'));
                } else {
                    $http = $http->withHeaders(['X-CP-Timestamp' => $timestamp, 'X-CP-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, config('operations.bank_secret'))]);
                }
                $response = $http->withBody($body, 'application/json')->post($url);
                $response->throw();
                if ($response->status() >= 300) {
                    throw new \RuntimeException('El destino no aceptó el evento.');
                }
                OutboxEvent::where('_id', $event->id)->where('lease', $lease)->update(['status' => 'enviado', 'sent_at' => now()->toIso8601String(), 'attempts' => (int) $event->attempts + 1]);
                $sent++;
            } catch (\Throwable $e) {
                $attempt = (int) $event->attempts + 1;
                OutboxEvent::where('_id', $event->id)->where('lease', $lease)->update(['status' => $attempt >= 8 ? 'fallido' : 'pendiente', 'attempts' => $attempt, 'next_attempt' => now()->addSeconds(min(3600, 2 ** $attempt * 15))->toIso8601String(), 'last_error' => 'El destino no confirmó la recepción.']);
            }
        }

        return $sent;
    }
}
