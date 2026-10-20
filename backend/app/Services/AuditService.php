<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CardApplication;
use App\Models\OutboxEvent;
use Illuminate\Support\Str;

class AuditService
{
    public function append(CardApplication $application, string $actor, string $action, string $note): void
    {
        $sequence = (int) ($application->audit_sequence ?? 0) + 1;
        $data = ['solicitud_id' => (string) $application->id, 'sequence' => $sequence, 'actor' => $actor, 'action' => $action, 'estado' => $application->estado, 'note' => $note, 'time' => now()->toIso8601String(), 'previous_hash' => $application->audit_hash ?? str_repeat('0', 64)];
        $data['hash'] = $this->hash($data);
        AuditEvent::create($data);
        $application->update(['audit_sequence' => $sequence, 'audit_hash' => $data['hash']]);
        OutboxEvent::create(['event_id' => (string) Str::uuid(), 'type' => 'solicitud.actualizada', 'aggregate_id' => (string) $application->id, 'sequence' => $sequence, 'status' => 'pendiente', 'attempts' => 0, 'payload' => ['solicitud_id' => (string) $application->id, 'estado' => $application->estado, 'sequence' => $sequence, 'nota' => $note, 'gestion_ref' => $application->gestion_ref, 'limite_asignado_centavos' => $application->limite_asignado_centavos, 'tarjeta_id' => $application->tarjeta_id, 'credito_id' => $application->credito_id]]);
    }

    public function hash(array $data): string
    {
        return hash_hmac('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function verify(CardApplication $application): bool
    {
        $prev = str_repeat('0', 64);
        $seq = 0;
        foreach (AuditEvent::where('solicitud_id', (string) $application->id)->orderBy('sequence')->get() as $event) {
            $data = $event->only(['solicitud_id', 'sequence', 'actor', 'action', 'estado', 'note', 'time', 'previous_hash']);
            if ($event->sequence !== ++$seq || $prev !== $event->previous_hash || ! hash_equals($event->hash, $this->hash($data))) {
                return false;
            }
            $prev = $event->hash;
        }

        return $seq === $application->audit_sequence && $prev === $application->audit_hash;
    }
}
