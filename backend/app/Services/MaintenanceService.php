<?php

namespace App\Services;

use App\Models\AccessToken;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankEvent;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MaintenanceService
{
    /**
     * Leftovers of customers and companies deleted before deletion was complete: movements of accounts that
     * no longer exist, cards, loans, gestiones, purchases and sessions without their customer. Records of
     * existing customers, companies and the bank's internal accounts are never touched. Idempotent.
     *
     * @return array<string, int>
     */
    public function purgeOrphans(): array
    {
        return DB::connection('mongodb')->transaction(function (): array {
            $customers = Customer::pluck('id')->map(fn ($id): string => (string) $id)->all();
            $companies = Company::pluck('id')->map(fn ($id): string => (string) $id)->all();
            $orphanAccounts = Account::whereNull('system_key')->get()->filter(fn (Account $a): bool => $a->empresa_id
                ? ! in_array((string) $a->empresa_id, $companies, true)
                : ! in_array((string) $a->cliente_id, $customers, true));
            foreach ($orphanAccounts as $account) {
                Movement::where('cuenta_id', (string) $account->id)->delete();
                $account->delete();
            }
            $accounts = Account::pluck('id')->map(fn ($id): string => (string) $id)->all();
            $cards = Card::whereNotIn('cliente_id', $customers)->pluck('id')->map(fn ($id): string => (string) $id)->all();
            $applications = CardApplication::whereNotIn('cliente_id', $customers)->pluck('id')->map(fn ($id): string => (string) $id)->all();
            $done = [
                'cuentas' => $orphanAccounts->count(),
                'movimientos' => Movement::whereNotNull('cuenta_id')->whereNotIn('cuenta_id', $accounts)->delete() + Movement::where('titular_eliminado', 'exists', true)->delete(),
                'tarjetas' => count($cards),
                'gestiones' => count($applications),
            ];
            Statement::whereIn('tarjeta_id', $cards)->delete();
            DailyAccrual::whereIn('tarjeta_id', $cards)->delete();
            Movement::whereIn('tarjeta_id', $cards)->delete();
            $done['compras'] = CardPurchase::whereNotIn('cliente_id', $customers)->delete();
            Card::whereIn('_id', $cards)->delete();
            AuditEvent::whereIn('solicitud_id', $applications)->delete();
            CardApplication::whereIn('_id', $applications)->delete();
            $done['prestamos'] = Credit::whereNotIn('cliente_id', $customers)->delete();
            CustomerSession::whereNotIn('cliente_id', $customers)->delete();
            ServicePayment::whereNotIn('cliente_id', $customers)->delete();
            RiskAssessment::whereNotNull('cliente_id')->whereNotIn('cliente_id', $customers)->delete();
            $done['colaboradores'] = Collaborator::where(fn ($q) => $q->whereNotNull('cliente_id')->whereNotIn('cliente_id', $customers))->orWhere(fn ($q) => $q->whereNotNull('empresa_id')->whereNotIn('empresa_id', $companies))->delete();
            Payroll::whereNotNull('empresa_id')->whereNotIn('empresa_id', $companies)->delete();
            $done['registros'] = SecurityEvent::whereNotNull('detail.cliente_id')->whereNotIn('detail.cliente_id', $customers)->delete()
                + SecurityEvent::where('action', 'cliente_eliminado')->whereNotNull('detail.nombre')->delete();
            OperationAlert::where('recurso', 'clientes')->whereNotIn('recurso_id', $customers)->delete();

            return $done;
        }, attempts: 5);
    }

    /**
     * Fresh start: every customer, company and their data (accounts, movements, cards, loans, gestiones,
     * payrolls, Banca Virtual sessions…) is deleted and the internal accounts go back to Q0.00; the master
     * account opens again with the bank's initial funds. Products, policies, integrations, staff users and
     * their access are kept. Banca Virtual is asked to delete each customer it keeps.
     *
     * @return array<string, int>
     */
    public function resetAll(string $actor): array
    {
        $counts = ['clientes' => Customer::count(), 'empresas' => Company::count(), 'cuentas' => Account::whereNull('system_key')->count(), 'movimientos' => Movement::count()];
        DB::connection('mongodb')->transaction(function () use ($actor): void {
            $settings = app(IntegrationSettings::class)->get('banca_abonos');
            $notify = $settings['url'] && $settings['secret'];
            $people = Customer::all()->map(fn (Customer $c): array => array_filter(['dpi' => $c->dpi, 'correo' => $c->email ? mb_strtolower($c->email) : null]))->filter()->values()->all();
            $companyUsers = User::where('role', 'empresa')->pluck('id')->map(fn ($id): string => (string) $id)->all();
            foreach ([Movement::class, Card::class, Statement::class, DailyAccrual::class, CardPurchase::class, Credit::class, CardApplication::class, AuditEvent::class,
                RiskAssessment::class, ServicePayment::class, Collaborator::class, Payroll::class, Company::class, CustomerSession::class, Customer::class,
                OperationAlert::class, OutboxEvent::class, IdempotencyRecord::class, BankEvent::class] as $model) {
                $model::query()->delete();
            }
            Account::whereNull('system_key')->delete();
            Account::whereNotNull('system_key')->update(['saldo_centavos' => 0]);
            UserSession::whereIn('user_id', $companyUsers)->delete();
            AccessToken::whereIn('user_id', $companyUsers)->delete();
            User::whereIn('_id', $companyUsers)->delete();
            // The staff's own activity stays; entries about customers, companies and their products go.
            SecurityEvent::where(function ($q): void {
                foreach (['cliente_id', 'empresa_id', 'cuenta_id', 'tarjeta_id', 'solicitud_id', 'credito_id', 'transaccion_id', 'compra_id', 'planilla_id', 'nombre', 'dpi'] as $field) {
                    $q->orWhereNotNull('detail.'.$field);
                }
            })->delete();
            SecurityEvent::whereIn('actor', $companyUsers)->delete();
            foreach ($notify ? $people : [] as $person) {
                OutboxEvent::create(['event_id' => (string) Str::uuid(), 'type' => 'banca.cliente_eliminado', 'aggregate_id' => (string) Str::uuid(), 'status' => 'pendiente', 'attempts' => 0, 'payload' => $person]);
            }
            app(SecurityService::class)->record('limpieza_total', $actor, []);
        }, attempts: 3);
        app(InstallService::class)->fundMasterAccount();

        return $counts;
    }
}
