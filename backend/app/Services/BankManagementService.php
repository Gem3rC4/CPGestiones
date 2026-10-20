<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\CardApplication;
use App\Models\Product;
use App\Models\RiskAssessment;
use App\Repositories\CustomerRepository;
use App\Support\Money;

class BankManagementService
{
    public function assessment(array $data): array
    {
        ksort($data);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $previous = RiskAssessment::where('evaluacion_ref', $data['evaluacion_ref'])->first();
        if ($previous) {
            if (! hash_equals($previous->fingerprint, $hash)) {
                throw new BusinessException('La evaluación ya existe con otros datos.', 'ASSESSMENT_CONFLICT');
            }

            return $previous->toArray();
        }
        $customer = app(CustomerRepository::class)->resolve($data);
        $product = Product::where('code', $data['producto'])->where('active', true)->whereIn('type', ['credito', 'prestamo'])->firstOrFail();
        $requested = Money::cents($data['monto_solicitado']);
        $approved = Money::cents($data['limite_aprobado'], true);
        if (($product->max_limit_cents !== null && $requested > $product->max_limit_cents) || $approved > $requested || ($data['resultado'] === 'aprobada' && $approved === 0) || ($data['resultado'] === 'rechazada' && $approved !== 0)) {
            throw new BusinessException('Los importes no corresponden al resultado o al producto.', 'INVALID_RISK_LIMIT', 422);
        }
        $assessment = RiskAssessment::create(['evaluacion_ref' => $data['evaluacion_ref'], 'cliente_id' => (string) $customer->id, 'producto' => $product->code, 'resultado' => $data['resultado'], 'solicitado_centavos' => $requested, 'aprobado_centavos' => $approved, 'motivo' => $data['motivo'], 'fingerprint' => $hash]);
        app(SecurityService::class)->record('evaluacion_registrada', 'service:riesgos', ['evaluacion_id' => (string) $assessment->id]);

        return $assessment->toArray();
    }

    public function receive(array $data): array
    {
        ksort($data);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $previous = CardApplication::where('gestion_ref', $data['gestion_ref'])->first();
        if ($previous) {
            if (! hash_equals($previous->recepcion_hash, $hash)) {
                throw new BusinessException('La gestión ya existe con otros datos.', 'MANAGEMENT_CONFLICT');
            }

            return $previous->toArray();
        }
        $customer = app(CustomerRepository::class)->resolve($data);
        $assessment = RiskAssessment::where('evaluacion_ref', $data['evaluacion_ref'])->where('cliente_id', (string) $customer->id)->firstOrFail();
        if ($assessment->resultado !== 'aprobada') {
            throw new BusinessException('Se requiere una evaluación aprobada por Analítica.', 'RISK_APPROVAL_REQUIRED');
        }
        $product = Product::where('code', $assessment->producto)->where('active', true)->firstOrFail();
        if (CardApplication::where('evaluacion_ref', $assessment->evaluacion_ref)->exists()) {
            throw new BusinessException('Esta evaluación ya se utilizó en otra gestión. Solicita una nueva evaluación.', 'ASSESSMENT_ALREADY_USED');
        }
        if (CardApplication::where('active_key', $customer->id.':'.$product->code)->exists()) {
            throw new BusinessException('El cliente ya tiene una gestión en curso para este producto.', 'MANAGEMENT_IN_PROGRESS');
        }
        $management = CardApplication::create([
            'folio' => 'GES-'.strtoupper(bin2hex(random_bytes(6))), 'gestion_ref' => $data['gestion_ref'], 'evaluacion_ref' => $assessment->evaluacion_ref,
            'cliente_id' => (string) $customer->id, 'producto' => $product->code, 'tipo' => $product->type, 'categoria' => $product->category,
            'estado' => 'en_tramite', 'version' => 1, 'active_key' => $customer->id.':'.$product->code, 'origen' => 'banca_virtual',
            'limite_solicitado_centavos' => $assessment->solicitado_centavos, 'limite_aprobado_centavos' => $assessment->aprobado_centavos,
            'referencia_aceptacion' => $data['referencia_aceptacion'], 'aceptada_en' => $data['aceptada_en'], 'recepcion_hash' => $hash,
            'decision' => ['referencia' => $assessment->evaluacion_ref, 'resultado' => 'aprobada', 'motivo' => $assessment->motivo, 'received_at' => $assessment->created_at->toIso8601String()],
            'policy' => $product->only(['annual_bps', 'minimum_bps', 'minimum_floor_cents', 'cutoff_day', 'payment_days']),
        ]);
        app(AuditService::class)->append($management, 'service:banca', 'gestion_recibida', 'Oferta aceptada en Banca Virtual. Referencia: '.$data['referencia_aceptacion']);
        app(AlertService::class)->publish('gestion.en_tramite:'.$management->id, 'solicitudes.read', 'Nueva gestión en trámite', 'El cliente aceptó la oferta. Revisa la gestión y asigna el límite.', 'solicitudes', (string) $management->id);

        return $management->toArray();
    }
}
