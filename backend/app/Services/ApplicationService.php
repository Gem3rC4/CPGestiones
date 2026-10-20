<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Account;
use App\Models\Card;
use App\Models\CardApplication;
use App\Models\Credit;
use App\Models\Customer;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Repositories\ApplicationRepository;
use App\Support\Money;
use Illuminate\Support\Str;

class ApplicationService
{
    public function __construct(private ApplicationRepository $repository) {}

    public function create(array $d, string $actor): array
    {
        Customer::findOrFail($d['cliente_id']);
        $product = Product::where('code', $d['producto'])->where('active', true)->firstOrFail();
        if (! in_array($product->type, ['credito', 'debito', 'prestamo'], true)) {
            throw new BusinessException('Selecciona un producto de tarjeta o crédito.', 'INVALID_PRODUCT', 422);
        }
        $limit = $product->type !== 'debito' ? Money::cents($d['limite_solicitado'] ?? '0') : 0;
        if ($product->max_limit_cents !== null && $limit > (int) $product->max_limit_cents) {
            throw new BusinessException('El monto supera el límite de la categoría.', 'PRODUCT_LIMIT', 422);
        }
        if ($product->type === 'debito') {
            $account = Account::findOrFail($d['cuenta_id'] ?? '');
            if ($account->cliente_id !== $d['cliente_id'] || $account->estado !== 'activa') {
                throw new BusinessException('La cuenta debe pertenecer al solicitante y estar activa.', 'INVALID_ACCOUNT', 422);
            }
        }
        if (CardApplication::where('active_key', $d['cliente_id'].':'.$product->code)->exists()) {
            throw new BusinessException('El cliente ya tiene una gestión en curso para este producto.', 'MANAGEMENT_IN_PROGRESS');
        }
        $application = $this->repository->create(['folio' => 'GES-'.strtoupper(substr(bin2hex(random_bytes(8)), 0, 12)), 'cliente_id' => $d['cliente_id'], 'producto' => $product->code, 'tipo' => $product->type, 'categoria' => $product->category, 'limite_solicitado_centavos' => $limit, 'cuenta_id' => $d['cuenta_id'] ?? null, 'estado' => $product->type !== 'debito' ? 'en_evaluacion' : 'aprobada', 'version' => 1, 'active_key' => $d['cliente_id'].':'.$product->code, 'policy' => ['annual_bps' => (int) $product->annual_bps, 'minimum_bps' => (int) $product->minimum_bps, 'minimum_floor_cents' => (int) $product->minimum_floor_cents, 'cutoff_day' => (int) $product->cutoff_day, 'payment_days' => (int) $product->payment_days]]);
        app(AuditService::class)->append($application, $actor, 'solicitud_recibida', 'Gestión recibida para evaluación.');
        if ($product->type !== 'debito') {
            OutboxEvent::create(['event_id' => (string) Str::uuid(), 'type' => 'riesgo.solicitado', 'aggregate_id' => (string) $application->id, 'status' => 'pendiente', 'attempts' => 0, 'payload' => ['solicitud_id' => (string) $application->id, 'cliente_id' => $d['cliente_id'], 'producto' => $product->code, 'monto' => Money::format($limit)]]);
        }

        return $application->toArray();
    }

    public function risk(string $id, array $d, string $actor): array
    {
        $app = $this->repository->find($id);
        if ($app->estado !== 'en_evaluacion') {
            throw new BusinessException('La gestión ya tiene una decisión de riesgo.', 'INVALID_STATE');
        }
        if ((int) $d['version'] !== (int) $app->version) {
            throw new BusinessException('La gestión cambió. Actualiza antes de continuar.', 'STALE_VERSION');
        }
        $limit = Money::cents($d['limite_aprobado'] ?? '0', true);
        if ($d['resultado'] !== 'rechazada' && ($limit < 1 || $limit > $app->limite_solicitado_centavos)) {
            throw new BusinessException('El límite debe ser positivo y no superar lo solicitado.', 'INVALID_RISK_LIMIT', 422);
        }
        $state = match ($d['resultado']) {
            'aprobada' => 'aprobada','rechazada' => 'rechazada','revision_manual' => 'revision_manual'
        };
        $app->update(['estado' => $state, 'version' => $app->version + 1, 'limite_aprobado_centavos' => $d['resultado'] === 'rechazada' ? 0 : $limit, 'decision' => ['referencia' => $d['referencia'], 'resultado' => $d['resultado'], 'motivo' => $d['motivo'], 'received_at' => now()->toIso8601String()]]);
        if ($state === 'rechazada') {
            $app->unset('active_key');
        }
        app(AuditService::class)->append($app, $actor, 'decision_riesgo', $d['motivo']);

        return $app->toArray();
    }

