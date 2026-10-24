<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CardApplication;
use App\Models\Collaborator;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Movement;
use App\Models\Payroll;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Exception\CommandException;

class InstallService
{
    public function indexes(): void
    {
        $db = DB::connection('mongodb')->getDatabase();
        foreach (['usuarios', 'tokens', 'clientes', 'productos', 'cuentas', 'movimientos', 'idempotencias', 'solicitudes', 'tarjetas', 'estados_cuenta', 'empresas', 'planillas', 'devengos', 'bitacora', 'seguridad', 'eventos_salida', 'sesiones_clientes', 'alertas', 'eventos_entrada', 'evaluaciones_riesgo', 'creditos', 'pagos_servicios', 'colaboradores', 'configuracion_integraciones', 'sesiones_usuarios', 'compras_tarjeta'] as $collection) {
            try {
                $db->createCollection($collection);
            } catch (CommandException $e) {
                if ($e->getCode() !== 48) {
                    throw $e;
                }
            }
        }
        $indexes = [
            ['sesiones_clientes', ['token_hash' => 1], ['unique' => true]],
            ['sesiones_clientes', ['expires_at' => 1], ['expireAfterSeconds' => 0]],
            ['alertas', ['clave' => 1], ['unique' => true]], ['alertas', ['permiso' => 1, '_id' => -1], []],
            ['eventos_entrada', ['evento_id' => 1], ['unique' => true]],
            ['evaluaciones_riesgo', ['evaluacion_ref' => 1], ['unique' => true]],
            ['solicitudes', ['gestion_ref' => 1], ['unique' => true, 'sparse' => true]],
            ['solicitudes', ['evaluacion_ref' => 1], ['unique' => true, 'sparse' => true]],
            ['creditos', ['solicitud_id' => 1], ['unique' => true]], ['creditos', ['cliente_id' => 1], []],
            ['usuarios', ['email' => 1], ['unique' => true]],
            ['tokens', ['token_hash' => 1], ['unique' => true]], ['tokens', ['expires_at' => 1], ['expireAfterSeconds' => 0]],
            ['clientes', ['dpi' => 1], ['unique' => true]], ['clientes', ['external_ref' => 1], ['unique' => true, 'partialFilterExpression' => ['external_ref' => ['$type' => 'string']]]],
            ['clientes', ['dpi_hash' => 1], ['unique' => true, 'partialFilterExpression' => ['dpi_hash' => ['$type' => 'string']]]],
            ['clientes', ['email' => 1], ['unique' => true, 'partialFilterExpression' => ['email' => ['$type' => 'string']]]],
            ['productos', ['code' => 1], ['unique' => true]], ['cuentas', ['numero' => 1], ['unique' => true]],
            ['cuentas', ['system_key' => 1], ['unique' => true, 'sparse' => true]], ['cuentas', ['cliente_id' => 1], []],
            ['idempotencias', ['actor' => 1, 'key' => 1], ['unique' => true]],
            ['solicitudes', ['folio' => 1], ['unique' => true]], ['solicitudes', ['active_key' => 1], ['unique' => true, 'sparse' => true]],
            ['solicitudes', ['estado' => 1, 'created_at' => -1], []], ['solicitudes', ['cliente_id' => 1], []],
            ['tarjetas', ['solicitud_id' => 1], ['unique' => true]], ['tarjetas', ['referencia' => 1], ['unique' => true]],
            ['estados_cuenta', ['tarjeta_id' => 1, 'periodo' => 1], ['unique' => true]],
            ['empresas', ['nit' => 1], ['unique' => true]], ['empresas', ['cuenta_id' => 1], ['unique' => true]],
            ['devengos', ['tarjeta_id' => 1, 'fecha' => 1], ['unique' => true, 'partialFilterExpression' => ['tarjeta_id' => ['$exists' => true]]]],
            ['devengos', ['cuenta_id' => 1, 'fecha' => 1], ['unique' => true, 'partialFilterExpression' => ['cuenta_id' => ['$exists' => true]]]],
            ['bitacora', ['solicitud_id' => 1, 'sequence' => 1], ['unique' => true]],
            ['movimientos', ['transaccion_id' => 1, 'cuenta_id' => 1], ['unique' => true]], ['movimientos', ['cuenta_id' => 1, 'created_at' => -1], []],
            ['eventos_salida', ['event_id' => 1], ['unique' => true]], ['eventos_salida', ['status' => 1], []],
            ['pagos_servicios', ['cliente_id' => 1, 'created_at' => -1], []], ['pagos_servicios', ['transaccion_id' => 1], ['unique' => true]],
            ['sesiones_clientes', ['cliente_id' => 1], []],
            ['colaboradores', ['empresa_id' => 1, 'puesto' => 1], []], ['configuracion_integraciones', ['sistema' => 1], ['unique' => true]],
            ['colaboradores', ['cliente_id' => 1], []], ['creditos', ['estado' => 1], []], ['estados_cuenta', ['fecha_pago' => 1], []],
            ['sesiones_usuarios', ['refresh_hash' => 1], ['unique' => true]], ['sesiones_usuarios', ['refresh_prev_hash' => 1], []],
            ['sesiones_usuarios', ['user_id' => 1], []], ['sesiones_usuarios', ['expires_at' => 1], ['expireAfterSeconds' => 0]],
            ['movimientos', ['created_at' => -1], []], ['usuarios', ['empresa_id' => 1], []],
        ];
        // external_ref used to be unique for every document; customers now may have no Banca reference.
        foreach ($db->selectCollection('clientes')->listIndexes() as $index) {
            if ($index->getName() === 'external_ref_1' && ! isset($index['partialFilterExpression'])) {
                $db->selectCollection('clientes')->dropIndex('external_ref_1');
            }
        }
        foreach ($indexes as [$collection,$keys,$options]) {
            $db->selectCollection($collection)->createIndex($keys, $options);
        }
    }

