<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Card;
use App\Models\CardApplication;
use App\Models\CardPurchase;
use App\Models\Collaborator;
use App\Models\Credit;
use App\Models\Customer;
use App\Models\CustomerSession;
use App\Models\DailyAccrual;
use App\Models\Movement;
use App\Models\Statement;
use App\Support\Money;

/**
 * Permanent deletion of a customer. It is always allowed, even with money in the accounts or debts: the
 * staff sees a warning first. Balances return to the master account (so the ledger still adds up), debts are
 * written off, and the customer with their accounts, cards, loans and gestiones is removed. Movements and
 * the security log are kept for audit, with the account number and holder name copied into each movement.
 */
class CustomerDeletionService
{
    public function __construct(private AccountService $accounts) {}

    /**
     * @return array{cliente: array, cuentas: int, saldo_centavos: int, deuda_prestamos_centavos: int, deuda_tarjetas_centavos: int, prestamos_activos: int, tarjetas: int, tiene_saldo_o_deuda: bool}
     */
    public function summary(Customer $customer): array
    {
        $id = (string) $customer->id;
        $accounts = Account::where('cliente_id', $id)->whereNull('system_key')->get();
        $credits = Credit::where('cliente_id', $id)->get();
        $cards = Card::where('cliente_id', $id)->get();
        $balance = (int) $accounts->sum('saldo_centavos');
        $active = $credits->filter(fn (Credit $c): bool => (int) ($c->saldo_centavos ?? 0) > 0);
        $loanDebt = (int) $active->sum(fn (Credit $c): int => (int) $c->saldo_centavos);
        $cardDebt = (int) $cards->sum(fn (Card $c): int => (int) $c->principal_centavos + (int) $c->interes_centavos);

        return [
            'cliente' => ['id' => $id, 'nombre' => $customer->nombre, 'dpi_enmascarado' => $customer->dpi_enmascarado],
            'cuentas' => $accounts->count(), 'saldo_centavos' => $balance, 'saldo' => Money::format($balance),
            'deuda_prestamos_centavos' => $loanDebt, 'deuda_tarjetas_centavos' => $cardDebt, 'deuda_total' => Money::format($loanDebt + $cardDebt),
            'prestamos_activos' => $active->count(), 'tarjetas' => $cards->count(),
            'tiene_saldo_o_deuda' => $balance > 0 || $loanDebt > 0 || $cardDebt > 0,
        ];
    }

    /**
     * Caller holds the transaction (IdempotencyService).
     */
    public function delete(Customer $customer, string $actor, ?string $note = null): array
    {
        $summary = $this->summary($customer);
        $id = (string) $customer->id;
        $master = (string) $this->accounts->master()->id;
        $accounts = Account::where('cliente_id', $id)->whereNull('system_key')->get();
        foreach ($accounts as $account) {
            if ((int) $account->saldo_centavos > 0) {
                $account->update(['estado' => 'activa']);
                $this->accounts->move((string) $account->id, $master, (int) $account->saldo_centavos, $actor, 'Cierre de cuenta '.$account->numero.' por eliminación del cliente', 'cierre_cuenta');
            }
            Movement::where('cuenta_id', (string) $account->id)->update(['cuenta_numero' => $account->numero, 'titular_eliminado' => $customer->nombre]);
        }
        $cards = Card::where('cliente_id', $id)->get()->map(fn (Card $c): string => (string) $c->id)->all();
        Statement::whereIn('tarjeta_id', $cards)->delete();
        DailyAccrual::whereIn('tarjeta_id', $cards)->delete();
        CardPurchase::whereIn('tarjeta_id', $cards)->update(['cliente_eliminado' => true]);
        Card::where('cliente_id', $id)->delete();
        Credit::where('cliente_id', $id)->delete();
        CardApplication::where('cliente_id', $id)->delete();
        Collaborator::where('cliente_id', $id)->delete();
        CustomerSession::where('cliente_id', $id)->delete();
        Account::whereIn('_id', $accounts->map(fn (Account $a): string => (string) $a->id)->all())->delete();
        $customer->delete();
        app(SecurityService::class)->record('cliente_eliminado', $actor, ['cliente_id' => $id, 'nombre' => $summary['cliente']['nombre'], 'dpi' => $summary['cliente']['dpi_enmascarado'],
            'saldo_devuelto_centavos' => $summary['saldo_centavos'], 'deuda_prestamos_centavos' => $summary['deuda_prestamos_centavos'], 'deuda_tarjetas_centavos' => $summary['deuda_tarjetas_centavos'], 'nota' => $note]);

        return ['eliminado' => true] + $summary;
    }
}
