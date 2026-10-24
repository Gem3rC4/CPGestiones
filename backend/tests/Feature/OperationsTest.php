<?php

namespace Tests\Feature;

use App\Exceptions\BusinessException;
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
use App\Models\IntegrationSetting;
use App\Models\Movement;
use App\Models\OutboxEvent;
use App\Models\Payroll;
use App\Models\Product;
use App\Models\SecurityEvent;
use App\Models\Statement;
use App\Models\User;
use App\Services\AccountService;
use App\Services\ApplicationService;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\CardService;
use App\Services\CollectionService;
use App\Services\IdempotencyService;
use App\Services\InstallService;
use App\Services\MaintenanceService;
use App\Services\OutboxService;
use App\Services\SavingsService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OperationsTest extends TestCase
{
    private string $token;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $database = 'cp_gestiones_'.getmypid().'_test';
        config(['database.connections.mongodb.database' => $database, 'cache.default' => 'array']);
        DB::purge('mongodb');
        if (! str_starts_with($database, 'cp_gestiones_') || ! str_ends_with($database, '_test')) {
            throw new \LogicException('Unsafe test database');
        }
        DB::connection('mongodb')->getDatabase()->drop();
        app(InstallService::class)->indexes();
        app(InstallService::class)->catalog();
        $this->admin = $this->user('administrador', 'admin');
        $this->token = $this->token($this->admin);
    }

    private function user(string $role, string $name): User
    {
        return User::create(['name' => $name, 'email' => $name.'@example.test', 'password' => Hash::make('TestingPass123!'), 'active' => true, 'role' => $role, 'failed_attempts' => 0]);
    }

    private function token(User $user): string
    {
        $raw = bin2hex(random_bytes(32));
        AccessToken::create(['kind' => 'user', 'user_id' => (string) $user->id, 'token_hash' => hash('sha256', $raw), 'expires_at' => now()->addHour()]);

        return $raw;
    }

    private function headers(?string $token = null, ?string $key = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->token), 'Idempotency-Key' => $key ?? 'test-'.bin2hex(random_bytes(10))];
    }

    private function customer(string $ref = 'one'): Customer
    {
        return Customer::create(['nombre' => 'Cliente '.$ref, 'dpi' => substr(hash('sha256', $ref), 0, 13), 'external_ref' => $ref, 'ingreso_centavos' => 500000, 'deuda_centavos' => 0]);
    }

    private function account(Customer $c, int $balance = 10000): Account
    {
        return Account::create(['cliente_id' => (string) $c->id, 'numero' => 'CP'.bin2hex(random_bytes(6)), 'tipo' => 'monetaria', 'estado' => 'activa', 'moneda' => 'GTQ', 'saldo_centavos' => $balance, 'tasa_bps' => 0]);
    }

    private function application(Customer $c): array
    {
        return app(IdempotencyService::class)->run((string) $this->admin->id, 'create-'.bin2hex(random_bytes(8)), 'application', [], fn () => app(ApplicationService::class)->create(['cliente_id' => (string) $c->id, 'producto' => 'credito-clasica', 'limite_solicitado' => '1000.00'], (string) $this->admin->id));
    }

    public function test_api_authentication_validation_and_role_boundaries(): void
    {
        $this->getJson('/api/v1/cuentas')->assertUnauthorized();
        $this->postJson('/api/v1/clientes', [], $this->headers())->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
        $cashier = $this->user('operador', 'cashier');
        $this->postJson('/api/v1/usuarios', [], $this->headers($this->token($cashier)))->assertForbidden();
        $this->getJson('/api/v1/usuarios', $this->headers())->assertOk()->assertJsonMissingPath('data.data.0.password');
    }

    public function test_failed_logins_lock_and_successful_sessions_can_be_revoked(): void
    {
        for ($i = 0; $i < 5; $i++) {
            try {
                app(AuthService::class)->login($this->admin->email, 'bad');
                self::fail('Expected login failure');
            } catch (BusinessException $e) {
                self::assertSame(401, $e->status);
            }
        }
        self::assertTrue($this->admin->refresh()->locked_until->isFuture());
        try {
            app(AuthService::class)->login($this->admin->email, 'TestingPass123!');
            self::fail('Locked user logged in');
        } catch (BusinessException $e) {
            self::assertSame(401, $e->status);
        }
        $this->admin->update(['locked_until' => now()->subSecond()]);
        $this->postJson('/api/v1/auth/login', ['email' => $this->admin->email, 'password' => 'bad'])->assertUnauthorized();
        self::assertNull($this->admin->refresh()->locked_until, 'One typo after an expired lock must not re-lock the user.');
        self::assertSame(1, $this->admin->failed_attempts);
        $login = $this->postJson('/api/v1/auth/login', ['email' => $this->admin->email, 'password' => 'TestingPass123!'])->assertOk();
        $token = $login->json('data.token');
        $this->postJson('/api/v1/auth/logout', [], $this->headers($token))->assertOk();
        $this->getJson('/api/v1/auth/me', $this->headers($token))->assertUnauthorized();
    }

    public function test_admin_issues_lists_and_revokes_integration_tokens_without_storing_them(): void
    {
        $cashier = $this->user('operador', 'cashier');
        $this->postJson('/api/v1/integraciones/tokens', ['cliente' => 'banca', 'dias' => 90], $this->headers($this->token($cashier)))->assertForbidden();
        $this->postJson('/api/v1/integraciones/tokens', ['cliente' => 'otro', 'dias' => 7], $this->headers())->assertUnprocessable();
        $issued = $this->postJson('/api/v1/integraciones/tokens', ['cliente' => 'banca', 'dias' => 90], $this->headers())->assertCreated()->json('data');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $issued['token']);
        self::assertSame(0, IdempotencyRecord::count());
        self::assertNull(AccessToken::where('token_hash', $issued['token'])->first());
        $this->getJson('/api/v1/productos', $this->headers($issued['token']))->assertOk();
        $this->getJson('/api/v1/usuarios', $this->headers($issued['token']))->assertForbidden();
        $list = $this->getJson('/api/v1/integraciones/tokens', $this->headers())->assertOk()->json('data');
        self::assertSame([['id' => $issued['id'], 'client' => 'banca', 'hint' => substr($issued['token'], -4)]], array_map(fn ($t) => array_intersect_key($t, array_flip(['id', 'client', 'hint'])), $list));
        self::assertStringNotContainsString($issued['token'], json_encode($list));
        $this->postJson('/api/v1/integraciones/tokens/'.$issued['id'].'/revocar', [], $this->headers())->assertOk();
        $this->getJson('/api/v1/productos', $this->headers($issued['token']))->assertUnauthorized();
        self::assertSame(['token_integracion_emitido', 'token_integracion_revocado'], SecurityEvent::whereIn('action', ['token_integracion_emitido', 'token_integracion_revocado'])->orderBy('created_at')->pluck('action')->all());
    }

    public function test_transfer_is_balanced_idempotent_and_rejects_reused_key(): void
    {
        $a = $this->account($this->customer('source'));
        $b = $this->account($this->customer('target'), 0);
        $body = ['origen_id' => (string) $a->id, 'destino_id' => (string) $b->id, 'monto' => '25.10', 'concepto' => 'Transferencia de prueba'];
        $headers = $this->headers(key: 'same-transfer-key');
        $first = $this->postJson('/api/v1/transferencias', $body, $headers)->assertCreated()->json('data');
        $second = $this->postJson('/api/v1/transferencias', $body, $headers)->assertCreated()->json('data');
        self::assertSame($first, $second);
        self::assertSame(7490, $a->refresh()->saldo_centavos);
        self::assertSame(2510, $b->refresh()->saldo_centavos);
        self::assertSame(2, Movement::count());
        self::assertSame(0, (int) Movement::sum('importe_centavos'));
        $body['monto'] = '10.00';
        $this->postJson('/api/v1/transferencias', $body, $headers)->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_MISMATCH');
    }

    public function test_destination_failure_rolls_back_an_already_applied_debit(): void
    {
        $a = $this->account($this->customer('source'));
        $b = $this->account($this->customer('target'), Money::MAX);
        $body = ['origen_id' => (string) $a->id, 'destino_id' => (string) $b->id, 'monto' => '1.00', 'concepto' => 'Rollback test'];
        $this->postJson('/api/v1/transferencias', $body, $this->headers())->assertConflict()->assertJsonPath('code', 'BALANCE_LIMIT');
        self::assertSame(10000, $a->refresh()->saldo_centavos);
        self::assertSame(Money::MAX, $b->refresh()->saldo_centavos);
        self::assertSame(0, Movement::count());
        self::assertSame(0, IdempotencyRecord::count());
    }

    public function test_insufficient_funds_do_not_write_movements(): void
    {
        $a = $this->account($this->customer('source'), 1);
        $b = $this->account($this->customer('target'), 0);
        $this->postJson('/api/v1/transferencias', ['origen_id' => (string) $a->id, 'destino_id' => (string) $b->id, 'monto' => '0.02', 'concepto' => 'Fondos insuficientes'], $this->headers())->assertConflict()->assertJsonPath('code', 'INSUFFICIENT_FUNDS');
        self::assertSame(1, $a->refresh()->saldo_centavos);
        self::assertSame(0, $b->refresh()->saldo_centavos);
        self::assertSame(0, Movement::count());
    }

    public function test_bank_can_transfer_to_a_third_party_by_account_number(): void
    {
        $own = $this->account($this->customer('a'));
        $other = Account::create(['cliente_id' => (string) $this->customer('b')->id, 'numero' => '2738729879', 'tipo' => 'monetaria', 'estado' => 'activa', 'moneda' => 'GTQ', 'saldo_centavos' => 0, 'tasa_bps' => 0]);
        $token = app(AuthService::class)->issueService('banca');
        $body = ['origen_id' => (string) $own->id, 'destino_numero' => '2738729879', 'monto' => '15.00', 'concepto' => 'Pago a tercero', 'cliente_ref' => 'a'];
        $this->postJson('/api/v1/transferencias', $body, $this->headers($token))->assertCreated();
        self::assertSame(8500, $own->refresh()->saldo_centavos);
        self::assertSame(1500, $other->refresh()->saldo_centavos);
        $this->postJson('/api/v1/transferencias', ['destino_numero' => '1111111111'] + $body, $this->headers($token))->assertNotFound()->assertJsonPath('code', 'ACCOUNT_NOT_FOUND');
        $this->postJson('/api/v1/transferencias', ['destino_numero' => '2-738-729879'] + $body, $this->headers($token))->assertUnprocessable();
        $this->postJson('/api/v1/transferencias', ['destino_id' => (string) $other->id] + $body, $this->headers($token))->assertUnprocessable();
        self::assertSame(2, Movement::count());
    }

    public function test_bank_token_cannot_read_or_debit_another_customer(): void
    {
        $a = $this->account($this->customer('a'));
        $b = $this->account($this->customer('b'));
        $token = app(AuthService::class)->issueService('banca');
        $this->getJson('/api/v1/cuentas', $this->headers($token))->assertUnprocessable();
        $this->getJson('/api/v1/cuentas?cliente_ref=a', $this->headers($token))->assertOk()->assertJsonCount(1, 'data.data');
        $this->getJson('/api/v1/cuentas/'.$b->id.'/movimientos?cliente_ref=a', $this->headers($token))->assertForbidden();
        $this->postJson('/api/v1/transferencias', ['origen_id' => (string) $b->id, 'destino_id' => (string) $a->id, 'monto' => '1.00', 'concepto' => 'Incorrecto', 'cliente_ref' => 'a'], $this->headers($token))->assertForbidden();
    }

    public function test_risk_service_and_valid_transitions_are_required_for_issuance(): void
    {
        $customer = $this->customer();
        $account = $this->account($customer, 0);
        $app = $this->application($customer);
        $id = $app['id'];
        $decision = ['resultado' => 'aprobada', 'version' => 1, 'limite_aprobado' => '800.00', 'referencia' => 'risk-1', 'motivo' => 'Resultado emitido por el motor'];
        $this->postJson('/api/v1/solicitudes/'.$id.'/decision-riesgo', $decision, $this->headers())->assertForbidden();
        $risk = app(AuthService::class)->issueService('riesgos');
        $this->postJson('/api/v1/solicitudes/'.$id.'/decision-riesgo', $decision, $this->headers($risk))->assertOk();
        $this->postJson('/api/v1/solicitudes/'.$id.'/transiciones', ['version' => 2, 'estado' => 'emitida', 'nota' => 'Intento de saltar etapas'], $this->headers())->assertConflict();
        $this->postJson('/api/v1/solicitudes/'.$id.'/transiciones', ['version' => 2, 'estado' => 'aceptada', 'nota' => 'Aceptación registrada', 'referencia_aceptacion' => 'consentimiento-001'], $this->headers())->assertOk();
        $this->postJson('/api/v1/solicitudes/'.$id.'/transiciones', ['version' => 3, 'estado' => 'en_tramite', 'nota' => 'Preparación de la tarjeta'], $this->headers())->assertOk();
        $header = $this->headers(key: 'emit-only-once');
        $body = ['version' => 4, 'estado' => 'emitida', 'limite_asignado' => '750.00', 'nota' => 'Lista para entrega en agencia', 'dia_corte' => 12, 'dia_pago' => 2, 'cuenta_id' => (string) $account->id];
        $this->postJson('/api/v1/solicitudes/'.$id.'/transiciones', ['dia_pago' => 12] + $body, $this->headers())->assertUnprocessable()->assertJsonPath('code', 'INVALID_PAYMENT_DAY');
        $this->postJson('/api/v1/solicitudes/'.$id.'/transiciones', ['dia_corte' => 29] + $body, $this->headers())->assertUnprocessable()->assertJsonValidationErrors('dia_corte');
        $this->postJson('/api/v1/solicitudes/'.$id.'/transiciones', $body, $header)->assertOk();
        $this->postJson('/api/v1/solicitudes/'.$id.'/transiciones', $body, $header)->assertOk();
        self::assertSame(1, Card::count());
        self::assertSame(75000, Card::first()->limite_centavos);
        self::assertSame((string) $account->id, Card::first()->cuenta_id);
        self::assertTrue(app(AuditService::class)->verify(CardApplication::findOrFail($id)));
        self::assertSame(5, AuditEvent::count());
    }

    public function test_audit_detects_direct_database_tampering(): void
    {
        $app = $this->application($this->customer());
        $id = $app['id'];
        self::assertTrue(app(AuditService::class)->verify(CardApplication::findOrFail($id)));
        DB::connection('mongodb')->getCollection('bitacora')->updateOne(['solicitud_id' => $id], ['$set' => ['note' => 'altered']]);
        self::assertFalse(app(AuditService::class)->verify(CardApplication::findOrFail($id)));
    }

    public function test_company_representative_loads_and_approves_payroll_paid_atomically(): void
    {
        app(InstallService::class)->fundMasterAccount();
        $source = $this->account($this->customer('company'), 10000);
        $company = Company::create(['nombre' => 'Empresa Test', 'nit' => 'EMP-1', 'cuenta_id' => (string) $source->id, 'miembros' => []]);
        $representative = $this->user('empresa', 'representative');
        $representative->update(['empresa_id' => (string) $company->id]);
        $collaborators = [];
        foreach (['employee1', 'employee2'] as $name) {
            $person = $this->customer($name);
            $account = $this->account($person, 0);
            $collaborators[] = $this->postJson('/api/v1/colaboradores', ['empresa_id' => (string) $company->id, 'cliente_id' => (string) $person->id, 'cuenta_id' => (string) $account->id, 'puesto' => 'Piloto', 'salario' => '60.00'], $this->headers())
                ->assertCreated()->assertJsonPath('data.nombre', 'Cliente '.$name)->json('data');
        }
        $this->postJson('/api/v1/colaboradores', ['empresa_id' => (string) $company->id, 'cliente_id' => $collaborators[0]['cliente_id'], 'cuenta_id' => $collaborators[0]['cuenta_id'], 'puesto' => 'Piloto', 'salario' => '60.00'], $this->headers())->assertConflict()->assertJsonPath('code', 'DUPLICATE_COLLABORATOR');
        $this->postJson('/api/v1/colaboradores', ['empresa_id' => (string) $company->id, 'cliente_id' => $collaborators[0]['cliente_id'], 'cuenta_id' => $collaborators[1]['cuenta_id'], 'puesto' => 'Piloto', 'salario' => '60.00'], $this->headers())->assertUnprocessable()->assertJsonPath('code', 'INVALID_ACCOUNT');
        $rep = $this->token($representative);
        $this->postJson('/api/v1/colaboradores', ['empresa_id' => (string) $company->id, 'cuenta_id' => (string) $source->id, 'puesto' => 'X', 'salario' => '1.00'], $this->headers($rep))->assertForbidden();
        $body = ['empresa_id' => (string) $company->id, 'nombre' => 'Planilla Test', 'pagos' => array_map(fn (array $c): array => ['nombre' => $c['nombre'], 'colaborador_id' => $c['id'], 'monto' => '60.00'], $collaborators)];
        $id = $this->postJson('/api/v1/planillas', $body, $this->headers($rep))->assertCreated()->assertJsonPath('data.pagos.0.puesto', 'Piloto')->json('data.id');
        $this->postJson('/api/v1/planillas/'.$id.'/autorizar', [], $this->headers($rep))->assertConflict()->assertJsonPath('code', 'INSUFFICIENT_FUNDS');
        self::assertSame(10000, $source->refresh()->saldo_centavos);
        self::assertSame(0, Movement::where('tipo', '!=', 'apertura_fondos')->count(), 'Nothing moved: the payroll is all or nothing.');
        self::assertSame('pendiente_autorizacion', Payroll::findOrFail($id)->estado);
        $source->update(['saldo_centavos' => 20000]);
        $this->postJson('/api/v1/planillas/'.$id.'/autorizar', [], $this->headers($rep, 'approve-payroll-1'))->assertOk()->assertJsonPath('data.estado', 'pagada');
        $this->postJson('/api/v1/planillas/'.$id.'/autorizar', [], $this->headers($rep, 'approve-payroll-1'))->assertOk();
        $this->postJson('/api/v1/planillas/'.$id.'/autorizar', [], $this->headers($rep))->assertConflict()->assertJsonPath('code', 'INVALID_STATE');
        self::assertSame(8000, $source->refresh()->saldo_centavos);
        foreach ($collaborators as $collaborator) {
            self::assertSame(6000, Account::findOrFail($collaborator['cuenta_id'])->saldo_centavos);
        }
        self::assertSame(6, Movement::where('tipo', 'planilla')->count(), 'Two payments (two legs each) and the release of the total from the vault.');
        self::assertNotEmpty(Payroll::findOrFail($id)->pagos[0]['transaccion_id']);
    }

    public function test_corporate_users_cannot_read_other_companies(): void
    {
        $company = Company::create(['nombre' => 'Empresa Test', 'nit' => 'EMP-1', 'cuenta_id' => (string) $this->account($this->customer())->id, 'miembros' => []]);
        $other = Company::create(['nombre' => 'Otra Empresa', 'nit' => 'EMP-2', 'cuenta_id' => (string) $this->account($this->customer('other'))->id, 'miembros' => []]);
        $outsider = $this->user('empresa', 'outsider');
        $outsider->update(['empresa_id' => (string) $other->id]);
        $token = $this->token($outsider);
        $this->getJson('/api/v1/empresas', $this->headers($token))->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.nombre', 'Otra Empresa');
        $this->postJson('/api/v1/planillas', ['empresa_id' => (string) $company->id, 'nombre' => 'Intrusión', 'pagos' => [['cuenta_numero' => 'CP000', 'nombre' => 'Persona', 'monto' => '1.00']]], $this->headers($token))->assertForbidden();
        $this->getJson('/api/v1/reportes/planillas?empresa_id='.$company->id, $this->headers($token))->assertForbidden();
        $this->getJson('/api/v1/reportes/planillas?empresa_id='.$other->id, $this->headers($token))->assertOk()->assertJsonPath('data.empresa.nombre', 'Otra Empresa');
        foreach (['/api/v1/movimientos', '/api/v1/clientes', '/api/v1/cuentas', '/api/v1/solicitudes', '/api/v1/usuarios', '/api/v1/reportes/transacciones'] as $path) {
            $this->getJson($path, $this->headers($token))->assertForbidden();
        }
    }

    public function test_credit_interest_cutoff_and_payment_are_exact_and_idempotent(): void
    {
        $today = now()->timezone('America/Guatemala');
        $c = $this->customer();
        $account = $this->account($c, 200000);
        $card = Card::create(['cliente_id' => (string) $c->id, 'solicitud_id' => 'test-card', 'referencia' => 'CPTEST', 'tipo' => 'credito', 'estado' => 'activa', 'limite_centavos' => 100000, 'principal_centavos' => 10000, 'interes_centavos' => 0, 'emitida_el' => $today->toDateString(), 'policy' => ['annual_bps' => 3600, 'minimum_bps' => 500, 'minimum_floor_cents' => 10000, 'cutoff_day' => $today->day, 'payment_days' => 20]]);
        $service = app(CardService::class);
        $service->closeDay($today->toDateString());
        $service->closeDay($today->toDateString());
        self::assertSame(10, $card->refresh()->interes_centavos);
        self::assertSame(1, DailyAccrual::count());
        self::assertSame(1, Statement::count());
        self::assertSame(10000, Statement::first()->minimo_centavos);
        $headers = $this->headers(key: 'pay-card-once');
        $payload = ['origen_id' => (string) $account->id, 'monto' => '20.00'];
        $this->postJson('/api/v1/tarjetas/'.$card->id.'/pagos', $payload, $headers)->assertCreated();
        $this->postJson('/api/v1/tarjetas/'.$card->id.'/pagos', $payload, $headers)->assertCreated();
        self::assertSame(0, $card->refresh()->interes_centavos);
        self::assertSame(8010, $card->principal_centavos);
        self::assertSame(198000, $account->refresh()->saldo_centavos);
        self::assertSame(2000, Statement::first()->pagado_centavos);
    }

    public function test_daily_close_catches_up_days_the_scheduler_missed(): void
    {
        $today = now()->timezone('America/Guatemala')->toImmutable();
        $policy = ['annual_bps' => 3650, 'minimum_bps' => 500, 'minimum_floor_cents' => 10000, 'cutoff_day' => 1, 'payment_days' => 20];
        $c = $this->customer();
        $behind = Card::create(['cliente_id' => (string) $c->id, 'solicitud_id' => 'gap', 'referencia' => 'CPGAP', 'tipo' => 'credito', 'estado' => 'activa', 'limite_centavos' => 100000, 'principal_centavos' => 10000, 'interes_centavos' => 0, 'emitida_el' => $today->subDays(5)->toDateString(), 'ultimo_devengo' => $today->subDays(3)->toDateString(), 'policy' => $policy]);
        $healthy = Card::create(['cliente_id' => (string) $c->id, 'solicitud_id' => 'ok', 'referencia' => 'CPOK', 'tipo' => 'credito', 'estado' => 'activa', 'limite_centavos' => 100000, 'principal_centavos' => 10000, 'interes_centavos' => 0, 'emitida_el' => $today->toDateString(), 'policy' => $policy]);
        $result = app(CardService::class)->closeDay($today->toDateString());
        self::assertSame(2, $result['tarjetas_procesadas']);
        self::assertSame([], $result['pendientes']);
        self::assertSame(10, $healthy->refresh()->interes_centavos);
        self::assertSame(30, $behind->refresh()->interes_centavos);
        self::assertSame(3, DailyAccrual::where('tarjeta_id', (string) $behind->id)->count());
        self::assertSame($today->toDateString(), $behind->ultimo_devengo);
    }

    public function test_card_statement_is_debited_automatically_on_its_payment_day(): void
    {
        $today = now()->timezone('America/Guatemala')->toImmutable();
        $c = $this->customer();
        $account = $this->account($c, 3000);
        $card = Card::create(['cliente_id' => (string) $c->id, 'solicitud_id' => 'auto', 'referencia' => 'CPAUTO', 'tipo' => 'credito', 'estado' => 'activa', 'limite_centavos' => 100000, 'principal_centavos' => 5000, 'interes_centavos' => 0, 'cuenta_id' => (string) $account->id, 'cargo_automatico' => true, 'emitida_el' => $today->subMonth()->toDateString(), 'policy' => ['annual_bps' => 0, 'minimum_bps' => 500, 'minimum_floor_cents' => 1000, 'cutoff_day' => 1, 'payment_day' => 20]]);
        $statement = Statement::create(['tarjeta_id' => (string) $card->id, 'periodo' => 'x', 'fecha_corte' => $today->subDays(10)->toDateString(), 'fecha_pago' => $today->toDateString(), 'principal_centavos' => 5000, 'interes_centavos' => 0, 'saldo_centavos' => 5000, 'minimo_centavos' => 1000, 'pagado_centavos' => 0, 'abonado_centavos' => 0]);
        Statement::create(['tarjeta_id' => (string) $card->id, 'periodo' => 'y', 'fecha_corte' => $today->toDateString(), 'fecha_pago' => $today->addDays(5)->toDateString(), 'principal_centavos' => 5000, 'interes_centavos' => 0, 'saldo_centavos' => 5000, 'minimo_centavos' => 1000, 'pagado_centavos' => 0, 'abonado_centavos' => 0]);
        $result = app(CollectionService::class)->run($today->toDateString());
        self::assertSame('parcial', $result['tarjetas'][0]['resultado']);
        self::assertCount(1, $result['tarjetas'], 'A statement not yet due is not charged.');
        self::assertSame(0, $account->refresh()->saldo_centavos);
        self::assertSame(2000, $card->refresh()->principal_centavos);
        self::assertSame(3000, $statement->refresh()->abonado_centavos);
        self::assertSame(1000, $statement->pagado_centavos);
        self::assertSame([], app(CollectionService::class)->run($today->toDateString())['tarjetas'], 'Only one automatic attempt per day.');
        $account->update(['saldo_centavos' => 10000]);
        $next = app(CollectionService::class)->run($today->addDay()->toDateString());
        self::assertSame('cobrado', $next['tarjetas'][0]['resultado']);
        self::assertSame(0, $card->refresh()->principal_centavos);
        self::assertSame(8000, $account->refresh()->saldo_centavos);
    }

    public function test_parallel_transfers_cannot_overdraw_a_shared_account(): void
    {
        $source = $this->account($this->customer('parallel-source'), 1000);
        $target = $this->account($this->customer('parallel-target'), 0);
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $args = [PHP_BINARY, __DIR__.'/../concurrent_worker.php', config('database.connections.mongodb.database'), config('database.connections.mongodb.dsn'), (string) $source->id, (string) $target->id, 'parallel-transfer-'.$i, '800'];
            $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        $success = 0;
        foreach ($processes as [$process,$pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            self::assertContains($out, ['OK', 'INSUFFICIENT_FUNDS'], $err.' '.$out);
            if ($out === 'OK') {
                $success++;
            }
        }
        self::assertSame(1, $success);
        self::assertSame(200, $source->refresh()->saldo_centavos);
        self::assertSame(800, $target->refresh()->saldo_centavos);
        self::assertSame(2, Movement::count());
    }

    public function test_savings_accrual_works_on_a_fresh_install_rolls_back_failures_and_runs_once(): void
    {
        $today = now()->timezone('America/Guatemala')->toDateString();
        $full = $this->account($this->customer('full'), Money::MAX);
        $full->update(['tipo' => 'ahorro', 'tasa_bps' => 365]);
        $account = $this->account($this->customer('savings'), 100000);
        $account->update(['tipo' => 'ahorro', 'tasa_bps' => 365]);
        app(InstallService::class)->fundMasterAccount();
        $fund = Account::where('system_key', 'concentradora')->firstOrFail();
        $opening = (int) $fund->saldo_centavos;
        $service = app(SavingsService::class);
        $result = $service->closeDay($today);
        $service->closeDay($today);
        self::assertSame(1, $result['cuentas_procesadas']);
        self::assertSame([['cuenta_id' => (string) $full->id, 'codigo' => 'BALANCE_LIMIT']], array_map(fn ($p) => array_intersect_key($p, array_flip(['cuenta_id', 'codigo'])), $result['pendientes']));
        self::assertSame(Money::MAX, $full->refresh()->saldo_centavos);
        self::assertSame(100010, $account->refresh()->saldo_centavos);
        self::assertSame($opening - 10, $fund->refresh()->saldo_centavos, 'Savings interest is paid out of the master account.');
        self::assertSame(1, DailyAccrual::count());
        self::assertSame(2, Movement::where('tipo', 'interes_ahorro')->count());
    }

    public function test_funding_and_withdrawals_move_the_master_account_with_double_entry(): void
    {
        $account = $this->account($this->customer('cash'), 0);
        app(InstallService::class)->fundMasterAccount();
        $vault = Account::where('system_key', 'concentradora')->firstOrFail();
        $opening = (int) $vault->saldo_centavos;
        $this->postJson('/api/v1/caja', ['cuenta_id' => (string) $account->id, 'tipo' => 'deposito', 'monto' => '1000.00', 'concepto' => 'Depósito inicial'], $this->headers())->assertCreated();
        $this->postJson('/api/v1/caja', ['cuenta_id' => (string) $account->id, 'tipo' => 'retiro', 'monto' => '250.00', 'concepto' => 'Retiro en ventanilla'], $this->headers())->assertCreated();
        $this->postJson('/api/v1/caja', ['cuenta_id' => (string) $account->id, 'tipo' => 'retiro', 'monto' => '750.01', 'concepto' => 'Retiro excesivo'], $this->headers())->assertConflict()->assertJsonPath('code', 'INSUFFICIENT_FUNDS');
        self::assertSame(75000, $account->refresh()->saldo_centavos);
        self::assertSame($opening + 75000, $vault->refresh()->saldo_centavos, 'The deposit came into the vault and the withdrawal left it.');
        self::assertSame(0, (int) Movement::sum('importe_centavos'));
        self::assertSame(0, (int) Account::sum('saldo_centavos'), 'Every balance still adds up to zero.');
        $summary = $this->getJson('/api/v1/dashboard', $this->headers())->json('data.concentradora');
        self::assertSame(['saldo_centavos' => $opening + 75000, 'salidas_centavos' => 25000, 'entradas_centavos' => 100000], array_intersect_key($summary, array_flip(['saldo_centavos', 'salidas_centavos', 'entradas_centavos'])));
    }

    public function test_outbox_retries_with_same_event_and_signs_exact_body(): void
    {
        config(['operations.bank_url' => 'https://bank.example.test/events', 'operations.bank_secret' => 'test-shared-secret']);
        $event = OutboxEvent::create(['event_id' => 'event-contract-test', 'type' => 'solicitud.actualizada', 'status' => 'pendiente', 'attempts' => 0, 'payload' => ['estado' => 'aprobada']]);
        Http::preventStrayRequests();
        Http::fake(['https://bank.example.test/events' => Http::sequence()->push([], 503)->push([], 200)]);
        $service = app(OutboxService::class);
        self::assertSame(0, $service->deliver());
        self::assertSame('pendiente', $event->refresh()->status);
        self::assertSame(1, $event->attempts);
        self::assertSame(0, $service->deliver());
        $event->update(['next_attempt' => now()->subSecond()->toIso8601String()]);
        self::assertSame(1, $service->deliver());
        self::assertSame('enviado', $event->refresh()->status);
        self::assertSame(2, $event->attempts);
        self::assertSame(0, $service->deliver());
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            self::assertSame(['event-contract-test'], $request->header('Idempotency-Key'));
            $timestamp = $request->header('X-CP-Timestamp')[0];
            self::assertSame(hash_hmac('sha256', $timestamp.'.'.$request->body(), 'test-shared-secret'), $request->header('X-CP-Signature')[0]);
        }
    }

    public function test_dashboard_shows_the_two_inbox_stages_and_the_global_registry(): void
    {
        $customer = $this->customer('global');
        $customer->update(['password' => Hash::make('clave2026')]);
        $savings = $this->account($customer, 30000);
        $savings->update(['tipo' => 'ahorro']);
        $this->account($this->customer('second'), 12000);
        Card::create(['cliente_id' => (string) $customer->id, 'solicitud_id' => 'g', 'referencia' => 'CPG', 'tipo' => 'credito', 'estado' => 'activa', 'limite_centavos' => 500000, 'principal_centavos' => 1000, 'interes_centavos' => 50]);
        $data = $this->getJson('/api/v1/dashboard', $this->headers())->assertOk()->json('data');
        self::assertSame(['en_evaluacion', 'emitida'], array_column($data['por_estado'], 'estado'));
        self::assertSame(42000, $data['saldo_centavos']);
        $global = $data['global'];
        self::assertSame([2, 1, 1, 1, 30000, 12000, 1, 500000, 1050], [$global['clientes'], $global['clientes_con_app'], $global['cuentas_ahorro'], $global['cuentas_monetarias'], $global['saldo_ahorro_centavos'], $global['saldo_monetario_centavos'], $global['tarjetas_credito'], $global['limite_credito_centavos'], $global['deuda_tarjetas_centavos']]);
    }

    public function test_global_ledger_is_staff_only_and_filters_by_movement_type(): void
    {
        app(InstallService::class)->fundMasterAccount();
        $a = $this->account($this->customer('ledger-a'));
        $b = $this->account($this->customer('ledger-b'), 0);
        $this->postJson('/api/v1/transferencias', ['origen_id' => (string) $a->id, 'destino_id' => (string) $b->id, 'monto' => '10.00', 'concepto' => 'Movimiento global'], $this->headers())->assertCreated();
        $this->postJson('/api/v1/caja', ['cuenta_id' => (string) $b->id, 'tipo' => 'deposito', 'monto' => '5.00', 'concepto' => 'Depósito en caja'], $this->headers())->assertCreated();
        $all = $this->getJson('/api/v1/movimientos', $this->headers())->assertOk()->json('data');
        self::assertSame(8, $all['total'], 'Two customer movements, four for the cash deposit (account and vault legs) and the opening of the master account.');
        self::assertContains($a->numero, array_column($all['data'], 'cuenta_numero'));
        $this->getJson('/api/v1/movimientos?estado=deposito', $this->headers())->assertOk()->assertJsonPath('data.total', 4);
        $this->getJson('/api/v1/movimientos?cuenta_id='.$a->id, $this->headers())->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/v1/movimientos', $this->headers(app(AuthService::class)->issueService('banca')))->assertForbidden();
        $this->getJson('/api/v1/movimientos', $this->headers($this->token($this->user('empresa', 'corp'))))->assertForbidden();
        $ledger = $this->getJson('/api/v1/movimientos?estado=transferencia&orden=importe_centavos&direccion=asc', $this->headers($this->token($this->user('operador', 'ops'))))->assertOk()->json('data.data');
        self::assertSame(-1000, $ledger[0]['importe_centavos']);
        self::assertSame('Cliente ledger-a', $ledger[0]['cliente_nombre'], 'The operator sees the holder without reading the customer directory.');
    }

    public function test_staff_administer_customer_credentials_and_sessions(): void
    {
        $customer = $this->customer('access');
        $customer->update(['email' => 'acceso@example.test', 'password' => Hash::make('clave2026'), 'active' => true, 'failed_attempts' => 4, 'locked_until' => now()->addMinutes(10)]);
        CustomerSession::create(['token_hash' => hash('sha256', 'one'), 'cliente_id' => (string) $customer->id, 'expires_at' => now()->addHour(), 'dispositivo' => 'Chrome']);
        CustomerSession::create(['token_hash' => hash('sha256', 'two'), 'cliente_id' => (string) $customer->id, 'expires_at' => now()->addHour()]);
        $view = $this->getJson('/api/v1/clientes/'.$customer->id.'/acceso', $this->headers())->assertOk()->json('data');
        self::assertSame([true, true, 4, 2], [$view['acceso_app'], $view['bloqueado_hasta'] !== null, $view['intentos_fallidos'], count($view['sesiones'])]);
        $this->postJson('/api/v1/clientes/'.$customer->id.'/sesiones/cerrar', [], $this->headers($this->token($this->user('operador', 'cash-access'))))->assertForbidden();
        $this->postJson('/api/v1/clientes/'.$customer->id.'/sesiones/cerrar', [], $this->headers())->assertOk()->assertJsonPath('data.sesiones_cerradas', 2);
        $this->patchJson('/api/v1/clientes/'.$customer->id.'/acceso', ['activo' => false, 'nota' => 'Solicitud del cliente por robo de celular'], $this->headers())->assertOk()->assertJsonPath('data.activo', false);
        $bank = app(AuthService::class)->issueService('banca');
        $this->postJson('/api/v1/banca/auth/login', ['correo_electronico' => 'acceso@example.test', 'contrasena' => 'clave2026'], $this->headers($bank))->assertUnauthorized();
        $this->patchJson('/api/v1/clientes/'.$customer->id.'/acceso', ['activo' => true, 'nota' => 'Identidad verificada en agencia'], $this->headers())->assertOk()
            ->assertJsonPath('data.activo', true)->assertJsonPath('data.bloqueado_hasta', null)->assertJsonPath('data.intentos_fallidos', 0);
        $this->postJson('/api/v1/banca/auth/login', ['correo_electronico' => 'acceso@example.test', 'contrasena' => 'clave2026'], $this->headers($bank))->assertOk();
        self::assertSame(['acceso_cliente_bloqueado', 'acceso_cliente_activado'], SecurityEvent::whereIn('action', ['acceso_cliente_bloqueado', 'acceso_cliente_activado'])->orderBy('created_at')->pluck('action')->all());
    }

    public function test_banca_connection_is_configured_from_the_panel_tested_and_used_by_the_outbox(): void
    {
        $url = 'https://banca.example.test/api/gestiones/webhook';
        $this->patchJson('/api/v1/integraciones/conexiones/banca', ['url' => $url], $this->headers())->assertUnprocessable()->assertJsonPath('code', 'SECRET_REQUIRED');
        $this->patchJson('/api/v1/integraciones/conexiones/banca', ['url' => 'no-es-url', 'secreto' => 'secreto-compartido-123'], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('url');
        $this->patchJson('/api/v1/integraciones/conexiones/otro', ['url' => $url, 'secreto' => 'secreto-compartido-123'], $this->headers())->assertUnprocessable();
        $this->patchJson('/api/v1/integraciones/conexiones/banca', ['url' => $url, 'secreto' => 'secreto-compartido-123'], $this->headers($this->token($this->user('operador', 'mgr-int'))))->assertForbidden();
        $saved = $this->patchJson('/api/v1/integraciones/conexiones/banca', ['url' => $url, 'secreto' => 'secreto-compartido-123'], $this->headers())->assertOk()->json('data');
        self::assertSame([$url, true, 'panel'], [$saved['url'], $saved['secreto_configurado'], $saved['origen']]);
        self::assertStringNotContainsString('secreto-compartido-123', $this->getJson('/api/v1/integraciones/conexiones', $this->headers())->assertOk()->getContent());
        self::assertStringNotContainsString('secreto-compartido-123', json_encode(iterator_to_array(DB::connection('mongodb')->getDatabase()->selectCollection('configuracion_integraciones')->find([]))));
        Http::preventStrayRequests();
        Http::fake(['https://banca.example.test/*' => Http::response(['ok' => true], 200)]);
        $this->postJson('/api/v1/integraciones/conexiones/banca/probar', [], $this->headers())->assertOk()->assertJsonPath('data.conectado', true)->assertJsonPath('data.http_status', 200);
        OutboxEvent::create(['event_id' => 'evt-panel', 'type' => 'solicitud.actualizada', 'status' => 'pendiente', 'attempts' => 0, 'payload' => ['estado' => 'emitida']]);
        self::assertSame(1, app(OutboxService::class)->deliver());
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $timestamp = $request->header('X-CP-Timestamp')[0];
            self::assertSame(hash_hmac('sha256', $timestamp.'.'.$request->body(), 'secreto-compartido-123'), $request->header('X-CP-Signature')[0]);
        }
    }

    public function test_install_removes_corporate_demo_data_and_migrates_old_roles(): void
    {
        $vault = Account::where('system_key', 'caja')->firstOrFail();
        $owner = Customer::create(['nombre' => 'Demo S.A.', 'dpi' => '9990000000011', 'demo' => true]);
        $worker = Customer::create(['nombre' => 'Demo Persona', 'dpi' => '9990000000012', 'demo' => true]);
        $real = $this->customer('real');
        $realAccount = $this->account($real, 500);
        $companyAccount = $this->account($owner, 0);
        $workerAccount = $this->account($worker, 0);
        DB::connection('mongodb')->transaction(function () use ($vault, $companyAccount, $workerAccount): void {
            app(AccountService::class)->move((string) $vault->id, (string) $companyAccount->id, 15000000, 'test', 'Depósito demo', 'deposito');
            app(AccountService::class)->move((string) $companyAccount->id, (string) $workerAccount->id, 450000, 'test', 'Planilla demo', 'planilla');
        });
        $company = Company::create(['nombre' => 'Demo S.A.', 'nit' => 'DEMO-1', 'cuenta_id' => (string) $companyAccount->id, 'miembros' => [], 'demo' => true]);
        Collaborator::create(['empresa_id' => (string) $company->id, 'nombre' => 'Demo Persona', 'cuenta_id' => (string) $workerAccount->id, 'demo' => true]);
        $demoUser = $this->user('empresa_aprobador', 'demo-user');
        $demoUser->update(['demo' => true]);
        $legacyManager = $this->user('gerente', 'legacy-manager');
        $legacyCorporate = $this->user('empresa_operador', 'legacy-corp');
        $realCompany = Company::create(['nombre' => 'Real S.A.', 'nit' => 'REAL-1', 'cuenta_id' => (string) $realAccount->id, 'miembros' => [(string) $legacyCorporate->id]]);

        $removed = app(InstallService::class)->purgeCorporateDemo();
        self::assertSame(['empresas' => 1, 'colaboradores' => 1, 'clientes' => 2, 'cuentas' => 2, 'usuarios' => 1], $removed);
        self::assertSame(0, $vault->refresh()->saldo_centavos, 'Money of the demo accounts returned to cash.');
        self::assertSame(500, $realAccount->refresh()->saldo_centavos);
        self::assertSame(1, Company::count());
        self::assertSame(0, Movement::whereIn('cuenta_id', [(string) $companyAccount->id, (string) $workerAccount->id])->count());
        self::assertNull(User::find($demoUser->id));
        self::assertSame(0, array_sum(app(InstallService::class)->purgeCorporateDemo()));

        self::assertSame(2, app(InstallService::class)->migrateRoles());
        self::assertSame('operador', $legacyManager->refresh()->role);
        self::assertSame('empresa', $legacyCorporate->refresh()->role);
        self::assertSame((string) $realCompany->id, $legacyCorporate->empresa_id);
        self::assertSame(0, app(InstallService::class)->migrateRoles());
    }

    public function test_administrator_can_load_and_authorize_the_same_payroll(): void
    {
        app(InstallService::class)->fundMasterAccount();
        $source = $this->account($this->customer('company-admin'), 50000);
        $employee = $this->account($this->customer('employee-admin'), 0);
        $company = Company::create(['nombre' => 'Empresa Admin', 'nit' => 'EMP-ADM', 'cuenta_id' => (string) $source->id, 'miembros' => []]);
        $id = $this->postJson('/api/v1/planillas', ['empresa_id' => (string) $company->id, 'nombre' => 'Planilla del administrador', 'pagos' => [['nombre' => 'Uno', 'cuenta_numero' => $employee->numero, 'monto' => '100.00', 'puesto' => 'Piloto']]], $this->headers())->assertCreated()->json('data.id');
        $this->postJson('/api/v1/planillas/'.$id.'/autorizar', [], $this->headers())->assertOk()->assertJsonPath('data.estado', 'pagada');
        self::assertSame(10000, $employee->refresh()->saldo_centavos);
        self::assertSame(40000, $source->refresh()->saldo_centavos);
    }

    public function test_staff_reset_customer_and_internal_user_passwords(): void
    {
        $customer = $this->customer('reset');
        $customer->update(['email' => 'reset@example.test', 'password' => Hash::make('vieja2026'), 'active' => true, 'failed_attempts' => 5, 'locked_until' => now()->addMinutes(10)]);
        CustomerSession::create(['token_hash' => hash('sha256', 'reset-session'), 'cliente_id' => (string) $customer->id, 'expires_at' => now()->addHour()]);
        $path = '/api/v1/clientes/'.$customer->id.'/contrasena';
        $this->postJson($path, ['contrasena' => 'nueva2026', 'nota' => 'Llamada del cliente'], $this->headers($this->token($this->user('operador', 'cash-reset'))))->assertForbidden();
        $this->postJson($path, ['contrasena' => 'sololetras', 'nota' => 'Llamada del cliente'], $this->headers())->assertUnprocessable()->assertJsonPath('errors.contrasena.0', 'Incluye al menos una letra y un número.');
        $this->postJson($path, ['contrasena' => 'nueva2026', 'nota' => 'Llamada del cliente'], $this->headers())->assertOk()
            ->assertJsonPath('data.contrasena_restablecida', true)->assertJsonPath('data.sesiones_cerradas', 1)->assertJsonPath('data.bloqueado_hasta', null)->assertJsonMissingPath('data.contrasena');
        self::assertStringNotContainsString('nueva2026', IdempotencyRecord::latest()->first()->toJson());
        $bank = app(AuthService::class)->issueService('banca');
        $this->postJson('/api/v1/banca/auth/login', ['correo_electronico' => 'reset@example.test', 'contrasena' => 'vieja2026'], $this->headers($bank))->assertUnauthorized();
        $this->postJson('/api/v1/banca/auth/login', ['correo_electronico' => 'reset@example.test', 'contrasena' => 'nueva2026'], $this->headers($bank))->assertOk();
        $noEmail = $this->customer('no-email');
        $this->postJson('/api/v1/clientes/'.$noEmail->id.'/contrasena', ['contrasena' => 'nueva2026', 'nota' => 'Llamada del cliente'], $this->headers())->assertUnprocessable()->assertJsonPath('code', 'EMAIL_REQUIRED');

        $operator = $this->user('empresa', 'demo-operator');
        $operatorToken = $this->token($operator);
        $this->postJson('/api/v1/usuarios/'.$operator->id.'/contrasena', ['contrasena' => 'corta'], $this->headers())->assertUnprocessable()->assertJsonPath('errors.contrasena.0', 'Usa al menos 12 caracteres.');
        $this->postJson('/api/v1/usuarios/'.$operator->id.'/contrasena', ['contrasena' => 'OperadorNuevo2026'], $this->headers())->assertOk()->assertJsonMissingPath('data.password');
        $this->getJson('/api/v1/auth/me', $this->headers($operatorToken))->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => $operator->email, 'password' => 'OperadorNuevo2026'])->assertOk();
        $this->postJson('/api/v1/usuarios/'.$this->admin->id.'/contrasena', ['contrasena' => 'AdminNuevo2026x'], $this->headers())->assertOk();
        $this->getJson('/api/v1/auth/me', $this->headers())->assertOk();
    }

    public function test_panel_login_issues_jwt_that_refreshes_with_rotation_and_logout_revokes(): void
    {
        $login = $this->postJson('/api/v1/auth/login', ['email' => $this->admin->email, 'password' => 'TestingPass123!'])->assertOk()->json('data');
        self::assertCount(3, explode('.', $login['token']), 'The access token is a JWT.');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $login['refresh_token']);
        self::assertSame(900, $login['expires_in']);
        $claims = json_decode(base64_decode(strtr(explode('.', $login['token'])[1], '-_', '+/')), true);
        self::assertSame((string) $this->admin->id, $claims['sub']);
        $this->getJson('/api/v1/auth/me', $this->headers($login['token']))->assertOk()->assertJsonPath('data.user.email', $this->admin->email);
        [$head, $body] = explode('.', $login['token']);
        $this->getJson('/api/v1/auth/me', $this->headers($head.'.'.$body.'.firma-falsa'))->assertUnauthorized()->assertJsonPath('code', 'INVALID_TOKEN');
        $none = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=');
        $this->getJson('/api/v1/auth/me', $this->headers($none.'.'.$body.'.x'))->assertUnauthorized();

        $renewed = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $login['refresh_token']])->assertOk()->json('data');
        self::assertNotSame($login['refresh_token'], $renewed['refresh_token']);
        $this->getJson('/api/v1/auth/me', $this->headers($renewed['token']))->assertOk();
        // Reusing a rotated refresh token closes the whole session.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $login['refresh_token']])->assertUnauthorized();
        $this->getJson('/api/v1/auth/me', $this->headers($renewed['token']))->assertUnauthorized()->assertJsonPath('code', 'SESSION_EXPIRED');
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $renewed['refresh_token']])->assertUnauthorized();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'corto'])->assertUnprocessable();

        $second = $this->postJson('/api/v1/auth/login', ['email' => $this->admin->email, 'password' => 'TestingPass123!'])->assertOk()->json('data');
        $this->travel(16)->minutes();
        $this->getJson('/api/v1/auth/me', $this->headers($second['token']))->assertUnauthorized()->assertJsonPath('code', 'TOKEN_EXPIRED');
        $third = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $second['refresh_token']])->assertOk()->json('data');
        $this->postJson('/api/v1/auth/logout', [], $this->headers($third['token']))->assertOk();
        $this->getJson('/api/v1/auth/me', $this->headers($third['token']))->assertUnauthorized();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $third['refresh_token']])->assertUnauthorized();
        $this->travelBack();
    }

    public function test_role_matrix_separates_operations_corporate_banking_and_administration(): void
    {
        app(InstallService::class)->fundMasterAccount();
        $operator = $this->token($this->user('operador', 'ops'));
        $mixed = $this->token($this->user('mixto', 'mix'));
        $corporate = $this->token($this->user('corporativo', 'corp'));
        $user = ['name' => 'Nueva', 'email' => 'nueva@example.test', 'password' => 'ClaveSegura2026'];
        foreach ([$operator, $mixed, $corporate] as $token) {
            $this->postJson('/api/v1/usuarios', $user + ['role' => 'operador'], $this->headers($token))->assertForbidden();
            foreach (['/api/v1/usuarios', '/api/v1/integraciones', '/api/v1/bitacora', '/api/v1/seguridad'] as $path) {
                $this->getJson($path, $this->headers($token))->assertForbidden();
            }
        }
        $this->postJson('/api/v1/usuarios', $user + ['role' => 'empresa'], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('role');
        foreach (['mixto', 'operador', 'corporativo'] as $i => $role) {
            $this->postJson('/api/v1/usuarios', ['email' => "rol{$i}@example.test"] + $user + ['role' => $role], $this->headers())->assertCreated()->assertJsonPath('data.role', $role);
        }

        // Operaciones: operador and mixto; never corporativo.
        foreach (['/api/v1/dashboard', '/api/v1/solicitudes', '/api/v1/cuentas', '/api/v1/creditos', '/api/v1/tarjetas', '/api/v1/movimientos', '/api/v1/reportes/transacciones'] as $path) {
            $this->getJson($path, $this->headers($operator))->assertOk();
            $this->getJson($path, $this->headers($mixed))->assertOk();
            $this->getJson($path, $this->headers($corporate))->assertForbidden();
        }
        // Banca corporativa: corporativo and mixto; never operador.
        foreach (['/api/v1/empresas', '/api/v1/colaboradores', '/api/v1/planillas', '/api/v1/colaboradores/candidatos'] as $path) {
            $this->getJson($path, $this->headers($corporate))->assertOk();
            $this->getJson($path, $this->headers($mixed))->assertOk();
            $this->getJson($path, $this->headers($operator))->assertForbidden();
        }
        $this->getJson('/api/v1/reportes/planillas?empresa_id=0123456789abcdef01234567', $this->headers($operator))->assertForbidden();

        // The corporate role manages companies end to end: company, account, its users and its funding.
        $company = $this->postJson('/api/v1/empresas', ['nombre' => 'Rol Corporativo', 'razon_social' => 'Rol Corporativo, S.A.', 'nit' => '5566778'], $this->headers($corporate))->assertCreated()->json('data');
        $member = $this->postJson('/api/v1/empresas/'.$company['id'].'/usuarios', ['nombre' => 'Pagos', 'correo' => 'pagos@rol.test', 'contrasena' => 'PagosEmpresa2026'], $this->headers($corporate))->assertCreated()->json('data');
        $this->postJson('/api/v1/empresas/'.$company['id'].'/usuarios/'.$member['id'].'/contrasena', ['contrasena' => 'OtraClaveEmpresa26'], $this->headers($corporate))->assertOk();
        $this->patchJson('/api/v1/empresas/'.$company['id'].'/usuarios/'.$member['id'], ['activo' => false], $this->headers($corporate))->assertOk()->assertJsonPath('data.active', false);
        $staff = User::where('role', 'operador')->first();
        $this->patchJson('/api/v1/empresas/'.$company['id'].'/usuarios/'.$staff->id, ['activo' => false], $this->headers($corporate))->assertForbidden();
        $this->postJson('/api/v1/empresas/'.$company['id'].'/fondeos', ['cuenta_id' => $company['cuenta_id'], 'tipo' => 'deposito', 'monto' => '2500.00', 'concepto' => 'Fondeo corporativo'], $this->headers($corporate))->assertCreated();
        $personal = $this->account($this->customer('ajena'), 0);
        $this->postJson('/api/v1/empresas/'.$company['id'].'/fondeos', ['cuenta_id' => (string) $personal->id, 'tipo' => 'deposito', 'monto' => '1.00', 'concepto' => 'Intento'], $this->headers($corporate))->assertUnprocessable();
        $this->postJson('/api/v1/caja', ['cuenta_id' => (string) $personal->id, 'tipo' => 'deposito', 'monto' => '1.00', 'concepto' => 'Intento'], $this->headers($corporate))->assertForbidden();
        $this->postJson('/api/v1/empresas/'.$company['id'].'/usuarios', ['nombre' => 'X', 'correo' => 'x@rol.test', 'contrasena' => 'PagosEmpresa2026'], $this->headers($operator))->assertForbidden();
        self::assertSame(250000, Account::findOrFail($company['cuenta_id'])->saldo_centavos);
    }

    public function test_the_original_administrator_cannot_be_changed_by_anyone_else(): void
    {
        $this->admin->update(['protegido' => true]);
        $second = $this->user('administrador', 'second-admin');
        $token = $this->token($second);
        $this->patchJson('/api/v1/usuarios/'.$this->admin->id, ['role' => 'operador', 'active' => false], $this->headers($token))->assertForbidden()->assertJsonPath('code', 'ADMIN_PROTECTED');
        $this->postJson('/api/v1/usuarios/'.$this->admin->id.'/contrasena', ['contrasena' => 'OtraClaveAdmin2026'], $this->headers($token))->assertForbidden()->assertJsonPath('code', 'ADMIN_PROTECTED');
        $this->postJson('/api/v1/usuarios/'.$this->admin->id.'/contrasena', ['contrasena' => 'OtraClaveAdmin2026'], $this->headers())->assertOk();
        self::assertSame('administrador', $this->admin->refresh()->role);
        self::assertTrue($this->admin->active);
        self::assertNull(app(InstallService::class)->protectOriginalAdministrator(), 'Only one administrator is ever protected.');
    }

    public function test_customer_form_requires_the_full_file_and_never_grants_banca_access_or_accounts(): void
    {
        $body = ['nombres' => 'David', 'apellidos' => 'Pérez', 'email' => 'David@Credit.com', 'dpi' => '9876543219876', 'nit' => '98765-4', 'telefono' => '1234-5678', 'fecha_nacimiento' => '1995-02-01', 'ingreso_mensual' => '10000.00', 'antiguedad_laboral_meses' => 30];
        $this->postJson('/api/v1/clientes', ['nombre' => 'David Pérez'] + array_diff_key($body, ['nombres' => 1, 'apellidos' => 1]), $this->headers())->assertUnprocessable()->assertJsonValidationErrors(['nombres', 'apellidos']);
        foreach ([['dpi', '98765'], ['dpi', null], ['fecha_nacimiento', null], ['antiguedad_laboral_meses', null], ['nit', 'abc'], ['telefono', 'x'], ['email', 'no-es-correo']] as [$field, $value]) {
            $this->postJson('/api/v1/clientes', [$field => $value] + $body, $this->headers())->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson('/api/v1/clientes', ['antiguedad_laboral_meses' => 400] + $body, $this->headers())->assertUnprocessable()->assertJsonValidationErrors('antiguedad_laboral_meses');
        // Password and account fields are no longer part of the form: they are ignored.
        $created = $this->postJson('/api/v1/clientes', $body + ['contrasena' => 'clave2026', 'producto_cuenta' => 'ahorro'], $this->headers())->assertCreated()
            ->assertJsonPath('data.nombre', 'David Pérez')->assertJsonPath('data.nombres', 'David')->assertJsonPath('data.apellidos', 'Pérez')->assertJsonPath('data.email', 'david@credit.com')
            ->assertJsonPath('data.nit', '98765-4')->assertJsonPath('data.ingreso_centavos', 1000000)->assertJsonPath('data.acceso_app', false)->assertJsonMissingPath('data.cuenta')->json('data');
        self::assertSame(0, Account::where('cliente_id', $created['id'])->count());
        $this->postJson('/api/v1/clientes', ['email' => 'otro@credit.com'] + $body, $this->headers())->assertConflict()->assertJsonPath('code', 'CUSTOMER_EXISTS')
            ->assertJsonPath('message', 'Ya existe un cliente registrado con ese DPI. Búscalo en el directorio en lugar de crearlo otra vez.');
        self::assertSame(1, Customer::where('email', 'david@credit.com')->count());
        $bank = app(AuthService::class)->issueService('banca');
        $this->postJson('/api/v1/banca/auth/login', ['correo_electronico' => 'david@credit.com', 'contrasena' => 'clave2026'], $this->headers($bank))->assertUnauthorized();
    }

    public function test_loan_installments_are_paid_from_banca_and_collected_automatically(): void
    {
        app(InstallService::class)->fundMasterAccount();
        $customer = Customer::create(['nombre' => 'Cliente préstamo', 'dpi' => '5555666677778', 'email' => 'loan@example.test', 'password' => Hash::make('clave2026'), 'active' => true]);
        $account = $this->account($customer, 0);
        $app = app(IdempotencyService::class)->run((string) $this->admin->id, 'create-loan-1', 'application', [], fn () => app(ApplicationService::class)->create(['cliente_id' => (string) $customer->id, 'producto' => 'credito-personal', 'limite_solicitado' => '12000.00', 'origen' => 'banca_virtual'], 'service:banca', preEvaluated: true));
        $this->postJson('/api/v1/solicitudes/'.$app['id'].'/transiciones', ['version' => 1, 'estado' => 'emitida', 'limite_asignado' => '12000.00', 'nota' => 'Préstamo aprobado', 'plazo_meses' => 3, 'tasa_anual' => '0', 'dia_pago' => 28, 'cuenta_id' => (string) $account->id], $this->headers())->assertOk();
        $credit = Credit::firstOrFail();
        self::assertSame(1200000, $account->refresh()->saldo_centavos);
        self::assertSame([400000, 400000, 400000], array_column($credit->cuotas, 'monto_centavos'));
        $bank = app(AuthService::class)->issueService('banca');
        $listed = $this->getJson('/api/v1/creditos?dpi='.$customer->dpi, $this->headers($bank))->assertOk()->json('data.data.0');
        self::assertSame(3, $listed['cuotas_total']);
        self::assertSame('4000.00', $listed['proxima_cuota']['monto']);
        $pay = '/api/v1/creditos/'.$credit->id.'/pagos';
        $intruder = Customer::create(['nombre' => 'Intruso', 'dpi' => '5555666677779']);
        $this->postJson($pay, ['origen_id' => (string) $account->id, 'dpi' => $intruder->dpi], $this->headers($bank))->assertForbidden();
        $this->postJson($pay, ['origen_id' => (string) $account->id, 'monto' => '12000.01', 'dpi' => $customer->dpi], $this->headers($bank))->assertUnprocessable()->assertJsonPath('code', 'PAYMENT_EXCEEDS_DEBT');
        $this->postJson($pay, ['origen_id' => (string) $account->id, 'dpi' => $customer->dpi], $this->headers($bank, 'pay-installment-1'))->assertCreated()->assertJsonPath('data.credito.cuotas_pagadas', 1)->assertJsonPath('data.credito.saldo', '8000.00');
        $this->postJson($pay, ['origen_id' => (string) $account->id, 'dpi' => $customer->dpi], $this->headers($bank, 'pay-installment-1'))->assertCreated();
        self::assertSame(800000, $account->refresh()->saldo_centavos);
        $detail = $this->getJson('/api/v1/solicitudes/'.$app['id'], $this->headers())->assertOk()->json('data');
        self::assertSame('pago_prestamo', end($detail['historial'])['action']);
        self::assertSame(800000, $detail['credito']['saldo_centavos']);
        self::assertTrue($detail['integridad_verificada']);

        $due = $credit->refresh()->cuotas[1]['fecha_vencimiento'];
        $account->update(['saldo_centavos' => 150000]);
        $collected = app(CollectionService::class)->run($due)['prestamos'];
        self::assertSame('parcial', $collected[0]['resultado']);
        self::assertSame('parcial', $credit->refresh()->cuotas[1]['estado']);
        $account->update(['saldo_centavos' => 2000000]);
        self::assertSame('cobrado', app(CollectionService::class)->run($due)['prestamos'][0]['resultado']);
        self::assertSame([], app(CollectionService::class)->run($due)['prestamos']);
        $this->postJson($pay, ['origen_id' => (string) $account->id, 'monto' => '4000.00'], $this->headers())->assertCreated()->assertJsonPath('data.credito.estado', 'pagado');
        self::assertSame(0, $credit->refresh()->saldo_centavos);
        $this->postJson($pay, ['origen_id' => (string) $account->id], $this->headers())->assertConflict()->assertJsonPath('code', 'LOAN_SETTLED');
        $master = Account::where('system_key', 'concentradora')->firstOrFail();
        self::assertSame((int) config('operations.master_account_opening_cents'), (int) $master->saldo_centavos, 'Every quetzal lent came back to the master account.');
    }

    public function test_reports_return_filtered_payroll_statement_and_global_transactions(): void
    {
        app(InstallService::class)->fundMasterAccount();
        Http::fake(['*TipoCambio.asmx' => Http::response('<fecha>21/10/2026</fecha><referencia>7.63916</referencia>'), '*' => Http::response([], 500)]);
        $holder = $this->customer('holder');
        $a = $this->account($holder, 50000);
        $b = $this->account($this->customer('second'), 0);
        $this->postJson('/api/v1/transferencias', ['origen_id' => (string) $a->id, 'destino_id' => (string) $b->id, 'monto' => '100.00', 'concepto' => 'Pago de prueba'], $this->headers())->assertCreated();
        $this->postJson('/api/v1/caja', ['cuenta_id' => (string) $a->id, 'tipo' => 'deposito', 'monto' => '25.00', 'concepto' => 'Depósito'], $this->headers())->assertCreated();
        $today = now()->timezone('America/Guatemala')->toDateString();
        $statement = $this->getJson('/api/v1/reportes/estado-cuenta?cliente_id='.$holder->id.'&desde='.$today.'&hasta='.$today, $this->headers())->assertOk()->json('data');
        self::assertSame('Cliente holder', $statement['cliente']['nombre']);
        self::assertCount(2, $statement['cuentas'][0]['movimientos']);
        self::assertSame(10000, $statement['cuentas'][0]['debitos_centavos']);
        self::assertSame(2500, $statement['cuentas'][0]['creditos_centavos']);
        self::assertSame(7.63916, $statement['tipo_cambio']['quetzales_por_dolar']);
        $this->getJson('/api/v1/reportes/estado-cuenta', $this->headers())->assertUnprocessable();
        $this->getJson('/api/v1/reportes/estado-cuenta?cliente_id='.$holder->id.'&desde=2026-10-10&hasta=2026-10-01', $this->headers())->assertUnprocessable();
        $global = $this->getJson('/api/v1/reportes/transacciones?desde='.$today, $this->headers())->assertOk()->json('data');
        self::assertSame(3, $global['totales']['movimientos'], 'Internal cash movements are not customer transactions.');
        self::assertSame(1, $this->getJson('/api/v1/reportes/transacciones?tipo=deposito', $this->headers())->json('data.totales.movimientos'));
        $company = Company::create(['nombre' => 'Reporte S.A.', 'nit' => 'REP-1', 'cuenta_id' => (string) $a->id, 'miembros' => []]);
        Payroll::create(['empresa_id' => (string) $company->id, 'nombre' => 'Octubre', 'estado' => 'pagada', 'total_centavos' => 5000, 'pagos' => [['nombre' => 'Uno', 'centavos' => 5000, 'cuenta_numero' => $b->numero]]]);
        Payroll::create(['empresa_id' => (string) $company->id, 'nombre' => 'Noviembre', 'estado' => 'pendiente_autorizacion', 'total_centavos' => 7000, 'pagos' => []]);
        $payroll = $this->getJson('/api/v1/reportes/planillas?empresa_id='.$company->id, $this->headers())->assertOk()->json('data');
        self::assertSame(['planillas' => 2, 'pagadas' => 1, 'pagado_centavos' => 5000, 'pendiente_centavos' => 7000, 'beneficiarios' => 1], $payroll['totales']);
        self::assertSame(1, $this->getJson('/api/v1/reportes/planillas?estado=pagada&empresa_id='.$company->id, $this->headers())->json('data.totales.planillas'));
    }

    public function test_exchange_rate_comes_from_banguat_with_fallback_and_last_known_value(): void
    {
        $online = true;
        Http::fake(function ($request) use (&$online) {
            return $online && str_contains($request->url(), 'open.er-api.com') ? Http::response(['rates' => ['GTQ' => 7.7]]) : Http::response('', 503);
        });
        $this->getJson('/api/v1/tipo-cambio', $this->headers())->assertOk()->assertJsonPath('data.quetzales_por_dolar', 7.7)->assertJsonPath('data.fuente', 'ExchangeRate-API (respaldo)');
        cache()->flush();
        $online = false;
        $this->getJson('/api/v1/tipo-cambio', $this->headers())->assertOk()->assertJsonPath('data.quetzales_por_dolar', 7.7)->assertJsonPath('data.desactualizado', true);
        $this->getJson('/api/v1/banca/tipo-cambio', $this->headers(app(AuthService::class)->issueService('banca')))->assertOk();
    }

    public function test_listings_support_text_search_sorting_and_date_filters(): void
    {
        $ana = Customer::create(['nombre' => 'Ana Lucía Méndez', 'email' => 'ana@example.test', 'dpi' => '1234567890123']);
        Customer::create(['nombre' => 'Bruno Díaz', 'email' => 'bruno@example.test', 'dpi' => '1234567890124']);
        $this->account($ana, 900);
        $this->account($this->customer('rich'), 50000);
        $this->getJson('/api/v1/clientes?q=lucía', $this->headers())->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.nombre', 'Ana Lucía Méndez');
        $this->getJson('/api/v1/clientes?q=1234567890124', $this->headers())->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.nombre', 'Bruno Díaz');
        $this->getJson('/api/v1/clientes?q=.*', $this->headers())->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/v1/cuentas?q=ana', $this->headers())->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/v1/cuentas?orden=saldo_centavos&direccion=desc', $this->headers())->assertOk()->assertJsonPath('data.data.0.saldo_centavos', 50000);
        $this->getJson('/api/v1/clientes?orden=nombre&direccion=asc', $this->headers())->assertOk()->assertJsonPath('data.data.0.nombre', 'Ana Lucía Méndez');
        $this->getJson('/api/v1/clientes?orden=password', $this->headers())->assertUnprocessable();
        $this->getJson('/api/v1/clientes?desde=2000-01-01&hasta=2000-01-31', $this->headers())->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_corporate_banking_keeps_companies_apart_from_personal_customers(): void
    {
        app(InstallService::class)->fundMasterAccount();
        $person = $this->customer('persona');
        $personal = $this->account($person, 0);
        $body = ['nombre' => 'Distribuidora Norte S.A.', 'razon_social' => 'Comercializadora del Norte, S.A.', 'representante_legal' => 'Ana Norte', 'nit' => '7788990', 'correo' => 'pagos@norte.test', 'producto_cuenta' => 'monetaria', 'representante_nombre' => 'Lucía Norte', 'representante_correo' => 'lucia@norte.test'];
        $this->postJson('/api/v1/empresas', $body + ['contrasena' => 'corta'], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('contrasena');
        $this->postJson('/api/v1/empresas', ['nit' => 'NIT 123'] + $body + ['contrasena' => 'RepresentanteNorte2026'], $this->headers())->assertUnprocessable()->assertJsonPath('errors.nit.0', 'Escribe el NIT sin espacios, por ejemplo 1234567-8 o 1234567K.');
        $company = $this->postJson('/api/v1/empresas', $body + ['contrasena' => 'RepresentanteNorte2026'], $this->headers())->assertCreated()
            ->assertJsonPath('data.cuentas.0.tipo', 'monetaria')->assertJsonPath('data.razon_social', 'Comercializadora del Norte, S.A.')->assertJsonPath('data.representante_legal', 'Ana Norte')->assertJsonPath('data.representantes.0.email', 'lucia@norte.test')->json('data');
        $this->postJson('/api/v1/empresas', $body + ['contrasena' => 'RepresentanteNorte2026'], $this->headers())->assertConflict();
        $id = $company['id'];
        $extra = $this->postJson('/api/v1/empresas/'.$id.'/cuentas', ['producto' => 'ahorro', 'nombre' => 'Reserva'], $this->headers())->assertCreated()->json('data');
        $this->postJson('/api/v1/empresas/'.$id.'/usuarios', ['nombre' => 'Mario Norte', 'correo' => 'mario@norte.test', 'contrasena' => 'MarioNorte2026x'], $this->headers())->assertCreated()->assertJsonPath('data.role', 'empresa');
        $this->postJson('/api/v1/caja', ['cuenta_id' => $company['cuenta_id'], 'tipo' => 'deposito', 'monto' => '9000.00', 'concepto' => 'Fondeo de planillas'], $this->headers())->assertCreated();

        // Personal screens never show the company, its accounts or its users…
        $personalAccounts = array_column($this->getJson('/api/v1/cuentas', $this->headers())->json('data.data'), 'id');
        self::assertSame([(string) $personal->id], $personalAccounts);
        self::assertNotContains('lucia@norte.test', array_column($this->getJson('/api/v1/usuarios', $this->headers())->json('data.data'), 'email'));
        // …and the corporate screens never show personal customers.
        $corporate = $this->getJson('/api/v1/cuentas?empresa_id='.$id, $this->headers())->json('data.data');
        self::assertEqualsCanonicalizing([$company['cuenta_id'], $extra['id']], array_column($corporate, 'id'));
        self::assertEqualsCanonicalizing(['lucia@norte.test', 'mario@norte.test'], array_column($this->getJson('/api/v1/usuarios?empresa_id='.$id, $this->headers())->json('data.data'), 'email'));
        $detail = $this->getJson('/api/v1/empresas/'.$id, $this->headers())->assertOk()->json('data');
        self::assertSame(900000, $detail['saldo_total_centavos']);
        self::assertCount(2, $detail['representantes']);
        self::assertSame(900000, $this->getJson('/api/v1/empresas', $this->headers())->json('data.data.0.saldo_total_centavos'));

        $this->postJson('/api/v1/colaboradores', ['empresa_id' => $id, 'cuenta_id' => $extra['id'], 'puesto' => 'X', 'salario' => '1.00'], $this->headers())->assertUnprocessable()->assertJsonPath('message', 'Las cuentas de empresas no reciben planilla: elige la cuenta personal del colaborador.');
        $this->postJson('/api/v1/colaboradores', ['empresa_id' => $id, 'cliente_id' => (string) $person->id, 'cuenta_id' => (string) $personal->id, 'puesto' => 'Chofer', 'salario' => '3000.00'], $this->headers())->assertCreated();
        $rep = $this->postJson('/api/v1/auth/login', ['email' => 'lucia@norte.test', 'password' => 'RepresentanteNorte2026'])->assertOk()->json('data.token');
        $this->getJson('/api/v1/empresas/'.$id, $this->headers($rep))->assertOk();
        $other = Company::create(['nombre' => 'Otra', 'nit' => 'OTRA-1', 'miembros' => []]);
        $this->getJson('/api/v1/empresas/'.$other->id, $this->headers($rep))->assertForbidden();
        $this->postJson('/api/v1/empresas/'.$id.'/cuentas', ['producto' => 'ahorro'], $this->headers($rep))->assertForbidden();
    }

    public function test_install_moves_legacy_company_accounts_out_of_the_personal_directory(): void
    {
        $holder = Customer::create(['nombre' => 'Comercial Antigua S.A.', 'dpi' => '4444555566667']);
        $account = $this->account($holder, 1000);
        $person = $this->customer('dueña');
        $person->update(['password' => Hash::make('clave2026')]);
        $shared = $this->account($person, 500);
        $legacy = Company::create(['nombre' => 'Comercial Antigua S.A.', 'nit' => 'ANT-1', 'cuenta_id' => (string) $account->id, 'miembros' => []]);
        $shop = Company::create(['nombre' => 'Tienda de la dueña', 'nit' => 'ANT-2', 'cuenta_id' => (string) $shared->id, 'miembros' => []]);
        self::assertSame(2, app(InstallService::class)->migrateCompanyAccounts());
        self::assertSame(0, app(InstallService::class)->migrateCompanyAccounts());
        self::assertSame((string) $legacy->id, $account->refresh()->empresa_id);
        self::assertSame('empresa', $holder->refresh()->segmento);
        self::assertNull($person->refresh()->segmento, 'A customer with app access stays in the personal directory.');
        $names = array_column($this->getJson('/api/v1/clientes', $this->headers())->json('data.data'), 'nombre');
        self::assertNotContains('Comercial Antigua S.A.', $names);
        self::assertContains('Cliente dueña', $names);
        self::assertSame((string) $shop->id, $shared->refresh()->empresa_id);
    }

    public function test_banca_is_notified_of_every_balance_change_when_its_webhook_is_configured(): void
    {
        app(InstallService::class)->fundMasterAccount();
        $customer = $this->customer('saldo');
        $account = $this->account($customer, 0);
        $deposit = fn (string $key) => $this->postJson('/api/v1/caja', ['cuenta_id' => (string) $account->id, 'tipo' => 'deposito', 'monto' => '5000.00', 'concepto' => 'Pago Quincena'], $this->headers(key: $key))->assertCreated();
        $deposit('deposit-without-webhook');
        self::assertSame(0, OutboxEvent::where('type', 'cuenta.movimiento')->count());
        IntegrationSetting::create(['sistema' => 'banca', 'url' => 'https://banca.example.test/webhooks/gestiones', 'secreto' => Crypt::encryptString('secreto-compartido-123')]);
        $deposit('deposit-with-webhook');
        $event = OutboxEvent::where('type', 'cuenta.movimiento')->firstOrFail();
        self::assertSame(1000000, $event->payload['saldo_centavos']);
        self::assertSame('10000.00', $event->payload['saldo']);
        self::assertSame((string) $customer->id, $event->payload['cliente_id']);
        self::assertSame(1, OutboxEvent::where('type', 'cuenta.movimiento')->count(), 'The internal cash account is not announced.');
    }

    public function test_master_account_absorbs_earlier_cash_funding_and_pays_card_purchases(): void
    {
        app(InstallService::class)->fundMasterAccount();
        $master = Account::where('system_key', 'concentradora')->firstOrFail();
        $opening = (int) $master->saldo_centavos;
        $company = $this->account($this->customer('empresa-antigua'), 0);
        $cash = Account::where('system_key', 'caja')->firstOrFail();
        // Before this version a deposit came out of the cash account and left the master account untouched.
        DB::connection('mongodb')->transaction(fn () => app(AccountService::class)->move((string) $cash->id, (string) $company->id, 100000000, 'test', 'Fondeo antiguo', 'deposito'));
        self::assertSame(['caja' => '1000000.00'], app(InstallService::class)->reconcileMasterAccount());
        self::assertSame([], app(InstallService::class)->reconcileMasterAccount());
        self::assertSame(0, $cash->refresh()->saldo_centavos);
        self::assertSame($opening - 100000000, $master->refresh()->saldo_centavos);
        self::assertSame(100000000, $company->refresh()->saldo_centavos);

        $holder = $this->customer('tarjeta');
        $card = Card::create(['cliente_id' => (string) $holder->id, 'solicitud_id' => 'card-vault', 'referencia' => 'CPVAULT', 'tipo' => 'credito', 'estado' => 'activa', 'limite_centavos' => 500000, 'principal_centavos' => 0, 'interes_centavos' => 0, 'policy' => ['annual_bps' => 0]]);
        $this->postJson('/api/v1/tarjetas/'.$card->id.'/consumos', ['monto' => '300.00', 'concepto' => 'Supermercado'], $this->headers())->assertCreated();
        self::assertSame($opening - 100000000 - 30000, $master->refresh()->saldo_centavos, 'The bank pays the merchant from the master account.');
        $source = $this->account($holder, 50000);
        $this->postJson('/api/v1/tarjetas/'.$card->id.'/pagos', ['origen_id' => (string) $source->id, 'monto' => '300.00'], $this->headers())->assertCreated();
        self::assertSame($opening - 100000000, $master->refresh()->saldo_centavos, 'The card payment returns to the master account.');
    }

    public function test_credits_to_people_are_sent_once_to_banca_virtual_abonos_api(): void
    {
        app(InstallService::class)->fundMasterAccount();
        $url = 'https://banca.example.test/api/v1/admin/abonos';
        $source = $this->account($this->customer('empresa-abonos'), 20000);
        $company = Company::create(['nombre' => 'Abonos S.A.', 'nit' => 'ABO-1', 'cuenta_id' => (string) $source->id, 'miembros' => []]);
        $ana = Customer::create(['nombre' => 'Ana Abono', 'dpi' => '3000000000001']);
        $luis = Customer::create(['nombre' => 'Luis Abono', 'dpi' => '3000000000002']);
        $anaAccount = $this->account($ana, 0);
        $anaAccount->update(['tipo' => 'ahorro']);
        $luisAccount = $this->account($luis, 0);
        $body = ['empresa_id' => (string) $company->id, 'nombre' => 'Quincena', 'pagos' => [['nombre' => 'Ana', 'cuenta_numero' => $anaAccount->numero, 'monto' => '60.00'], ['nombre' => 'Luis', 'cuenta_numero' => $luisAccount->numero, 'monto' => '40.50']]];

        // Without the key configured nothing is queued.
        $first = $this->postJson('/api/v1/planillas', $body, $this->headers())->assertCreated()->json('data.id');
        $this->postJson('/api/v1/planillas/'.$first.'/autorizar', [], $this->headers())->assertOk();
        self::assertSame(0, OutboxEvent::where('type', 'banca.abono')->count());

        $this->patchJson('/api/v1/integraciones/conexiones/banca_abonos', ['url' => $url, 'secreto' => 'llave de prueba'], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('secreto');
        $this->patchJson('/api/v1/integraciones/conexiones/banca_abonos', ['url' => $url, 'secreto' => 'llave-de-servicio-de-prueba-0001'], $this->headers())->assertOk()->assertJsonPath('data.secreto_configurado', true);
        // One fake for the whole test (Laravel keeps earlier stubs first); $mode chooses BancaVirtual's answer.
        $mode = 'prueba';
        $calls = [];
        $luisNumber = $luisAccount->numero;
        Http::fake(function ($request) use (&$mode, &$calls, $url, $luisNumber) {
            if ($request->url() !== $url) {
                return Http::response([], 200);
            }
            if ($mode === 'prueba') {
                return Http::response(['message' => ['monto debe ser mayor a 0']], 422);
            }
            if ($mode === 'bloqueada') {
                return Http::response(['message' => 'Cuenta bloqueada'], 422);
            }
            $calls[] = $request;
            // Luis's account predates shared numbers: BancaVirtual only finds it by DPI and type.
            if (($request->data()['cuentaNumero'] ?? null) === $luisNumber) {
                return Http::response(['message' => 'Cuenta no encontrada'], 404);
            }

            return Http::response(['ok' => true, 'duplicado' => false, 'cuenta' => '1234567890', 'monto' => $request['monto'], 'saldoDespues' => $request['monto']]);
        });
        $this->postJson('/api/v1/integraciones/conexiones/banca_abonos/probar', [], $this->headers())->assertOk()->assertJsonPath('data.conectado', true);

        $source->refresh()->update(['saldo_centavos' => 20000]);
        $second = $this->postJson('/api/v1/planillas', ['nombre' => 'Quincena 2'] + $body, $this->headers())->assertCreated()->json('data.id');
        $this->postJson('/api/v1/planillas/'.$second.'/autorizar', [], $this->headers())->assertOk();
        $events = OutboxEvent::where('type', 'banca.abono')->get();
        self::assertCount(2, $events);
        self::assertSame(['CP-GESTIONES-PLANILLAS'], $events->pluck('payload.origen')->unique()->values()->all());
        self::assertCount(2, $events->pluck('payload.referencia')->unique(), 'One reference per person paid.');

        $mode = 'normal';
        app(OutboxService::class)->deliver();
        self::assertSame(['enviado'], OutboxEvent::where('type', 'banca.abono')->pluck('status')->unique()->values()->all());
        $calls = collect($calls);
        self::assertCount(3, $calls, 'Two by account number, plus Luis by DPI after the 404.');
        self::assertSame('llave-de-servicio-de-prueba-0001', $calls[0]->header('X-Service-Key')[0]);
        self::assertEqualsCanonicalizing([$anaAccount->numero, $luisNumber], $calls->map(fn ($r) => $r->data()['cuentaNumero'] ?? null)->filter()->values()->all());
        $byDpi = $calls->first(fn ($r) => isset($r->data()['dpi']));
        self::assertSame(['3000000000002', 'MONETARIA', 40.5], [$byDpi['dpi'], $byDpi['tipoCuenta'], $byDpi['monto']]);
        self::assertEqualsCanonicalizing([60, 40.5, 40.5], $calls->map(fn ($r) => $r['monto'])->all());
        self::assertStringContainsString('Planilla Quincena 2', $calls[0]['concepto']);
        $calls = $calls->all();
        app(OutboxService::class)->deliver();
        self::assertCount(3, $calls, 'Delivered events are never sent again.');

        // A company account never produces an abono; a refused abono stays failed until it is retried.
        app(InstallService::class)->fundMasterAccount();
        $this->postJson('/api/v1/caja', ['cuenta_id' => (string) $anaAccount->id, 'tipo' => 'deposito', 'monto' => '15.00', 'concepto' => 'Fondeo'], $this->headers())->assertCreated();
        $funding = OutboxEvent::where('type', 'banca.abono')->where('payload.origen', 'CP-GESTIONES-FONDEOS')->firstOrFail();
        $mode = 'bloqueada';
        app(OutboxService::class)->deliver();
        self::assertSame('fallido', $funding->refresh()->status);
        self::assertSame('BancaVirtual no aceptó el abono: Cuenta bloqueada', $funding->last_error);
        $mode = 'normal';
        $this->postJson('/api/v1/integraciones/eventos/reintentar', [], $this->headers())->assertOk()->assertJsonPath('data.reintentados', 1)->assertJsonPath('data.enviados_ahora', 1);
        self::assertSame('enviado', $funding->refresh()->status);
    }

    public function test_opened_account_is_registered_in_banca_virtual_with_the_same_number_and_its_opening_credited_once(): void
    {
        app(InstallService::class)->fundMasterAccount();
        $abonos = 'https://banca.example.test/api/v1/admin/abonos';
        $this->patchJson('/api/v1/integraciones/conexiones/banca_abonos', ['url' => $abonos, 'secreto' => 'llave-de-servicio-de-prueba-0001'], $this->headers())->assertOk();
        $client = $this->postJson('/api/v1/clientes', ['nombres' => 'Rosa', 'apellidos' => 'Méndez', 'email' => 'rosa@example.test', 'dpi' => '2468013579246', 'nit' => '24680-1', 'telefono' => '5555-0101', 'fecha_nacimiento' => '1988-04-09', 'ingreso_mensual' => '8000.00', 'antiguedad_laboral_meses' => 60], $this->headers())->assertCreated()->json('data');
        $vault = Account::where('system_key', 'concentradora')->firstOrFail()->saldo_centavos;
        $this->postJson('/api/v1/cuentas', ['cliente_id' => $client['id'], 'producto' => 'monetaria', 'monto_apertura' => '0.00'], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('monto_apertura');
        $account = $this->postJson('/api/v1/cuentas', ['cliente_id' => $client['id'], 'producto' => 'monetaria', 'monto_apertura' => '250.75'], $this->headers())->assertCreated()->json('data');
        self::assertSame($vault + 25075, Account::where('system_key', 'concentradora')->firstOrFail()->saldo_centavos, 'The opening deposit is added to the vault.');
        self::assertSame(['apertura_cuenta', 25075], [Movement::where('cuenta_id', $account['id'])->firstOrFail()->tipo, Movement::where('cuenta_id', $account['id'])->firstOrFail()->importe_centavos]);
        $events = OutboxEvent::whereIn('type', ['banca.cuenta', 'banca.abono'])->orderBy('_id')->get();
        self::assertSame(['banca.cuenta', 'banca.abono'], $events->pluck('type')->all());
        self::assertSame([$account['numero'], 'CP-GESTIONES-APERTURAS', 25075, 'CP-'.$account['apertura_transaccion_id']], [$events[1]->payload['cuenta_numero'], $events[1]->payload['origen'], $events[1]->payload['monto_centavos'], $events[1]->payload['referencia']]);

        $remote = null;
        $calls = [];
        Http::fake(function ($request) use (&$remote, &$calls) {
            $calls[] = $request;
            if (str_ends_with($request->url(), '/admin/cuentas')) {
                return Http::response(['ok' => true, 'creada' => true, 'cuenta' => ['id' => 'b1', 'numero' => $remote ?? $request['numero'], 'tipo' => $request['tipo'], 'saldo' => 0]]);
            }

            return Http::response(['ok' => true, 'duplicado' => false, 'cuenta' => $request['cuentaNumero'], 'monto' => $request['monto'], 'saldoDespues' => $request['monto']]);
        });
        app(OutboxService::class)->deliver();
        self::assertSame(['enviado', 'enviado'], OutboxEvent::whereIn('type', ['banca.cuenta', 'banca.abono'])->orderBy('_id')->pluck('status')->all());
        self::assertSame('https://banca.example.test/api/v1/admin/cuentas', $calls[0]->url(), 'The account is registered before its opening credit.');
        self::assertSame(['2468013579246', 'rosa@example.test', 'MONETARIA', $account['numero'], 'Rosa', 'Méndez'], [$calls[0]['dpi'], $calls[0]['correo'], $calls[0]['tipo'], $calls[0]['numero'], $calls[0]['nombre'], $calls[0]['apellido']]);
        self::assertSame([$account['numero'], 250.75, 'CP-'.$account['apertura_transaccion_id']], [$calls[1]['cuentaNumero'], $calls[1]['monto'], $calls[1]['referencia']]);
        self::assertSame('llave-de-servicio-de-prueba-0001', $calls[0]->header('X-Service-Key')[0]);

        // BancaVirtual already had another savings account for this DPI: the mismatch is reported, never hidden.
        $remote = '9999999999';
        $savings = $this->postJson('/api/v1/cuentas', ['cliente_id' => $client['id'], 'producto' => 'ahorro', 'monto_apertura' => '100.00'], $this->headers())->assertCreated()->json('data');
        app(OutboxService::class)->deliver();
        $event = OutboxEvent::where('type', 'banca.cuenta')->where('payload.numero', $savings['numero'])->firstOrFail();
        self::assertSame('fallido', $event->status);
        self::assertSame('BancaVirtual ya tenía otra cuenta ahorro para este DPI (número 9999999999); no registró el número '.$savings['numero'].'.', $event->last_error);
    }

    public function test_vault_receives_openings_and_deposits_and_releases_payroll(): void
    {
        app(InstallService::class)->fundMasterAccount();
        $vault = fn (): int => (int) Account::where('system_key', 'concentradora')->firstOrFail()->saldo_centavos;
        $start = $vault();
        $client = $this->postJson('/api/v1/clientes', ['nombres' => 'Lupe', 'apellidos' => 'Ramírez', 'email' => 'lupe@example.test', 'dpi' => '1231231231231', 'nit' => '123123-1', 'telefono' => '5555-0000', 'fecha_nacimiento' => '1990-01-01', 'ingreso_mensual' => '5000.00', 'antiguedad_laboral_meses' => 12], $this->headers())->assertCreated()->json('data');
        $this->postJson('/api/v1/cuentas', ['cliente_id' => $client['id'], 'producto' => 'ahorro', 'monto_apertura' => '1000.00'], $this->headers())->assertCreated();
        self::assertSame($start + 100000, $vault(), 'A personal opening deposit is added to the vault.');
        $company = $this->postJson('/api/v1/empresas', ['nombre' => 'Cevichería Prueba', 'nit' => '7788996-6', 'monto_apertura' => '200.00'], $this->headers())->assertCreated()->json('data');
        self::assertSame($start + 120000, $vault(), 'A company opening deposit is added too.');
        $companyAccount = Account::where('empresa_id', $company['id'])->firstOrFail();
        self::assertSame(20000, $companyAccount->saldo_centavos);
        $this->postJson('/api/v1/empresas/'.$company['id'].'/fondeos', ['cuenta_id' => (string) $companyAccount->id, 'tipo' => 'deposito', 'monto' => '5000.00', 'concepto' => 'Depósito para planillas'], $this->headers())->assertCreated();
        self::assertSame($start + 620000, $vault(), 'The company deposit is added to the vault.');
        $this->postJson('/api/v1/empresas/'.$company['id'].'/cuentas', ['producto' => 'ahorro', 'monto_apertura' => '50.00'], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('monto_apertura');

        $employee = Account::where('cliente_id', $client['id'])->firstOrFail();
        $payroll = $this->postJson('/api/v1/planillas', ['empresa_id' => $company['id'], 'nombre' => 'Quincena', 'pagos' => [['nombre' => 'Lupe', 'cuenta_numero' => $employee->numero, 'monto' => '1500.00']]], $this->headers())->assertCreated()->json('data.id');
        $this->postJson('/api/v1/planillas/'.$payroll.'/autorizar', [], $this->headers())->assertOk();
        self::assertSame($start + 470000, $vault(), 'Paying the payroll releases its total from the vault.');
        self::assertSame(0, (int) Account::sum('saldo_centavos'), 'Every balance still adds up to zero.');
        self::assertSame(250000, $employee->refresh()->saldo_centavos);
    }

    public function test_customer_is_always_deleted_after_a_warning_about_balance_and_debts(): void
    {
        app(InstallService::class)->fundMasterAccount();
        $vault = fn (): int => (int) Account::where('system_key', 'concentradora')->firstOrFail()->saldo_centavos;
        $rich = $this->customer('rich');
        $rich->update(['dpi' => '1112223334445']);
        $account = $this->account($rich, 0);
        $this->postJson('/api/v1/caja', ['cuenta_id' => (string) $account->id, 'tipo' => 'deposito', 'monto' => '300.00', 'concepto' => 'Depósito'], $this->headers())->assertCreated();
        Card::create(['cliente_id' => (string) $rich->id, 'solicitud_id' => 'del', 'referencia' => 'CPDEL', 'tipo' => 'credito', 'estado' => 'activa', 'limite_centavos' => 100000, 'principal_centavos' => 4000, 'interes_centavos' => 100, 'emitida_el' => '2026-10-01', 'policy' => ['cutoff_day' => 5, 'payment_day' => 25, 'annual_bps' => 0]]);
        Credit::create(['cliente_id' => (string) $rich->id, 'referencia' => 'PR-DEL', 'saldo_centavos' => 50000, 'estado' => 'vigente']);
        CardApplication::create(['cliente_id' => (string) $rich->id, 'folio' => 'GES-DEL', 'producto' => 'credito-clasica', 'estado' => 'emitida', 'version' => 1]);

        $summary = $this->getJson('/api/v1/clientes/'.$rich->id.'/eliminacion', $this->headers())->assertOk()->json('data');
        self::assertSame([true, 30000, 50000, 4100, 1, 1], [$summary['tiene_saldo_o_deuda'], $summary['saldo_centavos'], $summary['deuda_prestamos_centavos'], $summary['deuda_tarjetas_centavos'], $summary['prestamos_activos'], $summary['tarjetas']]);
        $this->getJson('/api/v1/clientes/'.$rich->id.'/eliminacion', $this->headers($this->token($this->user('operador', 'ops-del'))))->assertForbidden();
        $before = $vault();
        $this->postJson('/api/v1/clientes/'.$rich->id.'/eliminar', ['nota' => 'Solicitud del cliente'], $this->headers())->assertOk()->assertJsonPath('data.eliminado', true);
        self::assertNull(Customer::find($rich->id));
        self::assertSame(0, Account::where('cliente_id', (string) $rich->id)->count() + Card::where('cliente_id', (string) $rich->id)->count() + Credit::where('cliente_id', (string) $rich->id)->count() + CardApplication::where('cliente_id', (string) $rich->id)->count());
        self::assertSame($before - 30000, $vault(), 'Closing the accounts: the holder takes the balance, it leaves the vault.');
        self::assertSame(0, (int) Account::sum('saldo_centavos'));
        self::assertSame(0, Movement::where('cuenta_id', (string) $account->id)->count(), 'The customer movements are deleted too.');
        self::assertSame(0, SecurityEvent::where('detail.cliente_id', (string) $rich->id)->count());
        self::assertArrayNotHasKey('nombre', SecurityEvent::where('action', 'cliente_eliminado')->firstOrFail()->detail);
        // The same person can be registered again with the same DPI and e-mail.
        $again = ['nombres' => 'Cliente', 'apellidos' => 'Rich', 'email' => 'rich@example.test', 'dpi' => $rich->dpi, 'nit' => '1234567-8', 'telefono' => '5555-1111', 'fecha_nacimiento' => '1990-01-01', 'ingreso_mensual' => '1000.00', 'antiguedad_laboral_meses' => 12];
        $this->postJson('/api/v1/clientes', $again, $this->headers())->assertCreated();
        $this->postJson('/api/v1/clientes', $again, $this->headers())->assertConflict();

        $empty = $this->customer('empty');
        $this->getJson('/api/v1/clientes/'.$empty->id.'/eliminacion', $this->headers())->assertOk()->assertJsonPath('data.tiene_saldo_o_deuda', false);
        $this->postJson('/api/v1/clientes/'.$empty->id.'/eliminar', [], $this->headers())->assertOk();
        self::assertNull(Customer::find($empty->id));
    }

    public function test_purchase_authorization_checks_available_credit_and_logs_every_attempt(): void
    {
        app(InstallService::class)->fundMasterAccount();
        $c = $this->customer('buyer');
        $c->update(['dpi' => '5432154321543']);
        $card = Card::create(['cliente_id' => (string) $c->id, 'solicitud_id' => 'buy', 'referencia' => 'CPBUY', 'ultimos_cuatro' => '4321', 'tipo' => 'credito', 'estado' => 'activa', 'limite_centavos' => 100000, 'principal_centavos' => 0, 'interes_centavos' => 0, 'emitida_el' => '2026-10-01', 'policy' => ['cutoff_day' => 5, 'payment_day' => 25, 'annual_bps' => 0]]);
        $bank = app(AuthService::class)->issueService('banca');
        $url = '/api/v1/tarjetas/'.$card->id.'/autorizaciones';
        $buy = ['dpi' => '5432154321543', 'monto' => '600.00', 'comercio' => 'Super La Torre', 'concepto' => 'Despensa'];
        $approved = $this->postJson($url, $buy, $this->headers($bank, 'purchase-001'))->assertCreated()->assertJsonPath('data.estado', 'aprobada')->assertJsonPath('data.codigo', 'APROBADA')
            ->assertJsonPath('data.disponible_centavos', 40000)->json('data');
        $this->postJson($url, $buy, $this->headers($bank, 'purchase-001'))->assertCreated()->assertJsonPath('data.id', $approved['id']);
        $this->postJson($url, ['monto' => '400.01'] + $buy, $this->headers($bank, 'purchase-002'))->assertUnprocessable()->assertJsonPath('code', 'LIMITE_EXCEDIDO')
            ->assertJsonPath('message', 'La compra supera el crédito disponible de la tarjeta.')->assertJsonPath('data.estado', 'rechazada');
        $this->postJson($url, ['dpi' => '0000000000000'] + $buy, $this->headers($bank, 'purchase-003'))->assertNotFound();
        $card->update(['estado' => 'bloqueada']);
        $this->postJson($url, ['monto' => '1.00'] + $buy, $this->headers(null, 'purchase-004'))->assertUnprocessable()->assertJsonPath('code', 'TARJETA_BLOQUEADA');
        self::assertSame(60000, $card->refresh()->principal_centavos, 'Only the approved purchase was charged.');
        $log = $this->getJson('/api/v1/tarjetas/'.$card->id.'/compras?dpi=5432154321543', $this->headers($bank))->assertOk()->json('data.data');
        self::assertSame(['rechazada', 'rechazada', 'aprobada'], array_column($log, 'estado'));
        self::assertSame(['TARJETA_BLOQUEADA', 'LIMITE_EXCEDIDO', 'APROBADA'], array_column($log, 'codigo'));
        self::assertSame(['Super La Torre', 40001, 'banca_virtual'], [$log[1]['comercio'], $log[1]['monto_centavos'], $log[1]['canal']]);
        $this->getJson('/api/v1/compras-tarjeta?estado=rechazada', $this->headers())->assertOk()->assertJsonPath('data.total', 2);
        $this->getJson('/api/v1/compras-tarjeta', $this->headers($bank))->assertForbidden();

        $debit = Card::create(['cliente_id' => (string) $c->id, 'solicitud_id' => 'deb', 'referencia' => 'CPDEB', 'tipo' => 'debito', 'estado' => 'activa', 'cuenta_id' => (string) $this->account($c, 5000)->id]);
        $this->postJson('/api/v1/tarjetas/'.$debit->id.'/autorizaciones', ['monto' => '50.01'] + $buy, $this->headers($bank, 'purchase-005'))->assertUnprocessable()->assertJsonPath('code', 'FONDOS_INSUFICIENTES');
        $this->postJson('/api/v1/tarjetas/'.$debit->id.'/autorizaciones', ['monto' => '50.00'] + $buy, $this->headers($bank, 'purchase-006'))->assertCreated()->assertJsonPath('data.disponible_centavos', 0);
    }

    public function test_billing_cycle_of_credit_cards_reaches_banca_virtual(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00:00', 'America/Guatemala'));
        $c = $this->customer('cycle');
        $c->update(['dpi' => '6543265432654']);
        $card = Card::create(['cliente_id' => (string) $c->id, 'solicitud_id' => 'cyc', 'referencia' => 'CPCYC', 'ultimos_cuatro' => '9876', 'producto' => 'credito-clasica', 'tipo' => 'credito', 'estado' => 'activa', 'limite_centavos' => 500000, 'principal_centavos' => 12000, 'interes_centavos' => 0, 'cargo_automatico' => true, 'emitida_el' => '2026-09-01', 'policy' => ['cutoff_day' => 5, 'payment_day' => 25, 'annual_bps' => 0]]);
        $bank = app(AuthService::class)->issueService('banca');
        $cycles = $this->getJson('/api/v1/banca/tarjetas/ciclos?dpi=6543265432654', $this->headers($bank))->assertOk()->json('data');
        self::assertSame([5, 25, '2026-11-05', '2026-11-25', 12000, 488000], [$cycles[0]['dia_corte'], $cycles[0]['dia_pago'], $cycles[0]['fecha_corte'], $cycles[0]['fecha_limite_pago'], $cycles[0]['deuda_centavos'], $cycles[0]['disponible_centavos']]);
        Statement::create(['tarjeta_id' => (string) $card->id, 'periodo' => '2026-10', 'fecha_corte' => '2026-10-05', 'fecha_pago' => '2026-10-25', 'principal_centavos' => 12000, 'interes_centavos' => 0, 'saldo_centavos' => 12000, 'minimo_centavos' => 1000, 'pagado_centavos' => 0, 'abonado_centavos' => 0]);
        $this->getJson('/api/v1/tarjetas/'.$card->id.'/ciclo?dpi=6543265432654', $this->headers($bank))->assertOk()
            ->assertJsonPath('data.fecha_limite_pago', '2026-10-25')->assertJsonPath('data.pago_pendiente.minimo_centavos', 1000)->assertJsonPath('data.fecha_corte', '2026-11-05');
        $this->getJson('/api/v1/tarjetas?dpi=6543265432654', $this->headers($bank))->assertOk()->assertJsonPath('data.data.0.ciclo.fecha_limite_pago', '2026-10-25');
        $this->getJson('/api/v1/banca/tarjetas/ciclos', $this->headers($bank))->assertUnprocessable();
        $this->travelBack();
    }

    public function test_company_is_deleted_with_its_accounts_payrolls_and_users_but_not_its_employees(): void
    {
        app(InstallService::class)->fundMasterAccount();
        $company = $this->postJson('/api/v1/empresas', ['nombre' => 'Borrar S.A.', 'nit' => '5566778-9', 'monto_apertura' => '500.00', 'representante_nombre' => 'Rep', 'representante_correo' => 'rep@borrar.test', 'contrasena' => 'EmpresaSegura2026'], $this->headers())->assertCreated()->json('data');
        $employee = $this->customer('empleado');
        $employeeAccount = $this->account($employee, 0);
        $companyAccount = Account::where('empresa_id', $company['id'])->firstOrFail();
        $payroll = $this->postJson('/api/v1/planillas', ['empresa_id' => $company['id'], 'nombre' => 'Única', 'pagos' => [['nombre' => 'Empleado', 'cuenta_numero' => $employeeAccount->numero, 'monto' => '100.00']]], $this->headers())->json('data.id');
        $this->postJson('/api/v1/planillas/'.$payroll.'/autorizar', [], $this->headers())->assertOk();
        $vault = fn (): int => (int) Account::where('system_key', 'concentradora')->firstOrFail()->saldo_centavos;
        $before = $vault();
        $this->getJson('/api/v1/empresas/'.$company['id'].'/eliminacion', $this->headers())->assertOk()->assertJsonPath('data.saldo_centavos', 40000)->assertJsonPath('data.usuarios', 1);
        $this->postJson('/api/v1/empresas/'.$company['id'].'/eliminar', [], $this->headers())->assertOk()->assertJsonPath('data.eliminada', true);
        self::assertNull(Company::find($company['id']));
        self::assertSame(0, Account::where('empresa_id', $company['id'])->count() + Payroll::where('empresa_id', $company['id'])->count() + User::where('empresa_id', $company['id'])->count());
        self::assertSame(0, Movement::where('cuenta_id', (string) $companyAccount->id)->count());
        self::assertSame($before - 40000, $vault(), 'Closing the company accounts: the balance leaves the vault.');
        self::assertSame(10000, $employeeAccount->refresh()->saldo_centavos, 'The employee keeps their money and history.');
        self::assertSame(0, (int) Account::sum('saldo_centavos'));
        $this->getJson('/api/v1/empresas', $this->headers())->assertOk()->assertJsonCount(0, 'data.data');
        $this->postJson('/api/v1/empresas', ['nombre' => 'Borrar S.A.', 'nit' => '5566778-9', 'representante_nombre' => 'Rep', 'representante_correo' => 'rep@borrar.test', 'contrasena' => 'EmpresaSegura2026'], $this->headers())->assertCreated();
    }

    public function test_administrator_adds_funds_to_the_vault_once_per_request(): void
    {
        app(InstallService::class)->fundMasterAccount();
        $start = $this->getJson('/api/v1/concentradora', $this->headers())->assertOk()->json('data.saldo_centavos');
        $this->postJson('/api/v1/concentradora/ingresos', ['monto' => '0.00', 'concepto' => 'Cero'], $this->headers())->assertUnprocessable();
        $this->postJson('/api/v1/concentradora/ingresos', ['monto' => '-5.00', 'concepto' => 'Negativo'], $this->headers())->assertUnprocessable();
        $body = ['monto' => '250000.50', 'concepto' => 'Aporte de capital octubre'];
        $done = $this->postJson('/api/v1/concentradora/ingresos', $body, $this->headers(null, 'vault-deposit-1'))->assertCreated()->json('data');
        self::assertSame([$start, $start + 25000050, 'Aporte de capital octubre', 'admin'], [$done['saldo_anterior_centavos'], $done['saldo_centavos'], $done['concepto'], $done['responsable_nombre']]);
        $this->postJson('/api/v1/concentradora/ingresos', $body, $this->headers(null, 'vault-deposit-1'))->assertCreated();
        $this->postJson('/api/v1/concentradora/ingresos', $body, $this->headers(null, 'vault-deposit-1'))->assertCreated();
        self::assertSame($start + 25000050, $this->getJson('/api/v1/concentradora', $this->headers())->json('data.saldo_centavos'), 'Repeated clicks with the same key add it once.');
        $movement = Movement::where('tipo', 'ingreso_concentradora')->where('importe_centavos', 25000050)->firstOrFail();
        self::assertSame(['Aporte de capital octubre', (string) $this->admin->id], [$movement->concepto, $movement->actor]);
        self::assertSame(1, Movement::where('tipo', 'ingreso_concentradora')->where('importe_centavos', 25000050)->count());
        $this->postJson('/api/v1/concentradora/ingresos', $body, $this->headers($this->token($this->user('operador', 'ops-vault'))))->assertForbidden();
    }

    public function test_full_reset_leaves_products_staff_and_integrations_and_allows_registering_again(): void
    {
        app(InstallService::class)->fundMasterAccount();
        $this->patchJson('/api/v1/integraciones/conexiones/banca_abonos', ['url' => 'https://banca.example.test/api/v1/admin/abonos', 'secreto' => 'llave-de-servicio-de-prueba-0001'], $this->headers())->assertOk();
        $client = ['nombres' => 'Reset', 'apellidos' => 'Prueba', 'email' => 'reset@example.test', 'dpi' => '7897897897897', 'nit' => '789789-7', 'telefono' => '5555-7777', 'fecha_nacimiento' => '1990-01-01', 'ingreso_mensual' => '3000.00', 'antiguedad_laboral_meses' => 6];
        $created = $this->postJson('/api/v1/clientes', $client, $this->headers())->assertCreated()->json('data');
        $this->postJson('/api/v1/cuentas', ['cliente_id' => $created['id'], 'producto' => 'ahorro', 'monto_apertura' => '500.00'], $this->headers())->assertCreated();
        $this->postJson('/api/v1/empresas', ['nombre' => 'Reset S.A.', 'nit' => '1112223-4', 'representante_nombre' => 'Rep', 'representante_correo' => 'rep@reset.test', 'contrasena' => 'EmpresaSegura2026'], $this->headers())->assertCreated();
        $products = Product::count();
        $staff = User::where('role', '!=', 'empresa')->count();

        $this->postJson('/api/v1/mantenimiento/limpieza-total', ['confirmacion' => 'borrar'], $this->headers())->assertUnprocessable();
        $this->postJson('/api/v1/mantenimiento/limpieza-total', ['confirmacion' => 'ELIMINAR TODO'], $this->headers($this->token($this->user('administrador', 'second-admin'))))->assertForbidden();
        $this->admin->refresh()->update(['protegido' => true]);
        $reset = $this->postJson('/api/v1/mantenimiento/limpieza-total', ['confirmacion' => 'ELIMINAR TODO'], $this->headers());
        $reset->assertOk()->assertJsonPath('data.eliminados.clientes', 1);
        self::assertSame([0, 0, 0, 0, 0], [Customer::count(), Company::count(), Account::whereNull('system_key')->count(), Payroll::count(), User::where('role', 'empresa')->count()]);
        self::assertSame([$products, $staff + 1], [Product::count(), User::where('role', '!=', 'empresa')->count()]);
        self::assertSame((int) config('operations.master_account_opening_cents'), (int) Account::where('system_key', 'concentradora')->firstOrFail()->saldo_centavos, 'The vault opens again with the initial funds.');
        self::assertSame(0, (int) Account::where('system_key', 'efectivo')->firstOrFail()->saldo_centavos);
        self::assertSame(0, (int) Account::sum('saldo_centavos'));
        self::assertSame(1, OutboxEvent::where('type', 'banca.cliente_eliminado')->count(), 'Banca Virtual is asked to delete its copy.');
        self::assertNotNull(IntegrationSetting::first());
        $again = $this->postJson('/api/v1/clientes', $client, $this->headers())->assertCreated()->json('data');
        $this->postJson('/api/v1/cuentas', ['cliente_id' => $again['id'], 'producto' => 'ahorro', 'monto_apertura' => '100.00'], $this->headers())->assertCreated();
    }

    public function test_leftovers_of_deleted_customers_are_purged_without_touching_others(): void
    {
        $kept = $this->customer('kept');
        $keptAccount = $this->account($kept, 500);
        Movement::create(['cuenta_id' => (string) $keptAccount->id, 'importe_centavos' => 500, 'tipo' => 'deposito']);
        $gone = Account::create(['cliente_id' => '0123456789abcdef01234567', 'numero' => 'CPORPHAN', 'tipo' => 'ahorro', 'estado' => 'activa', 'moneda' => 'GTQ', 'saldo_centavos' => 0]);
        Movement::create(['cuenta_id' => (string) $gone->id, 'importe_centavos' => 100, 'tipo' => 'deposito']);
        Movement::create(['cuenta_id' => 'abcdefabcdefabcdefabcdef', 'importe_centavos' => 100, 'tipo' => 'deposito', 'titular_eliminado' => 'Antiguo']);
        Card::create(['cliente_id' => '0123456789abcdef01234567', 'referencia' => 'CPOLD', 'tipo' => 'credito', 'estado' => 'activa']);
        $result = app(MaintenanceService::class)->purgeOrphans();
        self::assertSame([1, 1], [$result['cuentas'], $result['tarjetas']]);
        self::assertNull(Account::find($gone->id));
        self::assertSame(1, Movement::count(), 'Only the movement of the existing customer remains.');
        self::assertNotNull(Account::find($keptAccount->id));
        self::assertSame(0, app(MaintenanceService::class)->purgeOrphans()['cuentas']);
    }

    public function test_products_and_policies_are_read_only(): void
    {
        $product = Product::where('code', 'credito-oro')->firstOrFail();
        $this->patchJson('/api/v1/productos/'.$product->id, ['name' => 'Otro', 'active' => true, 'annual_bps' => 1, 'minimum_bps' => 1, 'minimum_floor_cents' => 1, 'cutoff_day' => 1, 'payment_days' => 1], $this->headers())->assertNotFound();
        $this->getJson('/api/v1/productos', $this->headers())->assertOk()->assertJsonPath('data.total', Product::count());
        self::assertSame('Tarjeta de crédito Oro', $product->refresh()->name);
    }

    public function test_banca_virtual_is_asked_to_delete_its_copy_and_the_dpi_is_not_kept(): void
    {
        $this->patchJson('/api/v1/integraciones/conexiones/banca_abonos', ['url' => 'https://banca.example.test/api/v1/admin/abonos', 'secreto' => 'llave-de-servicio-de-prueba-0001'], $this->headers())->assertOk();
        $customer = $this->customer('banca-delete');
        $customer->update(['dpi' => '9998887776665', 'email' => 'Borrar@Example.test']);
        $this->postJson('/api/v1/clientes/'.$customer->id.'/eliminar', [], $this->headers())->assertOk();
        $calls = [];
        Http::fake(function ($request) use (&$calls) {
            $calls[] = $request;

            return Http::response(['ok' => true, 'eliminado' => true]);
        });
        app(OutboxService::class)->deliver();
        self::assertSame('https://banca.example.test/api/v1/admin/clientes/eliminar', $calls[0]->url());
        self::assertSame(['9998887776665', 'borrar@example.test', 'llave-de-servicio-de-prueba-0001'], [$calls[0]['dpi'], $calls[0]['correo'], $calls[0]->header('X-Service-Key')[0]]);
        $event = OutboxEvent::where('type', 'banca.cliente_eliminado')->firstOrFail();
        self::assertSame(['enviado', []], [$event->status, (array) $event->payload]);
    }

    /**
     * QA case: limit Q10,000. The purchases of the report (5,000 + 10,000 + 1,000 + 3,000) can never exceed it;
     * once the limit is used up nothing else is approved until a payment frees credit.
     */
    public function test_credit_limit_is_a_hard_stop_until_a_payment_frees_credit(): void
    {
        app(InstallService::class)->fundMasterAccount();
        $c = $this->customer('qa-limit');
        $c->update(['dpi' => '3068226670601']);
        $account = Account::create(['cliente_id' => (string) $c->id, 'numero' => '5356242050', 'tipo' => 'ahorro', 'estado' => 'activa', 'moneda' => 'GTQ', 'saldo_centavos' => 200000, 'tasa_bps' => 0]);
        $card = Card::create(['cliente_id' => (string) $c->id, 'solicitud_id' => 'qa', 'referencia' => 'CP-B6F0DD1DCFBE', 'ultimos_cuatro' => '5811', 'tipo' => 'credito', 'estado' => 'activa', 'limite_centavos' => 1000000, 'principal_centavos' => 0, 'interes_centavos' => 0, 'cuenta_id' => (string) $account->id, 'emitida_el' => '2026-10-01', 'policy' => ['cutoff_day' => 10, 'payment_day' => 25, 'annual_bps' => 0]]);
        $bank = app(AuthService::class)->issueService('banca');
        $buy = fn (string $amount, string $merchant) => $this->postJson('/api/v1/tarjetas/'.$card->id.'/autorizaciones', ['dpi' => '3068226670601', 'monto' => $amount, 'comercio' => $merchant], $this->headers($bank));

        $buy('5000.00', 'Agencias Way')->assertCreated()->assertJsonPath('data.disponible_centavos', 500000);
        $buy('10000.00', 'Fernando')->assertUnprocessable()->assertJsonPath('code', 'LIMITE_EXCEDIDO');
        $buy('1000.00', 'Campero')->assertCreated();
        $buy('3000.00', 'TacoBell')->assertCreated()->assertJsonPath('data.disponible_centavos', 100000);
        $buy('1000.01', 'Excede por un centavo')->assertUnprocessable()->assertJsonPath('code', 'LIMITE_EXCEDIDO');
        $buy('1000.00', 'Usa el último crédito')->assertCreated()->assertJsonPath('data.disponible_centavos', 0);
        $buy('0.01', 'Sin crédito')->assertUnprocessable()->assertJsonPath('code', 'LIMITE_EXCEDIDO');
        // The older staff endpoint is held to the same hard stop.
        $this->postJson('/api/v1/tarjetas/'.$card->id.'/consumos', ['monto' => '0.01', 'concepto' => 'Consumo directo'], $this->headers())->assertUnprocessable()->assertJsonPath('code', 'LIMITE_EXCEDIDO');
        self::assertSame(1000000, $card->refresh()->principal_centavos, 'The debt never goes past the limit.');

        // Banca Virtual pays with the account number (the same in both systems): that frees credit.
        $this->postJson('/api/v1/tarjetas/'.$card->id.'/pagos', ['dpi' => '3068226670601', 'monto' => '500.00', 'origen_numero' => '5356242050'], $this->headers($bank))->assertCreated();
        self::assertSame([950000, 150000], [$card->refresh()->principal_centavos, $account->refresh()->saldo_centavos]);
        $buy('500.01', 'Más que lo liberado')->assertUnprocessable()->assertJsonPath('code', 'LIMITE_EXCEDIDO');
        $buy('500.00', 'Lo liberado')->assertCreated()->assertJsonPath('data.disponible_centavos', 0);
        $this->postJson('/api/v1/tarjetas/'.$card->id.'/pagos', ['dpi' => '3068226670601', 'monto' => '1.00', 'origen_numero' => '9999999999'], $this->headers($bank))->assertNotFound();
        self::assertSame(['aprobada' => 5, 'rechazada' => 4], CardPurchase::where('tarjeta_id', (string) $card->id)->get()->countBy('estado')->sortKeys()->all());
    }

    public function test_vault_accepts_a_large_deposit_from_the_bank_capital(): void
    {
        app(InstallService::class)->fundMasterAccount();
        $start = Account::where('system_key', 'concentradora')->firstOrFail()->saldo_centavos;
        $this->postJson('/api/v1/concentradora/ingresos', ['monto' => '1000000000.00', 'concepto' => 'Aporte de capital de inversionistas'], $this->headers())->assertCreated()
            ->assertJsonPath('data.saldo_centavos', $start + 100000000000);
        $this->postJson('/api/v1/concentradora/ingresos', ['monto' => '1000000000.01', 'concepto' => 'Más del máximo por operación'], $this->headers())->assertUnprocessable();
        self::assertSame(0, (int) Account::sum('saldo_centavos'));
    }

    public function test_closing_an_account_hands_the_balance_to_the_customer_and_subtracts_it_from_the_vault(): void
    {
        app(InstallService::class)->fundMasterAccount();
        $vault = fn (): int => (int) Account::where('system_key', 'concentradora')->firstOrFail()->saldo_centavos;
        $c = $this->customer('close');
        $account = Account::create(['cliente_id' => (string) $c->id, 'numero' => '7777777777', 'tipo' => 'ahorro', 'estado' => 'activa', 'moneda' => 'GTQ', 'saldo_centavos' => 0, 'tasa_bps' => 0]);
        $this->postJson('/api/v1/caja', ['cuenta_id' => (string) $account->id, 'tipo' => 'deposito', 'monto' => '300.00', 'concepto' => 'Depósito'], $this->headers())->assertCreated();
        $before = $vault();
        $this->patchJson('/api/v1/cuentas/'.$account->id.'/estado', ['estado' => 'cerrada', 'nota' => 'Cierre solicitado por el cliente.'], $this->headers())->assertOk()
            ->assertJsonPath('data.estado', 'cerrada')->assertJsonPath('data.saldo_retirado_centavos', 30000);
        self::assertSame([0, 'cerrada'], [$account->refresh()->saldo_centavos, $account->estado]);
        self::assertSame($before - 30000, $vault(), 'The customer takes the money: it leaves the vault.');
        self::assertSame(0, (int) Account::sum('saldo_centavos'));
        $this->patchJson('/api/v1/cuentas/'.$account->id.'/estado', ['estado' => 'activa', 'nota' => 'Intento de reabrir.'], $this->headers())->assertConflict()->assertJsonPath('code', 'ACCOUNT_CLOSED');
        $this->postJson('/api/v1/caja', ['cuenta_id' => (string) $account->id, 'tipo' => 'deposito', 'monto' => '1.00', 'concepto' => 'Después del cierre'], $this->headers())->assertConflict();
    }

    public function test_liquidating_a_loan_returns_the_capital_to_the_vault(): void
    {
        app(InstallService::class)->fundMasterAccount();
        $vault = fn (): int => (int) Account::where('system_key', 'concentradora')->firstOrFail()->saldo_centavos;
        $start = $vault();
        $customer = Customer::create(['nombre' => 'Cliente liquidación', 'dpi' => '5555666677770', 'email' => 'liquida@example.test', 'active' => true]);
        $account = $this->account($customer, 0);
        $app = app(IdempotencyService::class)->run((string) $this->admin->id, 'create-loan-liq', 'application', [], fn () => app(ApplicationService::class)->create(['cliente_id' => (string) $customer->id, 'producto' => 'credito-personal', 'limite_solicitado' => '12000.00', 'origen' => 'banca_virtual'], 'service:banca', preEvaluated: true));
        $this->postJson('/api/v1/solicitudes/'.$app['id'].'/transiciones', ['version' => 1, 'estado' => 'emitida', 'limite_asignado' => '12000.00', 'nota' => 'Préstamo aprobado', 'plazo_meses' => 3, 'tasa_anual' => '0', 'dia_pago' => 28, 'cuenta_id' => (string) $account->id], $this->headers())->assertOk();
        self::assertSame($start - 1200000, $vault(), 'The loan was disbursed from the vault.');
        $credit = Credit::firstOrFail();
        $pending = $this->getJson('/api/v1/creditos/'.$credit->id, $this->headers())->assertOk()->json('data.pendiente_total_centavos');
        $this->postJson('/api/v1/creditos/'.$credit->id.'/pagos', ['origen_id' => (string) $account->id, 'monto' => Money::format($pending)], $this->headers())->assertCreated();
        self::assertSame('pagado', $credit->refresh()->estado);
        self::assertSame($start, $vault(), 'Liquidating the loan returns the capital to the vault.');
    }

    public function test_staff_edit_the_customer_file_keeping_dpi_and_email_unique(): void
    {
        $base = ['nombres' => 'Estefani', 'apellidos' => 'Montes', 'email' => 'emontes@example.test', 'dpi' => '3000000002323', 'nit' => '775566443', 'telefono' => '56451236', 'fecha_nacimiento' => '2001-03-02', 'ingreso_mensual' => '6000.00', 'antiguedad_laboral_meses' => 18];
        $client = $this->postJson('/api/v1/clientes', $base, $this->headers())->assertCreated()->json('data');
        $this->postJson('/api/v1/clientes', ['email' => 'otra@example.test', 'dpi' => '3000000009999'] + $base, $this->headers())->assertCreated();
        $url = '/api/v1/clientes/'.$client['id'];

        // New income and phone, and a typo in the last name.
        $this->patchJson($url, ['ingreso_mensual' => '7500.50', 'telefono' => '88556633', 'apellidos' => 'Montes  López'], $this->headers())->assertOk()
            ->assertJsonPath('data.ingreso_centavos', 750050)->assertJsonPath('data.telefono', '88556633')->assertJsonPath('data.nombre', 'Estefani Montes López')
            ->assertJsonPath('data.campos_actualizados', ['apellidos', 'telefono', 'ingreso_centavos']);
        self::assertSame(2, Customer::findOrFail($client['id'])->perfil_version);
        // Nothing changed: nothing is written.
        $this->patchJson($url, ['telefono' => '88556633'], $this->headers())->assertOk()->assertJsonPath('data.campos_actualizados', []);
        // A wrong DPI typed at the branch can be fixed, but never to another customer's DPI or e-mail.
        $this->patchJson($url, ['dpi' => '3000000009999'], $this->headers())->assertConflict()->assertJsonPath('code', 'CUSTOMER_EXISTS');
        $this->patchJson($url, ['email' => 'OTRA@example.test'], $this->headers())->assertConflict()->assertJsonPath('code', 'CUSTOMER_EXISTS');
        $this->patchJson($url, ['dpi' => '3000000002324', 'email' => 'estefani@example.test'], $this->headers())->assertOk();
        self::assertSame(['3000000002324', 'estefani@example.test'], [Customer::findOrFail($client['id'])->dpi, Customer::findOrFail($client['id'])->email]);
        // Same rules as when registering.
        $this->patchJson($url, ['fecha_nacimiento' => '2015-01-01'], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('fecha_nacimiento');
        $this->patchJson($url, ['antiguedad_laboral_meses' => 400], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('antiguedad_laboral_meses');
        $this->patchJson($url, ['ingreso_mensual' => '-1'], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('ingreso_mensual');

        // Once the customer uses Banca Virtual, DPI and e-mail are their sign-in data there.
        Customer::findOrFail($client['id'])->update(['password' => Hash::make('clave2026')]);
        // The e-mail can change (they sign in with the new one); the DPI cannot.
        $this->patchJson($url, ['email' => 'Nuevo@Example.test'], $this->headers())->assertOk()->assertJsonPath('data.email', 'nuevo@example.test');
        $this->postJson('/api/v1/banca/auth/login', ['correo_electronico' => 'nuevo@example.test', 'contrasena' => 'clave2026'], $this->headers(app(AuthService::class)->issueService('banca')))->assertOk()
            ->assertJsonPath('data.cliente.dpi', '3000000002324');
        $this->patchJson($url, ['dpi' => '3000000002325'], $this->headers())->assertConflict()->assertJsonPath('code', 'APP_IDENTITY_LOCKED');
        $this->patchJson($url, ['ingreso_mensual' => '8000.00'], $this->headers())->assertOk()->assertJsonPath('data.ingreso_centavos', 800000);
        $this->patchJson($url, ['telefono' => '55555555'], $this->headers($this->token($this->user('operador', 'ops-edit'))))->assertForbidden();
    }
}
