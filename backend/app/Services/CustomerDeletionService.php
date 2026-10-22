<?php

namespace App\Services;

use App\Models\AccessToken;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Card;
use App\Models\CardApplication;
use App\Models\CardPurchase;
use App\Models\Collaborator;
use App\Models\Company;
use App\Models\Credit;
use App\Models\Customer;
use App\Models\CustomerSession;
use App\Models\DailyAccrual;
use App\Models\IdempotencyRecord;
use App\Models\Movement;
use App\Models\OperationAlert;
use App\Models\OutboxEvent;
use App\Models\Payroll;
use App\Models\RiskAssessment;
use App\Models\SecurityEvent;
use App\Models\ServicePayment;
use App\Models\Statement;
use App\Models\User;
use App\Models\UserSession;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * Physical, complete deletion of customers and companies. Always allowed (the panel only warns about
 * balances and debts). Nothing is marked as deleted: the records are removed, so the same DPI, e-mail or
 * NIT can be registered again. A remaining balance is returned to the master account first, so the vault
 * keeps the money and the internal ledger still adds up. Banca Virtual is told to delete its copy.
 * Callers hold the transaction.
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
        $active = Credit::where('cliente_id', $id)->get()->filter(fn (Credit $c): bool => (int) ($c->saldo_centavos ?? 0) > 0);
        $cards = Card::where('cliente_id', $id)->get();
        $balance = (int) $accounts->sum('saldo_centavos');
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

    public function delete(Customer $customer, string $actor): array
    {
        $summary = $this->summary($customer);
        $this->queueBankDeletion($customer);
        $this->purgeCustomer($customer, $actor, true);
        app(SecurityService::class)->record('cliente_eliminado', $actor, ['cuentas' => $summary['cuentas'], 'saldo_devuelto_centavos' => $summary['saldo_centavos'],
            'deuda_dada_de_baja_centavos' => $summary['deuda_prestamos_centavos'] + $summary['deuda_tarjetas_centavos']]);

        return ['eliminado' => true] + $summary;
    }

    /**
     * @return array{empresa: array, cuentas: int, saldo_centavos: int, colaboradores: int, planillas: int, usuarios: int}
     */
    public function companySummary(Company $company): array
    {
        $id = (string) $company->id;
        $accounts = Account::where('empresa_id', $id)->get();

        return ['empresa' => ['id' => $id, 'nombre' => $company->nombre, 'nit' => $company->nit], 'cuentas' => $accounts->count(), 'saldo_centavos' => (int) $accounts->sum('saldo_centavos'),
            'colaboradores' => Collaborator::where('empresa_id', $id)->count(), 'planillas' => Payroll::where('empresa_id', $id)->count(), 'usuarios' => User::where('role', 'empresa')->where('empresa_id', $id)->count()];
    }

    /**
     * Removes the company, its accounts (balance back to the vault) with their movements, its payrolls,
     * its collaborator links and its users. The employees are customers of their own and are not touched.
     */
    public function deleteCompany(Company $company, string $actor): array
    {
        $summary = $this->companySummary($company);
        $id = (string) $company->id;
        $this->purgeAccounts(Account::where('empresa_id', $id)->get()->all(), $actor, true);
        Payroll::where('empresa_id', $id)->delete();
        Collaborator::where('empresa_id', $id)->delete();
        $users = User::where('role', 'empresa')->where('empresa_id', $id)->get()->map(fn (User $u): string => (string) $u->id)->all();
        UserSession::whereIn('user_id', $users)->delete();
        AccessToken::whereIn('user_id', $users)->delete();
        User::whereIn('_id', $users)->delete();
        $this->forget('empresa_id', $id);
        OperationAlert::where('recurso', 'empresas')->where('recurso_id', $id)->delete();
        $company->delete();
        app(SecurityService::class)->record('empresa_eliminada', $actor, ['cuentas' => $summary['cuentas'], 'saldo_devuelto_centavos' => $summary['saldo_centavos']]);

        return ['eliminada' => true] + $summary;
    }

    /**
     * Everything that belongs only to this customer. With $returnBalance the money still in the accounts goes
     * back to the master account; a full reset does not need it (every balance is reset there).
     */
    public function purgeCustomer(Customer $customer, string $actor, bool $returnBalance): void
    {
        $id = (string) $customer->id;
        $this->purgeAccounts(Account::where('cliente_id', $id)->whereNull('system_key')->get()->all(), $actor, $returnBalance);
        $cards = Card::where('cliente_id', $id)->get()->map(fn (Card $c): string => (string) $c->id)->all();
        $applications = CardApplication::where('cliente_id', $id)->get()->map(fn (CardApplication $a): string => (string) $a->id)->all();
        $credits = Credit::where('cliente_id', $id)->get()->map(fn (Credit $c): string => (string) $c->id)->all();
        Statement::whereIn('tarjeta_id', $cards)->delete();
        DailyAccrual::whereIn('tarjeta_id', $cards)->delete();
        CardPurchase::whereIn('tarjeta_id', $cards)->delete();
        Movement::whereIn('tarjeta_id', $cards)->delete();
        AuditEvent::whereIn('solicitud_id', $applications)->delete();
        RiskAssessment::where('cliente_id', $id)->delete();
        ServicePayment::where('cliente_id', $id)->delete();
        Collaborator::where('cliente_id', $id)->delete();
        CustomerSession::where('cliente_id', $id)->delete();
        foreach (['clientes' => [$id], 'solicitudes' => $applications, 'tarjetas' => $cards, 'creditos' => $credits] as $resource => $ids) {
            OperationAlert::where('recurso', $resource)->whereIn('recurso_id', $ids)->delete();
        }
        OutboxEvent::whereIn('aggregate_id', array_merge($applications, $cards))->where('type', '!=', 'banca.cliente_eliminado')->delete();
        foreach (['tarjeta_id' => $cards, 'solicitud_id' => $applications, 'credito_id' => $credits] as $field => $ids) {
            SecurityEvent::whereIn('detail.'.$field, $ids)->delete();
        }
        $this->forget('cliente_id', $id);
        Card::whereIn('_id', $cards)->delete();
        Credit::whereIn('_id', $credits)->delete();
        CardApplication::whereIn('_id', $applications)->delete();
        $customer->delete();
    }

    /**
     * Deletes accounts with their movements. The master account keeps its own leg (the vault's history).
     *
     * @param  list<Account>  $accounts
     */
    private function purgeAccounts(array $accounts, string $actor, bool $returnBalance): void
    {
        $master = $returnBalance ? (string) $this->accounts->master()->id : null;
        $ids = [];
        foreach ($accounts as $account) {
            if ($master && (int) $account->saldo_centavos > 0) {
                $account->update(['estado' => 'activa']);
                $this->accounts->move((string) $account->id, $master, (int) $account->saldo_centavos, $actor, 'Cierre de cuenta '.$account->numero.' por eliminación', 'cierre_cuenta');
            }
            $ids[] = (string) $account->id;
        }
        if ($ids === []) {
            return;
        }
        Movement::whereIn('cuenta_id', $ids)->delete();
        OutboxEvent::whereIn('aggregate_id', $ids)->delete();
        SecurityEvent::whereIn('detail.cuenta_id', $ids)->delete();
        OperationAlert::where('recurso', 'cuentas')->whereIn('recurso_id', $ids)->delete();
        Account::whereIn('_id', $ids)->delete();
    }

    /**
     * Idempotency replays, events and log entries that point at the deleted customer or company.
     */
    private function forget(string $field, string $id): void
    {
        OutboxEvent::where('payload.'.$field, $id)->where('type', '!=', 'banca.cliente_eliminado')->delete();
        SecurityEvent::where('detail.'.$field, $id)->delete();
        IdempotencyRecord::where('result.'.$field, $id)->delete();
        IdempotencyRecord::where('result.id', $id)->delete();
        IdempotencyRecord::where('result.cliente.id', $id)->delete();
    }

    /**
     * Banca Virtual keeps its own copy of the customer (and their accounts there): it must delete it too, or
     * the old data would come back when the person registers again. Sent with the service key of abonos.
     */
    private function queueBankDeletion(Customer $customer): void
    {
        $config = app(IntegrationSettings::class)->get('banca_abonos');
        if (! $config['url'] || ! $config['secret'] || (! $customer->dpi && ! $customer->email)) {
            return;
        }
        OutboxEvent::create(['event_id' => (string) Str::uuid(), 'type' => 'banca.cliente_eliminado', 'aggregate_id' => (string) Str::uuid(), 'status' => 'pendiente', 'attempts' => 0,
            'payload' => array_filter(['dpi' => $customer->dpi, 'correo' => $customer->email ? mb_strtolower($customer->email) : null])]);
    }
}