    public function transition(string $id, array $d, string $actor): array
    {
        $app = $this->repository->find($id);
        if ((int) $app->version !== (int) $d['version']) {
            throw new BusinessException('La gestión cambió. Actualiza antes de continuar.', 'STALE_VERSION');
        }
        $target = $d['estado'];
        $allowed = ['revision_manual' => ['aprobada', 'rechazada'], 'aprobada' => ['aceptada', 'cancelada'], 'aceptada' => ['en_tramite'], 'en_tramite' => ['emitida', 'rechazada']];
        if (! in_array($target, $allowed[$app->estado] ?? [], true)) {
            throw new BusinessException('La transición solicitada no está permitida.', 'INVALID_STATE');
        }
        if ($actor === 'service:banca' && ! in_array($target, ['aceptada', 'cancelada'], true)) {
            throw new BusinessException('La banca solo puede registrar la respuesta del cliente.', 'FORBIDDEN', 403);
        }
        if ($target === 'aceptada' && empty($d['referencia_aceptacion'])) {
            throw new BusinessException('Registra la referencia del consentimiento del cliente.', 'CONSENT_REQUIRED', 422);
        }
        if ($target === 'emitida') {
            $assigned = $app->tipo === 'debito' ? 0 : Money::cents($d['limite_asignado'] ?? '0');
            if ($app->tipo !== 'debito' && ($assigned > (int) $app->limite_aprobado_centavos || $assigned > (int) $app->limite_solicitado_centavos || ! $app->decision || $app->decision['resultado'] === 'rechazada')) {
                throw new BusinessException('El límite asignado no puede superar el máximo aprobado por Analítica ni el monto solicitado.', 'RISK_LIMIT_EXCEEDED', 422);
            }
            $app->limite_asignado_centavos = $assigned;
            $app->resolucion = ['operador_id' => $actor, 'fecha' => now()->toIso8601String(), 'nota' => $d['nota'], 'limite_centavos' => $assigned];
            $d['nota'] .= ' Límite asignado: Q '.Money::format($assigned).'; máximo de Analítica: Q '.Money::format((int) $app->limite_aprobado_centavos).'.';
            if ($app->tipo === 'prestamo') {
                $credit = Credit::create(['solicitud_id' => $id, 'cliente_id' => $app->cliente_id, 'producto' => $app->producto, 'referencia' => 'CRE-'.strtoupper(bin2hex(random_bytes(6))), 'limite_centavos' => $assigned, 'saldo_centavos' => 0, 'desembolsado_centavos' => 0, 'estado' => 'autorizado', 'moneda' => 'GTQ', 'operador_id' => $actor]);
                $app->credito_id = (string) $credit->id;
            } else {
                $card = Card::create(['solicitud_id' => $id, 'cliente_id' => $app->cliente_id, 'producto' => $app->producto, 'tipo' => $app->tipo, 'categoria' => $app->categoria, 'cuenta_id' => $app->cuenta_id, 'referencia' => 'CP-'.strtoupper(bin2hex(random_bytes(6))), 'ultimos_cuatro' => (string) random_int(1000, 9999), 'limite_centavos' => $assigned, 'principal_centavos' => 0, 'interes_centavos' => 0, 'estado' => 'activa', 'policy' => $app->policy, 'emitida_el' => now()->timezone(config('operations.timezone'))->toDateString()]);
                $app->tarjeta_id = (string) $card->id;
            }
        }
        $app->estado = $target;
        $app->version = (int) $app->version + 1;
        if ($target === 'aceptada') {
            $app->referencia_aceptacion = $d['referencia_aceptacion'];
        }
        $app->save();
        if (in_array($target, ['rechazada', 'cancelada', 'emitida'], true)) {
            $app->unset('active_key');
        }
        app(AuditService::class)->append($app, $actor, 'cambio_estado', $d['nota']);
        if ($target === 'en_tramite') {
            app(AlertService::class)->publish('gestion.en_tramite:'.$app->id, 'solicitudes.read', 'Nueva gestión en trámite', 'Revisa la gestión y asigna el límite de crédito.', 'solicitudes', (string) $app->id);
        }

        return $app->toArray();
    }
}