    public function catalog(): void
    {
        foreach ([
            ['ahorro', 'Cuenta de ahorro', 'ahorro', null, 200, 0], ['monetaria', 'Cuenta monetaria', 'monetaria', null, 0, 0],
            ['debito', 'Tarjeta de débito', 'debito', null, 0, 0],
            ['credito-clasica', 'Tarjeta de crédito Clásica', 'credito', 'clasica', 3600, 2500000],
            ['credito-oro', 'Tarjeta de crédito Oro', 'credito', 'oro', 3000, 7500000],
            ['credito-platino', 'Tarjeta de crédito Platino', 'credito', 'platino', 2400, 15000000],
        ] as [$code,$name,$type,$category,$annual,$max]) {
            Product::firstOrCreate(['code' => $code], ['name' => $name, 'type' => $type, 'category' => $category, 'annual_bps' => $annual, 'max_limit_cents' => $max, 'minimum_bps' => 500, 'minimum_floor_cents' => 10000, 'cutoff_day' => 20, 'payment_days' => 20, 'active' => true]);
        }
        foreach (['credito-personal' => 'Crédito Personal', 'credito-vehicular' => 'Crédito Vehicular', 'credito-hipotecario' => 'Préstamo Hipotecario'] as $code => $name) {
            Product::firstOrCreate(['code' => $code], ['name' => $name, 'type' => 'prestamo', 'active' => true, 'max_limit_cents' => null, 'annual_bps' => null]);
        }
        foreach (['caja' => 'Caja de efectivo', 'comercios' => 'Liquidación de consumos', 'pagos_tarjeta' => 'Liquidación de pagos', 'intereses' => 'Gasto de intereses', 'servicios' => 'Liquidación de pago de servicios', 'capital' => 'Capital del banco', 'efectivo' => 'Efectivo recibido de clientes y empresas', 'concentradora' => 'Cuenta Principal del Banco (concentradora)'] as $key => $name) {
            Account::firstOrCreate(['system_key' => $key], ['numero' => 'INTERNA-'.$key, 'nombre' => $name, 'tipo' => 'interna', 'moneda' => 'GTQ', 'saldo_centavos' => 0, 'estado' => 'activa']);
        }
    }

