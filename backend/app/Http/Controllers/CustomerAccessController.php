<?php

namespace App\Http\Controllers;

use App\Http\Requests\OperationRequest;
use App\Models\Customer;
use App\Models\CustomerSession;
use App\Services\SecurityService;
use Illuminate\Http\JsonResponse;

/**
 * Bank staff administer the Banca Virtual credentials stored centrally in Gestiones.
 */
class CustomerAccessController extends ApiController
{
    public function show(string $id): JsonResponse
    {
        return $this->ok($this->view(Customer::findOrFail($id)));
    }

    public function update(OperationRequest $request, string $id): JsonResponse
    {
        return $this->mutation($request, function (array $data, string $actor) use ($id): array {
            $customer = Customer::findOrFail($id);
            $updates = ['active' => (bool) $data['activo']];
            if ($data['activo']) {
                // Re-enabling also clears a temporary lock from failed attempts.
                $updates += ['failed_attempts' => 0, 'locked_until' => null];
            }
            $customer->update($updates);
            $closed = $data['activo'] ? 0 : CustomerSession::where('cliente_id', $id)->delete();
            app(SecurityService::class)->record($data['activo'] ? 'acceso_cliente_activado' : 'acceso_cliente_bloqueado', $actor, ['cliente_id' => $id, 'nota' => $data['nota'], 'sesiones_cerradas' => $closed]);

            return $this->view($customer->refresh());
        });
    }

    public function closeSessions(OperationRequest $request, string $id): JsonResponse
    {
        return $this->mutation($request, function (array $data, string $actor) use ($id): array {
            $customer = Customer::findOrFail($id);
            $closed = CustomerSession::where('cliente_id', $id)->delete();
            app(SecurityService::class)->record('sesiones_cliente_cerradas', $actor, ['cliente_id' => $id, 'sesiones_cerradas' => $closed]);

            return $this->view($customer) + ['sesiones_cerradas' => $closed];
        });
    }

    private function view(Customer $customer): array
    {
        return [
            'cliente_id' => (string) $customer->id, 'acceso_app' => $customer->acceso_app, 'activo' => (bool) ($customer->active ?? true),
            'bloqueado_hasta' => $customer->locked_until?->isFuture() ? $customer->locked_until->toIso8601String() : null,
            'intentos_fallidos' => (int) $customer->failed_attempts,
            'sesiones' => CustomerSession::where('cliente_id', (string) $customer->id)->where('expires_at', '>', now())->orderBy('created_at', 'desc')->get()
                ->map(fn (CustomerSession $s): array => ['id' => (string) $s->id, 'dispositivo' => $s->dispositivo, 'ip' => $s->ip, 'inicio' => $s->created_at?->toIso8601String(), 'ultima_actividad' => $s->ultima_actividad?->toIso8601String(), 'vence' => $s->expires_at?->toIso8601String()])->values()->all(),
        ];
    }
}
