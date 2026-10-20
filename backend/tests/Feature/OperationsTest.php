<?php

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\AccessToken;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Card;
use App\Models\CardApplication;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DailyAccrual;
use App\Models\IdempotencyRecord;
use App\Models\Movement;
use App\Models\OutboxEvent;
use App\Models\Payroll;
use App\Models\SecurityEvent;
use App\Models\Statement;
use App\Models\User;
use App\Services\ApplicationService;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\CardService;
use App\Services\IdempotencyService;
use App\Services\InstallService;
use App\Services\OutboxService;
use App\Services\PayrollService;
use App\Services\SavingsService;
use App\Support\Money;
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
        $cashier = $this->user('cajero', 'cashier');
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
        $cashier = $this->user('cajero', 'cashier');
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
        $other = $this->account($this->customer('b'), 0);
        $other->update(['numero' => '2738729879']);
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
        $app = $this->application($this->customer());
        $id = $app['id'];
        $decision = ['resultado' => 'aprobada', 'version' => 1, 'limite_aprobado' => '800.00', 'referencia' => 'risk-1', 'motivo' => 'Resultado emitido por el motor'];
        $this->postJson('/api/v1/solicitudes/'.$id.'/decision-riesgo', $decision, $this->headers())->assertForbidden();
        $risk = app(AuthService::class)->issueService('riesgos');
        $this->postJson('/api/v1/solicitudes/'.$id.'/decision-riesgo', $decision, $this->headers($risk))->assertOk();
        $this->postJson('/api/v1/solicitudes/'.$id.'/transiciones', ['version' => 2, 'estado' => 'emitida', 'nota' => 'Intento de saltar etapas'], $this->headers())->assertConflict();
        $this->postJson('/api/v1/solicitudes/'.$id.'/transiciones', ['version' => 2, 'estado' => 'aceptada', 'nota' => 'Aceptación registrada', 'referencia_aceptacion' => 'consentimiento-001'], $this->headers())->assertOk();
        $this->postJson('/api/v1/solicitudes/'.$id.'/transiciones', ['version' => 3, 'estado' => 'en_tramite', 'nota' => 'Preparación de la tarjeta'], $this->headers())->assertOk();
        $header = $this->headers(key: 'emit-only-once');
        $body = ['version' => 4, 'estado' => 'emitida', 'limite_asignado' => '750.00', 'nota' => 'Lista para entrega en agencia'];
        $this->postJson('/api/v1/solicitudes/'.$id.'/transiciones', $body, $header)->assertOk();
        $this->postJson('/api/v1/solicitudes/'.$id.'/transiciones', $body, $header)->assertOk();
        self::assertSame(1, Card::count());
        self::assertSame(75000, Card::first()->limite_centavos);
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

    public function test_payroll_cannot_be_self_approved_and_failure_rolls_back_all_beneficiaries(): void
    {
        $operator = $this->user('empresa_operador', 'operator');
        $approver = $this->user('empresa_aprobador', 'approver');
        $source = $this->account($this->customer('company'), 10000);
        $a = $this->account($this->customer('employee1'), 0);
        $b = $this->account($this->customer('employee2'), 0);
        $company = Company::create(['nombre' => 'Empresa Test', 'nit' => 'EMP-1', 'cuenta_id' => (string) $source->id, 'miembros' => [(string) $operator->id, (string) $approver->id]]);
        $body = ['empresa_id' => (string) $company->id, 'nombre' => 'Planilla Test', 'pagos' => [['nombre' => 'Uno', 'cuenta_numero' => $a->numero, 'monto' => '60.00'], ['nombre' => 'Dos', 'cuenta_numero' => $b->numero, 'monto' => '60.00']]];
        $id = $this->postJson('/api/v1/planillas', $body, $this->headers($this->token($operator)))->assertCreated()->json('data.id');
        $operator->update(['role' => 'administrador']);
        $this->postJson('/api/v1/planillas/'.$id.'/autorizar', [], $this->headers($this->token($operator)))->assertForbidden()->assertJsonPath('code', 'FOUR_EYES_REQUIRED');
        $this->postJson('/api/v1/planillas/'.$id.'/autorizar', [], $this->headers($this->token($approver)))->assertOk();
        try {
            app(PayrollService::class)->process($id);
            self::fail('Expected insufficient funds');
        } catch (BusinessException $e) {
            self::assertSame('INSUFFICIENT_FUNDS', $e->errorCode);
        }
        self::assertSame(10000, $source->refresh()->saldo_centavos);
        self::assertSame(0, $a->refresh()->saldo_centavos);
        self::assertSame(0, $b->refresh()->saldo_centavos);
        self::assertSame(0, Movement::count());
        $source->update(['saldo_centavos' => 20000]);
        app(PayrollService::class)->process($id);
        app(PayrollService::class)->process($id);
        self::assertSame(8000, $source->refresh()->saldo_centavos);
        self::assertSame(6000, $a->refresh()->saldo_centavos);
        self::assertSame(4, Movement::count());
        self::assertSame('pagada', Payroll::findOrFail($id)->estado);
    }

    public function test_corporate_users_cannot_read_other_companies(): void
    {
        $operator = $this->user('empresa_operador', 'operator');
        $outsider = $this->user('empresa_operador', 'outsider');
        $company = Company::create(['nombre' => 'Empresa Test', 'nit' => 'EMP-1', 'cuenta_id' => (string) $this->account($this->customer())->id, 'miembros' => [(string) $operator->id]]);
        $this->getJson('/api/v1/empresas', $this->headers($this->token($outsider)))->assertOk()->assertJsonCount(0, 'data.data');
        $this->postJson('/api/v1/planillas', ['empresa_id' => (string) $company->id, 'nombre' => 'Intrusión', 'pagos' => [['cuenta_numero' => 'CP000', 'nombre' => 'Persona', 'monto' => '1.00']]], $this->headers($this->token($outsider)))->assertForbidden();
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

    public function test_card_awaiting_reconciliation_does_not_stop_the_daily_close_of_others(): void
    {
        $today = now()->timezone('America/Guatemala')->toImmutable();
        $policy = ['annual_bps' => 3650, 'minimum_bps' => 500, 'minimum_floor_cents' => 10000, 'cutoff_day' => 1, 'payment_days' => 20];
        $c = $this->customer();
        $stuck = Card::create(['cliente_id' => (string) $c->id, 'solicitud_id' => 'gap', 'referencia' => 'CPGAP', 'tipo' => 'credito', 'estado' => 'activa', 'limite_centavos' => 100000, 'principal_centavos' => 10000, 'interes_centavos' => 0, 'emitida_el' => $today->subDays(5)->toDateString(), 'ultimo_devengo' => $today->subDays(3)->toDateString(), 'policy' => $policy]);
        $healthy = Card::create(['cliente_id' => (string) $c->id, 'solicitud_id' => 'ok', 'referencia' => 'CPOK', 'tipo' => 'credito', 'estado' => 'activa', 'limite_centavos' => 100000, 'principal_centavos' => 10000, 'interes_centavos' => 0, 'emitida_el' => $today->toDateString(), 'policy' => $policy]);
        $result = app(CardService::class)->closeDay($today->toDateString());
        self::assertSame(1, $result['tarjetas_procesadas']);
        self::assertSame([(string) $stuck->id], array_column($result['pendientes'], 'tarjeta_id'));
        self::assertSame(10, $healthy->refresh()->interes_centavos);
        self::assertSame(0, $stuck->refresh()->interes_centavos);
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

    public function test_savings_accrual_rolls_back_without_funding_and_retries_once(): void
    {
        $today = now()->timezone('America/Guatemala')->toDateString();
        $account = $this->account($this->customer('savings'), 100000);
        $account->update(['tipo' => 'ahorro', 'tasa_bps' => 365]);
        $fund = Account::where('system_key', 'intereses')->firstOrFail();
        $service = app(SavingsService::class);
        $result = $service->closeDay($today);
        self::assertSame(0, $result['cuentas_procesadas']);
        self::assertSame('INSUFFICIENT_FUNDS', $result['pendientes'][0]['codigo']);
        self::assertSame(0, DailyAccrual::count());
        self::assertSame(100000, $account->refresh()->saldo_centavos);
        $fund->update(['saldo_centavos' => 1000]);
        $service->closeDay($today);
        $service->closeDay($today);
        self::assertSame(100010, $account->refresh()->saldo_centavos);
        self::assertSame(990, $fund->refresh()->saldo_centavos);
        self::assertSame(1, DailyAccrual::count());
        self::assertSame(2, Movement::count());
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
}