    /**
     * The master (concentradora) account opens once with the bank's own funds, recorded as a movement from
     * the capital account so every balance in the ledger still adds up to zero. Loans are disbursed from it.
     */
    public function fundMasterAccount(): void
    {
        $master = Account::where('system_key', 'concentradora')->firstOrFail();
        if (Movement::where('cuenta_id', (string) $master->id)->where('tipo', 'apertura_fondos')->exists()) {
            return;
        }
        DB::connection('mongodb')->transaction(function () use ($master): void {
            $capital = Account::where('system_key', 'capital')->firstOrFail();
            app(AccountService::class)->move((string) $capital->id, (string) $master->id, (int) config('operations.master_account_opening_cents'), 'sistema:instalacion', 'Fondos iniciales de la cuenta concentradora', 'apertura_fondos');
        }, attempts: 5);
    }

    /**
     * Before the master account became the single vault, cash deposits came from "caja", savings interest
     * from "intereses" and card payments stayed in "pagos_tarjeta". Their balances are settled against the
     * master account once, so it shows every quetzal the bank has injected or recovered. Idempotent.
     *
     * @return array<string, string>
     */
    public function reconcileMasterAccount(): array
    {
        $done = [];
        DB::connection('mongodb')->transaction(function () use (&$done): void {
            $master = Account::where('system_key', 'concentradora')->firstOrFail();
            foreach (['caja' => 'fondeos y retiros en ventanilla', 'intereses' => 'intereses de ahorro', 'pagos_tarjeta' => 'pagos de tarjetas'] as $key => $name) {
                $account = Account::where('system_key', $key)->first();
                $balance = (int) ($account?->saldo_centavos ?? 0);
                if (! $account || $balance === 0) {
                    continue;
                }
                [$from, $to] = $balance < 0 ? [$master, $account] : [$account, $master];
                app(AccountService::class)->move((string) $from->id, (string) $to->id, abs($balance), 'sistema:conciliacion', 'Conciliación con la cuenta concentradora: '.$name.' anteriores', 'conciliacion_concentradora');
                $done[$key] = Money::format(-$balance);
            }
        }, attempts: 5);

        return $done;
    }

    /**
     * Account closings recorded before the current rule returned the customer's balance to the master account
     * (+X). The rule is that the customer takes the money, so it leaves the vault (-X). Each such closing is
     * corrected once with a movement of 2X from the master account to "efectivo": the vault ends where it would
     * have been and the closing shows as an outflow. Idempotent (the original movement is marked).
     *
     * @return array{corregidos: int, monto_centavos: int}
     */
    public function correctOldAccountClosings(): array
    {
        $master = Account::where('system_key', 'concentradora')->first();
        if (! $master) {
            return ['corregidos' => 0, 'monto_centavos' => 0];
        }
        $done = ['corregidos' => 0, 'monto_centavos' => 0];
        $old = Movement::where('cuenta_id', (string) $master->id)->where('tipo', 'cierre_cuenta')->where('importe_centavos', '>', 0)->where('corregido', '!=', true)->get();
        foreach ($old as $movement) {
            DB::connection('mongodb')->transaction(function () use ($movement, $master, &$done): void {
                $fresh = Movement::findOrFail($movement->id);
                if ($fresh->corregido === true) {
                    return;
                }
                $amount = (int) $fresh->importe_centavos;
                $accounts = app(AccountService::class);
                $accounts->move((string) $master->id, (string) $accounts->cashOutAccount()->id, 2 * $amount, 'sistema:correccion', 'Corrección: el saldo de un cierre de cuenta sale de la concentradora ('.Money::format($amount).')', 'cierre_cuenta');
                $fresh->update(['corregido' => true]);
                $done['corregidos']++;
                $done['monto_centavos'] += $amount;
            }, attempts: 5);
        }

        return $done;
    }

    /**
     * Before v3 a company paid payroll from an account of a customer created only to hold it. That account
     * now belongs to the company, and such a holder (no app access, no personal accounts) leaves the
     * personal directory. Idempotent.
     */
    public function migrateCompanyAccounts(): int
    {
        $moved = 0;
        foreach (Company::all() as $company) {
            $account = $company->cuenta_id ? Account::find($company->cuenta_id) : null;
            if (! $account || $account->empresa_id === (string) $company->id) {
                continue;
            }
            $account->update(['empresa_id' => (string) $company->id]);
            $company->update(['cuenta_numero' => $account->numero, 'segmento' => 'empresarial']);
            $holder = $account->cliente_id ? Customer::find($account->cliente_id) : null;
            if ($holder && ! $holder->password && ! Account::where('cliente_id', (string) $holder->id)->whereNull('empresa_id')->exists()) {
                $holder->update(['segmento' => 'empresa', 'empresa_id' => (string) $company->id]);
            }
            $moved++;
        }

        return $moved;
    }

