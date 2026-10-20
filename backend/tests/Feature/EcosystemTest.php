<?php

namespace Tests\Feature;

use App\Models\AccessToken;
use App\Models\Account;
use App\Models\BankEvent;
use App\Models\Card;
use App\Models\CardApplication;
use App\Models\Credit;
use App\Models\Customer;
use App\Models\CustomerSession;
use App\Models\OperationAlert;
use App\Models\OutboxEvent;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Services\AlertService;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\InstallService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use MongoDB\Database;
use Tests\TestCase;

final class EcosystemTest extends TestCase
{
    private string $bank;

    private string $risk;

    private string $manager;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.mongodb.database' => 'cp_gestiones_ecosystem_'.getmypid().'_test', 'cache.default' => 'array']);
        DB::purge('mongodb');
        $this->isolatedDatabase()->drop();
        app(InstallService::class)->indexes();
        app(InstallService::class)->catalog();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00', 'America/Guatemala'));
        $this->bank = app(AuthService::class)->issueService('banca');
        $this->risk = app(AuthService::class)->issueService('riesgos');
        $this->manager = $this->staffToken('gerente');
        $this->customer = Customer::create(['nombre' => 'Cliente de prueba', 'email' => 'cliente@example.test', 'dpi' => '1111111111111', 'external_ref' => 'banca-1', 'password' => Hash::make('PasswordSeguro123'), 'active' => true, 'failed_attempts' => 0]);
    }

    protected function tearDown(): void
    {
        $this->isolatedDatabase()->drop();
        $this->travelBack();
        parent::tearDown();
    }

    private function isolatedDatabase(): Database
    {
        if (! preg_match('/^cp_gestiones_ecosystem_[0-9]+_test$/D', config('database.connections.mongodb.database'))) {
            throw new \LogicException('Unsafe test database');
        }

        return DB::connection('mongodb')->getDatabase();
    }

    private function staffToken(string $role): string
    {
        $user = User::create(['name' => $role, 'email' => bin2hex(random_bytes(5)).'@example.test', 'active' => true, 'role' => $role]);
        $plain = bin2hex(random_bytes(32));
        AccessToken::create(['kind' => 'user', 'user_id' => (string) $user->id, 'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addHour()]);

        return $plain;
    }

    private function headers(?string $token = null, ?string $key = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->bank), 'Idempotency-Key' => $key ?? bin2hex(random_bytes(12))];
    }

    private function assessment(array $changes = []): array
    {
        return array_replace(['evaluacion_ref' => 'risk-10030', 'cliente_ref' => 'banca-1', 'producto' => 'credito-personal', 'resultado' => 'aprobada', 'monto_solicitado' => '10000.00', 'limite_aprobado' => '8500.00', 'motivo' => 'Evaluación aprobada por el motor'], $changes);
    }

    private function management(array $changes = []): array
    {
        return array_replace(['gestion_ref' => '10030', 'cliente_ref' => 'banca-1', 'evaluacion_ref' => 'risk-10030', 'referencia_aceptacion' => 'consent-10030', 'aceptada_en' => '2026-10-07T11:30:00-06:00'], $changes);
    }

    private function received(string $product = 'credito-personal'): array
    {
        $this->postJson('/api/v1/riesgos/evaluaciones', $this->assessment(['producto' => $product]), $this->headers($this->risk))->assertCreated();

        return $this->postJson('/api/v1/banca/gestiones', $this->management(), $this->headers())->assertCreated()->json('data');
    }

    public function test_central_login_issues_separate_expiring_revocable_customer_session(): void
    {
        $body = ['correo_electronico' => ' CLIENTE@EXAMPLE.TEST ', 'contrasena' => 'PasswordSeguro123'];
        $this->postJson('/api/v1/banca/auth/login', $body)->assertUnauthorized();
        $this->postJson('/api/v1/banca/auth/login', $body, $this->headers($this->manager))->assertForbidden();
        $result = $this->postJson('/api/v1/banca/auth/login', $body, $this->headers())->assertOk()->assertJsonMissingPath('data.cliente.password');
        $plain = $result->json('data.token_cliente');
        self::assertSame(hash('sha256', $plain), CustomerSession::first()->token_hash);
        self::assertSame(0, AccessToken::where('token_hash', hash('sha256', $plain))->count());
        $this->getJson('/api/v1/cuentas', $this->headers($plain))->assertUnauthorized();
        $sessionHeaders = $this->headers() + ['X-Cliente-Token' => $plain];
        $this->getJson('/api/v1/banca/auth/sesion', $sessionHeaders)->assertOk()->assertJsonPath('data.cliente.cliente_ref', 'banca-1');
        $this->postJson('/api/v1/banca/auth/logout', [], $sessionHeaders)->assertOk();
        $this->getJson('/api/v1/banca/auth/sesion', $sessionHeaders)->assertUnauthorized();
        $new = $this->postJson('/api/v1/banca/auth/login', $body, $this->headers())->assertOk()->json('data.token_cliente');
        $this->travel(61)->minutes();
        $this->getJson('/api/v1/banca/auth/sesion', $this->headers() + ['X-Cliente-Token' => $new])->assertUnauthorized();
    }

    public function test_login_rejects_malformed_and_truncated_passwords_and_preserves_spaces(): void
    {
        $exact = ' Aa1'.str_repeat('b', 67).' ';
        self::assertSame(72, strlen($exact));
        $this->customer->update(['password' => Hash::make($exact)]);
        foreach ([['unexpected'], $exact.'suffix', 'Aa1'.str_repeat('é', 35), "PasswordSeguro123\0"] as $password) {
            $this->postJson('/api/v1/banca/auth/login', ['correo_electronico' => 'cliente@example.test', 'contrasena' => $password], $this->headers())
                ->assertUnprocessable()->assertJsonValidationErrors('contrasena');
        }
        self::assertSame(0, CustomerSession::count());
        $this->postJson('/api/v1/banca/auth/login', ['correo_electronico' => 'cliente@example.test', 'contrasena' => trim($exact)], $this->headers())->assertUnauthorized();
        $this->postJson('/api/v1/banca/auth/login', ['correo_electronico' => 'cliente@example.test', 'contrasena' => $exact], $this->headers())->assertOk();
    }

    public function test_customer_lockout_and_legacy_customers_without_credentials_fail_safely(): void
    {
        $body = ['correo_electronico' => 'cliente@example.test', 'contrasena' => 'Incorrecta123'];
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/banca/auth/login', $body, $this->headers())->assertUnauthorized()->assertJsonPath('code', 'INVALID_CREDENTIALS');
        }
        self::assertTrue($this->customer->refresh()->locked_until->isFuture());
        $body['contrasena'] = 'PasswordSeguro123';
        $this->postJson('/api/v1/banca/auth/login', $body, $this->headers())->assertUnauthorized();
        $this->travel(16)->minutes();
        $this->postJson('/api/v1/banca/auth/login', $body, $this->headers())->assertOk();
        $this->customer->update(['password' => null]);
        $this->postJson('/api/v1/banca/auth/login', $body, $this->headers())->assertUnauthorized();
        self::assertStringNotContainsString('PasswordSeguro123', Customer::first()->toJson());
    }

    public function test_only_analytics_can_publish_an_immutable_bounded_assessment(): void
    {
        $this->postJson('/api/v1/riesgos/evaluaciones', $this->assessment(), $this->headers())->assertForbidden();
        $this->postJson('/api/v1/riesgos/evaluaciones', $this->assessment(), $this->headers($this->staffToken('administrador')))->assertForbidden();
        $this->postJson('/api/v1/riesgos/evaluaciones', $this->assessment(['limite_aprobado' => '10000.01']), $this->headers($this->risk))->assertUnprocessable();
        $this->postJson('/api/v1/riesgos/evaluaciones', $this->assessment(), $this->headers($this->risk))->assertCreated();
        $this->postJson('/api/v1/riesgos/evaluaciones', $this->assessment(), $this->headers($this->risk))->assertCreated();
        $this->postJson('/api/v1/riesgos/evaluaciones', $this->assessment(['limite_aprobado' => '9000.00']), $this->headers($this->risk))->assertConflict();
        self::assertSame(1, RiskAssessment::count());
    }

    public function test_accepted_management_arrives_in_progress_and_retries_do_not_duplicate_alerts(): void
    {
        $app = $this->received();
        self::assertSame('en_tramite', $app['estado']);
        self::assertSame(850000, $app['limite_aprobado_centavos']);
        $replayed = $this->postJson('/api/v1/banca/gestiones', $this->management(), $this->headers())->assertCreated()->json('data');
        self::assertSame($app['id'], $replayed['id']);
        $this->postJson('/api/v1/banca/gestiones', $this->management(['referencia_aceptacion' => 'different']), $this->headers())->assertConflict();
        $this->postJson('/api/v1/banca/gestiones', $this->management(['gestion_ref' => 'another']), $this->headers())->assertConflict()->assertJsonPath('code', 'ASSESSMENT_ALREADY_USED');
        self::assertSame(1, CardApplication::count());
        self::assertSame(1, OperationAlert::count());
        self::assertTrue(app(AuditService::class)->verify(CardApplication::first()));
        $this->getJson('/api/v1/solicitudes?estado=en_tramite', $this->headers($this->manager))->assertOk()->assertJsonCount(1, 'data.data');
    }

    public function test_management_requires_approved_matching_evaluation_and_consent(): void
    {
        $this->postJson('/api/v1/banca/gestiones', $this->management(), $this->headers())->assertNotFound();
        $this->postJson('/api/v1/riesgos/evaluaciones', $this->assessment(['resultado' => 'rechazada', 'limite_aprobado' => '0.00']), $this->headers($this->risk))->assertCreated();
        $this->postJson('/api/v1/banca/gestiones', $this->management(), $this->headers())->assertConflict()->assertJsonPath('code', 'RISK_APPROVAL_REQUIRED');
        $this->postJson('/api/v1/banca/gestiones', $this->management(['referencia_aceptacion' => '']), $this->headers())->assertUnprocessable();
        $this->postJson('/api/v1/banca/gestiones', $this->management(['aceptada_en' => '2027-01-01T00:00:00-06:00']), $this->headers())->assertUnprocessable();
        $this->postJson('/api/v1/banca/gestiones', $this->management(['limite_aprobado' => '99999.00']), $this->headers())->assertUnprocessable();
        Customer::create(['nombre' => 'Otro', 'external_ref' => 'other', 'dpi' => '2222222222222']);
        $this->postJson('/api/v1/banca/gestiones', $this->management(['cliente_ref' => 'other']), $this->headers())->assertNotFound();
        self::assertSame(0, CardApplication::count());
        self::assertSame(0, OperationAlert::count());
    }

    public function test_operator_must_enter_limit_and_cannot_exceed_analytics_or_bypass_roles(): void
    {
        $app = $this->received('credito-clasica');
        $path = '/api/v1/solicitudes/'.$app['id'].'/transiciones';
        $body = ['version' => 1, 'estado' => 'emitida', 'nota' => 'Evaluación final del operador'];
        $this->postJson($path, $body, $this->headers($this->manager))->assertUnprocessable();
        $this->postJson($path, $body + ['limite_asignado' => '8500.01'], $this->headers($this->manager))->assertUnprocessable()->assertJsonPath('code', 'RISK_LIMIT_EXCEEDED');
        foreach (['cajero', 'analista', 'auditor', 'empresa_operador', 'empresa_aprobador'] as $role) {
            $this->postJson($path, $body + ['limite_asignado' => '8000.00'], $this->headers($this->staffToken($role)))->assertForbidden();
        }
        $this->postJson($path, $body + ['limite_asignado' => '8000.00', 'cliente_ref' => 'banca-1'], $this->headers())->assertForbidden();
        self::assertSame(0, Card::count());
        self::assertSame(1, CardApplication::first()->version);
        $body['limite_asignado'] = '8000.00';
        $headers = $this->headers($this->manager, 'emit-card-only-once');
        $this->postJson($path, $body, $headers)->assertOk()->assertJsonPath('data.estado', 'emitida')->assertJsonPath('data.limite_aprobado_centavos', 850000)->assertJsonPath('data.limite_asignado_centavos', 800000);
        $this->postJson($path, $body, $headers)->assertOk();
        $this->postJson($path, $body, $this->headers($this->manager))->assertConflict()->assertJsonPath('code', 'STALE_VERSION');
        self::assertSame(1, Card::count());
        self::assertSame(800000, Card::first()->limite_centavos);
        self::assertTrue(app(AuditService::class)->verify(CardApplication::first()));
        $payload = OutboxEvent::where('sequence', 2)->first()->payload;
        self::assertSame(800000, $payload['limite_asignado_centavos']);
        self::assertSame('10030', $payload['gestion_ref']);
    }

    public function test_personal_credit_emission_never_creates_card_or_credits_account_balance(): void
    {
        $app = $this->received();
        $account = Account::create(['cliente_id' => (string) $this->customer->id, 'numero' => '1234567890', 'saldo_centavos' => 12300]);
        $this->postJson('/api/v1/solicitudes/'.$app['id'].'/transiciones', ['estado' => 'emitida', 'version' => 1, 'limite_asignado' => '8500.00', 'nota' => 'Crédito autorizado por el operador'], $this->headers($this->manager))->assertOk()->assertJsonPath('data.estado', 'emitida');
        self::assertSame(0, Card::count());
        self::assertSame(1, Credit::count());
        self::assertSame(850000, Credit::first()->limite_centavos);
        self::assertSame(0, Credit::first()->desembolsado_centavos);
        self::assertSame(12300, $account->refresh()->saldo_centavos);
        $this->getJson('/api/v1/creditos?cliente_ref=banca-1', $this->headers())->assertOk()->assertJsonCount(1, 'data.data');
        Customer::create(['nombre' => 'Otro', 'external_ref' => 'other', 'dpi' => '2222222222222']);
        $this->getJson('/api/v1/creditos?cliente_ref=other', $this->headers())->assertOk()->assertJsonCount(0, 'data.data');
    }

    public function test_operator_can_reject_in_progress_without_emitting_a_product(): void
    {
        $app = $this->received();
        $this->postJson('/api/v1/solicitudes/'.$app['id'].'/transiciones', ['estado' => 'rechazada', 'version' => 1, 'nota' => 'Documentación no corresponde al trámite'], $this->headers($this->manager))->assertOk()->assertJsonPath('data.estado', 'rechazada');
        self::assertNull(CardApplication::first()->active_key);
        self::assertSame(0, Credit::count());
        self::assertTrue(app(AuditService::class)->verify(CardApplication::first()));
    }

    public function test_alerts_are_employee_scoped_and_read_status_is_personal_and_snapshot_based(): void
    {
        $first = app(AlertService::class)->publish('customer-1', 'clientes.read', 'Cliente registrado', 'Primer registro');
        $other = $this->staffToken('gerente');
        $this->getJson('/api/v1/alertas', $this->headers())->assertForbidden();
        $this->getJson('/api/v1/alertas', $this->headers($this->staffToken('empresa_operador')))->assertForbidden();
        $this->getJson('/api/v1/alertas', $this->headers($this->manager))->assertOk()->assertJsonPath('data.sin_leer', 1)->assertJsonMissingPath('data.alertas.data.0.leida_por');
        app(AlertService::class)->publish('customer-2', 'clientes.read', 'Otro cliente', 'Un registro posterior');
        $this->postJson('/api/v1/alertas/leidas', ['ids' => [(string) $first->id]], $this->headers($this->manager))->assertOk();
        $this->postJson('/api/v1/alertas/leidas', ['ids' => [(string) $first->id]], $this->headers($this->manager))->assertOk();
        $this->getJson('/api/v1/alertas', $this->headers($this->manager))->assertOk()->assertJsonPath('data.sin_leer', 1);
        $this->getJson('/api/v1/alertas', $this->headers($other))->assertOk()->assertJsonPath('data.sin_leer', 2);
        $this->postJson('/api/v1/alertas/leidas', ['ids' => ['0123456789abcdef01234567']], $this->headers($this->manager))->assertNotFound();
    }

    public function test_incoming_events_are_validated_idempotent_and_cannot_fake_completion(): void
    {
        $app = $this->received();
        $body = ['evento_id' => 'event-01', 'tipo' => 'gestion.en_tramite', 'referencia' => $app['id'], 'cliente_ref' => 'banca-1'];
        $this->postJson('/api/v1/banca/eventos', $body, $this->headers($this->risk))->assertForbidden();
        $this->postJson('/api/v1/banca/eventos', $body, $this->headers())->assertCreated();
        $this->postJson('/api/v1/banca/eventos', $body, $this->headers())->assertCreated();
        $body['referencia'] = '0123456789abcdef01234567';
        $this->postJson('/api/v1/banca/eventos', $body, $this->headers())->assertConflict();
        $body['evento_id'] = 'event-02';
        $this->postJson('/api/v1/banca/eventos', $body, $this->headers())->assertNotFound();
        $body['tipo'] = 'gestion.emitida';
        $this->postJson('/api/v1/banca/eventos', $body, $this->headers())->assertUnprocessable();
        self::assertSame(1, OperationAlert::count());
        self::assertSame(1, BankEvent::count());
        self::assertSame('en_tramite', CardApplication::first()->estado);
    }
}
