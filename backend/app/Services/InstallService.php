<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Movement;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use MongoDB\Driver\Exception\CommandException;

class InstallService
{
    public function indexes(): void
    {
        $db = DB::connection('mongodb')->getDatabase();
        foreach (['usuarios', 'tokens', 'clientes', 'productos', 'cuentas', 'movimientos', 'idempotencias', 'solicitudes', 'tarjetas', 'estados_cuenta', 'empresas', 'planillas', 'devengos', 'bitacora', 'seguridad', 'eventos_salida', 'sesiones_clientes', 'alertas', 'eventos_entrada', 'evaluaciones_riesgo', 'creditos', 'pagos_servicios'] as $collection) {
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
            ['clientes', ['dpi' => 1], ['unique' => true]], ['clientes', ['external_ref' => 1], ['unique' => true]],
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
        ];
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
        foreach (['caja' => 'Caja de efectivo', 'comercios' => 'Liquidación de consumos', 'pagos_tarjeta' => 'Liquidación de pagos', 'intereses' => 'Gasto de intereses', 'servicios' => 'Liquidación de pago de servicios'] as $key => $name) {
            Account::firstOrCreate(['system_key' => $key], ['numero' => 'INTERNA-'.$key, 'nombre' => $name, 'tipo' => 'interna', 'moneda' => 'GTQ', 'saldo_centavos' => 0, 'estado' => 'activa']);
        }
    }

    public function demo(string $password): void
    {
        if (User::where('email', 'admin@cp.local')->exists()) {
            throw new \RuntimeException('La demostración ya existe. No se sobrescribieron datos.');
        }
        DB::transaction(function () use ($password) {
            foreach (['admin' => 'administrador', 'gerente' => 'gerente', 'cajero' => 'cajero', 'auditor' => 'auditor', 'operador' => 'empresa_operador', 'aprobador' => 'empresa_aprobador'] as $name => $role) {
                User::create(['name' => ucfirst($name).' de demostración', 'email' => $name.'@cp.local', 'password' => Hash::make($password), 'role' => $role, 'active' => true, 'failed_attempts' => 0]);
            }
            $names = ['Ana Lucía Méndez', 'Carlos Estuardo López', 'María Fernanda Ruiz', 'Diego Alejandro Soto', 'Sofía Isabel Pérez', 'Comercial Los Pinos'];
            $accounts = [];
            $vault = Account::where('system_key', 'caja')->firstOrFail();
            $vault->update(['saldo_centavos' => 100000000]);
            Movement::create(['transaccion_id' => 'apertura-demo', 'cuenta_id' => (string) $vault->id, 'importe_centavos' => 100000000, 'saldo_centavos' => 100000000, 'tipo' => 'apertura_demo', 'concepto' => 'Fondos ficticios de la demostración']);
            foreach ($names as $i => $name) {
                $c = Customer::create(['nombre' => $name, 'email' => 'cliente'.($i + 1).'@example.test', 'dpi' => '99999999'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'nit' => 'DEMO-'.($i + 1), 'telefono' => '5555000'.$i, 'external_ref' => 'demo-'.($i + 1), 'ingreso_centavos' => 1200000 + $i * 100000, 'deuda_centavos' => 100000]);
                $a = app(AccountService::class)->open(['cliente_id' => (string) $c->id, 'producto' => $i === 5 ? 'monetaria' : 'ahorro'], 'seed:demo');
                $id = (string) ($a['id'] ?? $a['_id']);
                $accounts[] = $id;
                app(AccountService::class)->move((string) $vault->id, $id, ($i + 1) * 250000, 'seed:demo', 'Depósito ficticio inicial', 'deposito_demo');
                if ($i < 4) {
                    app(ApplicationService::class)->create(['cliente_id' => (string) $c->id, 'producto' => ['credito-clasica', 'credito-oro', 'credito-clasica', 'credito-platino'][$i], 'limite_solicitado' => ['12000.00', '25000.00', '8500.00', '50000.00'][$i]], 'seed:demo');
                }
            }
            $users = User::whereIn('role', ['empresa_operador', 'empresa_aprobador'])->get()->map(fn ($u) => (string) $u->id)->all();
            $company = Company::create(['nombre' => 'Comercial Los Pinos', 'nit' => 'DEMO-EMPRESA', 'cuenta_id' => $accounts[5], 'miembros' => $users]);
            $op = User::where('role', 'empresa_operador')->first();
            app(PayrollService::class)->create(['empresa_id' => (string) $company->id, 'nombre' => 'Planilla de ejemplo', 'pagos' => [['cuenta_id' => $accounts[0], 'nombre' => $names[0], 'monto' => '1500.00'], ['cuenta_id' => $accounts[1], 'nombre' => $names[1], 'monto' => '2000.00']]], (string) $op->id);
        });
    }
}