    /**
     * The original administrator (the first one created) is protected: nobody else can change its role,
     * deactivate it or reset its password. Idempotent.
     */
    public function protectOriginalAdministrator(): ?string
    {
        if (User::where('protegido', true)->exists()) {
            return null;
        }
        $admin = User::where('role', 'administrador')->where('active', true)->orderBy('created_at')->first();
        $admin?->update(['protegido' => true]);

        return $admin?->email;
    }

    /**
     * Earlier versions had seven roles; the project now defines three. Runs on every start and is idempotent.
     */
    public function migrateRoles(): int
    {
        $changed = 0;
        foreach (User::whereNotIn('role', array_keys(config('operations.roles')))->get() as $user) {
            $company = str_starts_with((string) $user->role, 'empresa') ? Company::where('miembros', (string) $user->id)->first() : null;
            $user->update($company ? ['role' => 'empresa', 'empresa_id' => (string) $company->id] : ['role' => 'operador']);
            app(AuthService::class)->closeUserSessions((string) $user->id);
            $changed++;
        }

        return $changed;
    }

    /**
     * Removes the fictitious corporate data the old "Cargar datos de prueba" button created (marked demo=true).
     * Money left in those accounts is first returned to the cash account so the ledger stays balanced.
     *
     * @return array{empresas: int, colaboradores: int, clientes: int, cuentas: int, usuarios: int}
     */
    public function purgeCorporateDemo(): array
    {
        $companies = Company::where('demo', true)->get()->map(fn ($c) => (string) $c->id)->all();
        $customers = Customer::where('demo', true)->get()->map(fn ($c) => (string) $c->id)->all();
        $users = User::where('demo', true)->get()->map(fn ($u) => (string) $u->id)->all();
        if ($companies === [] && $customers === [] && $users === [] && ! Collaborator::where('demo', true)->exists()) {
            return ['empresas' => 0, 'colaboradores' => 0, 'clientes' => 0, 'cuentas' => 0, 'usuarios' => 0];
        }

        return DB::connection('mongodb')->transaction(function () use ($companies, $customers, $users): array {
            $vault = Account::where('system_key', 'caja')->firstOrFail();
            $accounts = Account::whereIn('cliente_id', $customers)->get();
            foreach ($accounts as $account) {
                if ($account->saldo_centavos > 0) {
                    $account->update(['estado' => 'activa']);
                    app(AccountService::class)->move((string) $account->id, (string) $vault->id, (int) $account->saldo_centavos, 'sistema:limpieza', 'Reverso de datos de prueba corporativos', 'reverso_demo');
                }
            }
            $accountIds = $accounts->map(fn ($a) => (string) $a->id)->all();
            Movement::whereIn('cuenta_id', $accountIds)->delete();
            Account::whereIn('_id', $accountIds)->delete();
            Payroll::whereIn('empresa_id', $companies)->delete();
            $collaborators = Collaborator::where('demo', true)->orWhereIn('empresa_id', $companies)->delete();
            Company::whereIn('_id', $companies)->delete();
            foreach ($users as $id) {
                app(AuthService::class)->closeUserSessions($id);
            }
            User::whereIn('_id', $users)->delete();
            // A demo customer that somehow got a real product is kept rather than leaving orphans.
            $removable = array_values(array_filter($customers, fn (string $id): bool => ! CardApplication::where('cliente_id', $id)->exists()));
            Customer::whereIn('_id', $removable)->delete();

            return ['empresas' => count($companies), 'colaboradores' => (int) $collaborators, 'clientes' => count($removable), 'cuentas' => count($accountIds), 'usuarios' => count($users)];
        }, attempts: 5);
    }
}
