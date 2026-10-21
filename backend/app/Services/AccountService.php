<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Movement;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Repositories\AccountRepository;
use App\Repositories\CustomerRepository;
use App\Support\Money;
use Illuminate\Support\Str;

class AccountService
{
    /**
     * Internal accounts that stand for money entering the ledger from outside: cash received at the
     * counter, the bank's own interest expense and the bank's capital (which funds the master account).
     * Their negative balance is that inflow, so the sum of
     * every balance stays zero. Customer and settlement accounts can never be overdrawn.
     */
    public const COUNTERPARTY_ACCOUNTS = ['caja', 'intereses', 'capital'];

    public function __construct(private AccountRepository $repository) {}

    /**
     * Personal accounts belong to a customer (cliente_id); corporate accounts belong to a company (empresa_id).
     */
    public function open(array $d, string $actor): array
    {
        $owner = isset($d['empresa_id']) ? ['empresa_id' => (string) Company::findOrFail($d['empresa_id'])->id] : ['cliente_id' => (string) Customer::findOrFail($d['cliente_id'])->id];
        $product = Product::where('code', $d['producto'])->where('active', true)->first();
        if (! $product) {
            throw new BusinessException('El producto de cuenta no está disponible. Reintenta cuando el banco lo habilite.', 'PRODUCT_UNAVAILABLE', 409);
        }
        if (! in_array($product->type, ['ahorro', 'monetaria'], true)) {
            throw new BusinessException('Selecciona un producto de cuenta.', 'INVALID_PRODUCT', 422);
        }
        $account = $this->repository->create($owner + ['producto' => $product->code, 'tipo' => $product->type, 'numero' => $this->freeNumber(), 'moneda' => 'GTQ', 'saldo_centavos' => 0, 'estado' => 'activa', 'tasa_bps' => (int) $product->annual_bps, 'nombre' => $d['nombre'] ?? $product->name]);
        app(SecurityService::class)->record('cuenta_abierta', $actor, ['cuenta_id' => (string) $account->id]);

        return $account->toArray();
    }

    private function freeNumber(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $number = (string) random_int(1000000000, 9999999999);
            if (! Account::where('numero', $number)->exists()) {
                return $number;
            }
        }

        throw new BusinessException('No se pudo asignar un número de cuenta. Reintenta con la misma clave.', 'ACCOUNT_NUMBER_UNAVAILABLE', 503);
    }

    public function transfer(array $d, string $actor, string $kind = 'transferencia'): array
    {
        return $this->move($d['origen_id'], $d['destino_id'], Money::cents($d['monto']), $actor, $d['concepto'], $kind);
    }

    // Caller must hold a MongoDB transaction; reused by transfers, payroll and card payments.
    public function move(string $sourceId, string $targetId, int $cents, string $actor, string $concept, string $kind): array
    {
        if ($sourceId === $targetId) {
            throw new BusinessException('Selecciona dos cuentas diferentes.', 'SAME_ACCOUNT', 422);
        }
        $source = $this->repository->find($sourceId);
        $target = $this->repository->find($targetId);
        if ($source->estado !== 'activa' || $target->estado !== 'activa') {
            throw new BusinessException('Ambas cuentas deben estar activas.', 'ACCOUNT_BLOCKED');
        }
        if ($source->moneda !== $target->moneda) {
            throw new BusinessException('Las monedas de las cuentas no coinciden.', 'CURRENCY_MISMATCH');
        }
        if ($cents < 1 || $cents > Money::MAX) {
            throw new BusinessException('Importe inválido.', 'INVALID_AMOUNT', 422);
        }
        if (! $this->repository->debit($sourceId, $cents, in_array($source->system_key, self::COUNTERPARTY_ACCOUNTS, true))) {
            throw new BusinessException('La cuenta de origen no tiene fondos suficientes.', 'INSUFFICIENT_FUNDS');
        }
        if (! $this->repository->credit($targetId, $cents)) {
            throw new BusinessException('La cuenta de destino excedería su límite de saldo.', 'BALANCE_LIMIT');
        }
        $tx = (string) Str::uuid();
        foreach ([[$source, -$cents], [$target, $cents]] as [$account,$amount]) {
            $account->refresh();
            Movement::create(['transaccion_id' => $tx, 'cuenta_id' => (string) $account->id, 'importe_centavos' => $amount, 'saldo_centavos' => $account->saldo_centavos, 'moneda' => 'GTQ', 'concepto' => $concept, 'tipo' => $kind, 'actor' => $actor]);
            $this->notifyBank($account, $tx, $amount, $concept, $kind);
        }
        app(SecurityService::class)->record($kind, $actor, ['transaccion_id' => $tx]);

        return ['transaccion_id' => $tx, 'monto' => Money::format($cents), 'moneda' => 'GTQ', 'estado' => 'completada'];
    }

    /**
     * Banca Virtual learns at once that a customer's balance changed (deposits at the counter, payroll,
     * loan disbursements…). Only queued when its webhook is configured; the balance APIs stay the source of truth.
     */
    private function notifyBank(Account $account, string $tx, int $amount, string $concept, string $kind): void
    {
        if (! $account->cliente_id || app(IntegrationSettings::class)->get('banca')['url'] === null) {
            return;
        }
        OutboxEvent::create(['event_id' => (string) Str::uuid(), 'type' => 'cuenta.movimiento', 'aggregate_id' => (string) $account->id, 'status' => 'pendiente', 'attempts' => 0,
            'payload' => ['cliente_id' => $account->cliente_id, 'cuenta_id' => (string) $account->id, 'numero' => $account->numero, 'transaccion_id' => $tx, 'tipo' => $kind, 'concepto' => $concept,
                'importe_centavos' => $amount, 'saldo_centavos' => (int) $account->saldo_centavos, 'saldo' => Money::format((int) $account->saldo_centavos)]]);
    }

    /**
     * The bank's master account: the single vault every outflow of the bank's own money comes from
     * (funding, loans, credit card purchases, savings interest) and every return goes back to.
     */
    public function master(): Account
    {
        return Account::where('system_key', 'concentradora')->firstOrFail();
    }

    public function ownership(array $d, string $actor): void
    {
        if ($actor === 'service:banca') {
            $customer = app(CustomerRepository::class)->resolve($d);
            $account = Account::findOrFail($d['origen_id']);
            if ($account->cliente_id !== (string) $customer->id) {
                throw new BusinessException('La cuenta no pertenece al cliente autenticado.', 'FORBIDDEN', 403);
            }
        }
    }
}
