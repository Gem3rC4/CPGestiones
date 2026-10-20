<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Account;
use App\Models\ServicePayment;
use App\Repositories\CustomerRepository;
use App\Support\Money;
use Illuminate\Pagination\LengthAwarePaginator;

class ServicePaymentService
{
    public function __construct(private CustomerRepository $customers, private AccountService $accounts) {}

    /**
     * @return list<array{codigo: string, nombre: string, categoria: string, campo: string}>
     */
    public function catalog(): array
    {
        $services = [];
        foreach (config('operations.services') as $code => $service) {
            $services[] = ['codigo' => $code] + $service;
        }

        return $services;
    }

    // The controller's IdempotencyService holds the transaction: debit, settlement and receipt commit together.
    public function pay(array $data, string $actor): array
    {
        $customer = $this->customers->resolve($data);
        $source = Account::findOrFail($data['origen_id']);
        if ($source->cliente_id !== (string) $customer->id || $source->system_key) {
            throw new BusinessException('La cuenta no pertenece al cliente autenticado.', 'FORBIDDEN', 403);
        }
        $service = config('operations.services.'.$data['servicio']);
        $reference = mb_strtoupper($data['referencia_servicio']);
        $cents = Money::cents($data['monto']);
        $settlement = Account::where('system_key', 'servicios')->first();
        if (! $settlement) {
            throw new BusinessException('El pago de servicios no está habilitado. Reintenta más tarde.', 'SERVICE_UNAVAILABLE', 503);
        }
        $movement = $this->accounts->move((string) $source->id, (string) $settlement->id, $cents, $actor, 'Pago '.$service['nombre'].' · '.$reference, 'pago_servicio');
        $payment = ServicePayment::create([
            'cliente_id' => (string) $customer->id, 'cuenta_id' => (string) $source->id, 'cuenta_numero' => $source->numero,
            'servicio' => $data['servicio'], 'servicio_nombre' => $service['nombre'], 'categoria' => $service['categoria'],
            'referencia_servicio' => $reference, 'monto_centavos' => $cents, 'moneda' => 'GTQ',
            'transaccion_id' => $movement['transaccion_id'], 'estado' => 'completado', 'actor' => $actor,
        ]);

        return $this->view($payment) + ['saldo_disponible' => Money::format((int) $source->refresh()->saldo_centavos)];
    }

    public function history(array $identity, int $page): LengthAwarePaginator
    {
        $customer = $this->customers->resolve($identity);
        $payments = ServicePayment::where('cliente_id', (string) $customer->id)->orderBy('created_at', 'desc')->paginate(25, ['*'], 'page', $page);
        $payments->setCollection($payments->getCollection()->map(fn (ServicePayment $payment): array => $this->view($payment)));

        return $payments;
    }

    private function view(ServicePayment $payment): array
    {
        return [
            'id' => (string) $payment->id, 'comprobante' => $payment->transaccion_id,
            'servicio' => $payment->servicio, 'servicio_nombre' => $payment->servicio_nombre, 'categoria' => $payment->categoria,
            'referencia_servicio' => $payment->referencia_servicio, 'cuenta_id' => $payment->cuenta_id, 'cuenta_numero' => $payment->cuenta_numero,
            'monto' => Money::format((int) $payment->monto_centavos), 'monto_centavos' => (int) $payment->monto_centavos, 'moneda' => 'GTQ',
            'estado' => $payment->estado, 'fecha' => $payment->created_at?->toIso8601String(),
        ];
    }
}
