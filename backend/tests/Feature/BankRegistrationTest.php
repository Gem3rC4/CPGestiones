<?php

namespace Tests\Feature;

use App\Models\AccessToken;
use App\Models\Account;
use App\Models\Customer;
use App\Models\IdempotencyRecord;
use App\Models\Product;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\AuthService;
use App\Services\InstallService;
use App\Services\RegistrationUpgradeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use MongoDB\Database;
use MongoDB\Driver\Exception\BulkWriteException;
use Tests\TestCase;

final class BankRegistrationTest extends TestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.mongodb.database' => 'cp_gestiones_registration_'.getmypid().'_test', 'cache.default' => 'array']);
        DB::purge('mongodb');
        $this->isolatedDatabase()->drop();
        app(InstallService::class)->indexes();
        app(InstallService::class)->catalog();
        $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'America/Guatemala'));
        $this->token = app(AuthService::class)->issueService('banca');
    }

    protected function tearDown(): void
    {
        $this->isolatedDatabase()->drop();
        $this->travelBack();
        parent::tearDown();
    }

    private function isolatedDatabase(): Database
    {
        if (! preg_match('/^cp_gestiones_registration_[0-9]+_test$/D', config('database.connections.mongodb.database'))) {
            throw new \LogicException('Unsafe test database');
        }

        return DB::connection('mongodb')->getDatabase();
    }

    private function payload(array $changes = []): array
    {
        return array_replace([
            'contrasena' => 'PasswordSeguro123', 'cliente_ref' => 'banca-juan-001', 'nombre' => 'Juan', 'apellido' => 'Pérez',
            'correo_electronico' => 'juan.perez@ejemplo.com', 'dpi' => '1234567890123', 'nit' => '1234567-8',
            'fecha_nacimiento' => '25/10/1990', 'tipo_empleo' => 'Asalariado',
            'ingresos_mensuales_q' => 5000.00, 'antiguedad_laboral_meses' => 24,
        ], $changes);
    }

    private function headers(string $key = 'register-juan-001', ?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->token), 'Idempotency-Key' => $key];
    }

    public function test_registration_creates_encrypted_customer_and_zero_balance_savings_without_login_user(): void
    {
        $result = $this->postJson('/api/v1/banca/registro', $this->payload(), $this->headers())
            ->assertCreated()->assertJsonPath('data.cliente.nombre', 'Juan')->assertJsonPath('data.cliente.apellido', 'Pérez')
            ->assertJsonPath('data.cliente.nombre_completo', 'Juan Pérez')->assertJsonPath('data.cliente.fecha_nacimiento', '1990-10-25')
            ->assertJsonPath('data.cliente.ingresos_mensuales_q', '5000.00')->assertJsonPath('data.cliente.ingreso_centavos', 500000)
            ->assertJsonPath('data.cliente.dpi_enmascarado', '*********0123')->assertJsonPath('data.cliente.deuda_declarada', false)
            ->assertJsonPath('data.cuenta.tipo', 'ahorro')->assertJsonPath('data.cuenta.saldo', '0.00')
            ->assertJsonPath('data.resumen.cantidad_cuentas', 1)->assertJsonPath('data.resumen.saldo_total_centavos', 0);
        self::assertMatchesRegularExpression('/^[0-9]{10}$/D', $result->json('data.cuenta.numero'));
        self::assertMatchesRegularExpression('/^[0-9]-[0-9]{3}-[0-9]{6}$/D', $result->json('data.cuenta.numero_formateado'));
        self::assertSame(1, Customer::count());
        self::assertSame(1, Account::whereNull('system_key')->count());
        self::assertSame(0, User::count());
        self::assertSame(1, AccessToken::count());
        self::assertSame(2, SecurityEvent::count());
        $raw = $this->isolatedDatabase()->selectCollection('clientes')->findOne([]);
        self::assertNotSame('1234567890123', $raw['dpi']);
        self::assertSame('1234567890123', Crypt::decryptString($raw['dpi']));
        self::assertSame(Customer::fingerprint('1234567890123'), $raw['dpi_hash']);
        self::assertNull(Customer::first()->deuda_centavos);
        self::assertStringNotContainsString('1234567890123', $result->getContent());
        self::assertStringNotContainsString('1234567890123', IdempotencyRecord::first()->toJson());
        self::assertArrayNotHasKey('dpi', Customer::first()->toArray());
        self::assertArrayNotHasKey('dpi_hash', Customer::first()->toArray());
        self::assertArrayNotHasKey('password', Customer::first()->toArray());
        self::assertTrue(Hash::check('PasswordSeguro123', Customer::first()->password));
        self::assertStringNotContainsString('PasswordSeguro123', IdempotencyRecord::first()->toJson());
    }

    public function test_equivalent_retries_replay_one_result_but_changed_payload_conflicts(): void
    {
        $first = $this->postJson('/api/v1/banca/registro', $this->payload(), $this->headers())->assertCreated()->json('data');
        $retry = $this->payload(['fecha_nacimiento' => '1990-10-25', 'ingresos_mensuales_q' => '5000.00', 'correo_electronico' => ' JUAN.PEREZ@EJEMPLO.COM ', 'tipo_empleo' => 'asalariado']);
        self::assertJsonStringEqualsJsonString(json_encode($first), json_encode($this->postJson('/api/v1/banca/registro', $retry, $this->headers())->assertCreated()->json('data')));
        $this->postJson('/api/v1/banca/registro', $this->payload(['nombre' => 'Otro']), $this->headers())->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_MISMATCH');
        self::assertSame(1, Customer::count());
        self::assertSame(1, Account::whereNull('system_key')->count());
    }

    public function test_duplicate_email_dpi_and_reference_never_link_another_user(): void
    {
        $this->postJson('/api/v1/banca/registro', $this->payload(), $this->headers())->assertCreated();
        foreach ([['cliente_ref' => 'banca-juan-001'], ['dpi' => '1234567890123'], ['correo_electronico' => 'JUAN.PEREZ@EJEMPLO.COM']] as $collision) {
            $data = $this->payload(array_replace(['cliente_ref' => 'other-person', 'dpi' => '9999999999999', 'correo_electronico' => 'other@example.test'], $collision));
            $this->postJson('/api/v1/banca/registro', $data, $this->headers('new-key-'.bin2hex(random_bytes(5))))
                ->assertConflict()->assertJsonPath('code', 'CUSTOMER_EXISTS')->assertJsonMissingPath('data');
        }
        self::assertSame(1, Customer::count());
        self::assertSame(1, IdempotencyRecord::count());
    }

    public function test_unknown_and_privilege_fields_are_rejected_without_any_write(): void
    {
        foreach (['password' => 'secret', 'role' => 'administrador', 'saldo_centavos' => 100000, 'cliente_id' => '0123456789abcdef01234567'] as $field => $value) {
            $this->postJson('/api/v1/banca/registro', $this->payload([$field => $value]), $this->headers())
                ->assertUnprocessable()->assertJsonValidationErrors('campos_no_admitidos');
        }
        self::assertSame(0, Customer::count());
        self::assertSame(0, IdempotencyRecord::count());
        self::assertSame(0, SecurityEvent::count());
    }

    public function test_invalid_dates_identity_money_and_employment_return_field_errors(): void
    {
        foreach ([['fecha_nacimiento', '31/02/1990'], ['fecha_nacimiento', '07/10/2008'], ['dpi', 1234567890123], ['dpi', '123'], ['nit', 'abc'], ['nombre', '<script>'], ['correo_electronico', 'invalid'], ['tipo_empleo', 'administrador'], ['ingresos_mensuales_q', -1], ['ingresos_mensuales_q', 5000.123], ['ingresos_mensuales_q', '1000000000.01'], ['antiguedad_laboral_meses', -1], ['antiguedad_laboral_meses', 1.5], ['antiguedad_laboral_meses', 1200], ['cliente_ref', null]] as [$field, $value]) {
            $this->postJson('/api/v1/banca/registro', $this->payload([$field => $value]), $this->headers())
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        self::assertSame(0, Customer::count());
    }

    public function test_invalid_passwords_never_create_partial_registration(): void
    {
        foreach ([null, ['unexpected'], 'Corta1', 'sinmayusculas123', str_repeat('Aa1', 25), 'Aa1'.str_repeat('é', 35), "PasswordSeguro123\0"] as $password) {
            $this->postJson('/api/v1/banca/registro', $this->payload(['contrasena' => $password]), $this->headers())
                ->assertUnprocessable()->assertJsonValidationErrors('contrasena');
        }
        self::assertSame(0, Customer::count());
        self::assertSame(0, Account::whereNull('system_key')->count());
        self::assertSame(0, IdempotencyRecord::count());
        self::assertSame(0, SecurityEvent::count());
    }

    public function test_eighteenth_birthday_and_zero_income_are_accepted(): void
    {
        $this->postJson('/api/v1/banca/registro', $this->payload(['fecha_nacimiento' => '06/10/2008', 'ingresos_mensuales_q' => 0, 'antiguedad_laboral_meses' => 0]), $this->headers())
            ->assertCreated()->assertJsonPath('data.cliente.ingresos_mensuales_q', '0.00');
    }

    public function test_only_bank_token_can_register_and_idempotency_key_is_required(): void
    {
        $this->postJson('/api/v1/banca/registro', $this->payload())->assertUnauthorized();
        $risk = app(AuthService::class)->issueService('riesgos');
        $this->postJson('/api/v1/banca/registro', $this->payload(), $this->headers(token: $risk))->assertForbidden();
        $user = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'role' => 'administrador', 'active' => true]);
        $admin = bin2hex(random_bytes(32));
        AccessToken::create(['kind' => 'user', 'user_id' => (string) $user->id, 'token_hash' => hash('sha256', $admin), 'expires_at' => now()->addHour()]);
        $this->postJson('/api/v1/banca/registro', $this->payload(), $this->headers(token: $admin))->assertForbidden();
        $this->postJson('/api/v1/banca/registro', $this->payload(), ['Authorization' => 'Bearer '.$this->token])->assertUnprocessable()->assertJsonPath('code', 'IDEMPOTENCY_REQUIRED');
        self::assertSame(0, Customer::count());
    }

    public function test_account_failure_rolls_back_customer_audit_and_idempotency_then_allows_retry(): void
    {
        Product::where('code', 'ahorro')->update(['active' => false]);
        $this->postJson('/api/v1/banca/registro', $this->payload(), $this->headers())->assertConflict()->assertJsonPath('code', 'PRODUCT_UNAVAILABLE');
        self::assertSame(0, Customer::count());
        self::assertSame(0, SecurityEvent::count());
        self::assertSame(0, IdempotencyRecord::count());
        self::assertSame(0, Account::whereNull('system_key')->count());
        Product::where('code', 'ahorro')->update(['active' => true]);
        $this->postJson('/api/v1/banca/registro', $this->payload(), $this->headers())->assertCreated();
    }

    public function test_profile_and_summary_are_scoped_and_blocked_balances_are_not_available(): void
    {
        $registration = $this->postJson('/api/v1/banca/registro', $this->payload(), $this->headers())->assertCreated();
        $this->postJson('/api/v1/banca/registro', $this->payload(['cliente_ref' => 'other', 'dpi' => '9999999999999', 'correo_electronico' => 'other@example.test']), $this->headers('register-other'))->assertCreated();
        $this->getJson('/api/v1/banca/resumen', $this->headers())->assertUnprocessable();
        $this->getJson('/api/v1/banca/perfil?cliente_ref=missing', $this->headers())->assertNotFound();
        $this->getJson('/api/v1/banca/perfil?cliente_ref=banca-juan-001', $this->headers())->assertOk()->assertJsonPath('data.nombre', 'Juan')->assertJsonMissingPath('data.dpi');
        $this->getJson('/api/v1/banca/resumen?cliente_ref=banca-juan-001', $this->headers())->assertOk()->assertJsonCount(1, 'data.cuentas')->assertJsonPath('data.resumen.cantidad_cuentas', 1);
        $this->getJson('/api/v1/clientes?cliente_ref=banca-juan-001', $this->headers())->assertOk()->assertJsonMissingPath('data.data.0.dpi')->assertJsonPath('data.data.0.dpi_enmascarado', '*********0123');
        $account = Account::findOrFail($registration->json('data.cuenta.id'));
        $account->update(['estado' => 'bloqueada', 'saldo_centavos' => 12345, 'numero' => 'CP123456789012']);
        $this->getJson('/api/v1/banca/resumen?cliente_ref=banca-juan-001', $this->headers())->assertOk()
            ->assertJsonPath('data.cuentas.0.numero', 'CP123456789012')->assertJsonPath('data.cuentas.0.numero_formateado', 'CP123456789012')
            ->assertJsonPath('data.resumen.saldo_total', '123.45')->assertJsonPath('data.resumen.saldo_disponible', '0.00');
    }

    public function test_financial_updates_are_versioned_idempotent_and_cannot_modify_identity(): void
    {
        $this->postJson('/api/v1/banca/registro', $this->payload(), $this->headers())->assertCreated();
        $payload = ['cliente_ref' => 'banca-juan-001', 'tipo_empleo' => 'Independiente', 'ingresos_mensuales_q' => 6500.25, 'antiguedad_laboral_meses' => 36, 'perfil_version' => 1];
        $first = $this->patchJson('/api/v1/banca/perfil/finanzas', $payload, $this->headers('update-finances-01'))->assertOk()->assertJsonPath('data.perfil_version', 2)->assertJsonPath('data.ingresos_mensuales_q', '6500.25')->json();
        self::assertJsonStringEqualsJsonString(json_encode($first), json_encode($this->patchJson('/api/v1/banca/perfil/finanzas', $payload, $this->headers('update-finances-01'))->assertOk()->json()));
        $this->patchJson('/api/v1/banca/perfil/finanzas', $payload, $this->headers('update-finances-02'))->assertConflict()->assertJsonPath('code', 'STALE_PROFILE');
        $payload['perfil_version'] = 2;
        $payload['antiguedad_laboral_meses'] = 1200;
        $this->patchJson('/api/v1/banca/perfil/finanzas', $payload, $this->headers('update-finances-03'))->assertUnprocessable()->assertJsonValidationErrors('antiguedad_laboral_meses');
        $payload['nombre'] = 'Changed';
        $this->patchJson('/api/v1/banca/perfil/finanzas', $payload, $this->headers('update-finances-04'))->assertUnprocessable()->assertJsonValidationErrors('campos_no_admitidos');
        self::assertSame('Juan Pérez', Customer::first()->nombre);
        self::assertSame(2, Customer::first()->perfil_version);
        self::assertSame(1, Account::whereNull('system_key')->count());
    }

    public function test_unique_index_blocks_duplicate_dpi_despite_randomized_encryption(): void
    {
        Customer::create(['nombre' => 'First', 'dpi' => '1234567890123', 'external_ref' => 'first']);
        try {
            Customer::create(['nombre' => 'Second', 'dpi' => '1234567890123', 'external_ref' => 'second']);
            self::fail('Database allowed a duplicate DPI');
        } catch (BulkWriteException $exception) {
            self::assertSame(11000, $exception->getCode());
        }
        self::assertSame(1, Customer::count());
    }

    public function test_concurrent_registrations_with_distinct_keys_create_only_one_customer_and_account(): void
    {
        $processes = [];
        $data = $this->payload(['fecha_nacimiento' => '1990-10-25', 'tipo_empleo' => 'asalariado', 'ingresos_mensuales_q' => '5000.00']);
        for ($i = 0; $i < 4; $i++) {
            $process = proc_open([PHP_BINARY, __DIR__.'/../registration_worker.php', config('database.connections.mongodb.database'), config('database.connections.mongodb.dsn'), 'parallel-registration-'.$i], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fwrite($pipes[0], json_encode($data));
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        $successful = 0;
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);
            self::assertContains($output, ['OK', 'CUSTOMER_EXISTS', 'CONFLICT'], $errors.' '.$output);
            $successful += $output === 'OK' ? 1 : 0;
        }
        self::assertSame(1, $successful);
        self::assertSame(1, Customer::count());
        self::assertSame(1, Account::whereNull('system_key')->count());
        self::assertSame(1, IdempotencyRecord::count());
        self::assertSame(2, SecurityEvent::count());
    }

    public function test_upgrade_encrypts_legacy_identity_without_changing_ids_or_accounts(): void
    {
        $collection = $this->isolatedDatabase()->selectCollection('clientes');
        $id = $collection->insertOne(['nombre' => 'Legacy', 'dpi' => '1111111111111', 'email' => 'LEGACY@EXAMPLE.TEST', 'external_ref' => 'legacy'])->getInsertedId();
        $account = Account::create(['cliente_id' => (string) $id, 'numero' => 'CP123456789012', 'estado' => 'activa', 'saldo_centavos' => 100]);
        self::assertSame(1, app(RegistrationUpgradeService::class)->run());
        self::assertSame(0, app(RegistrationUpgradeService::class)->run());
        $raw = $collection->findOne(['_id' => $id]);
        self::assertSame('1111111111111', Crypt::decryptString($raw['dpi']));
        self::assertSame('legacy@example.test', $raw['email']);
        self::assertSame((string) $id, $account->refresh()->cliente_id);
        self::assertSame('CP123456789012', $account->numero);
        self::assertSame(100, $account->saldo_centavos);
        $this->getJson('/api/v1/banca/perfil?cliente_ref=legacy', $this->headers())->assertOk()->assertJsonPath('data.perfil_completo', false)->assertJsonPath('data.nombre', null);
        $this->patchJson('/api/v1/banca/perfil/finanzas', ['cliente_ref' => 'legacy', 'tipo_empleo' => 'Asalariado', 'ingresos_mensuales_q' => 50, 'antiguedad_laboral_meses' => 0, 'perfil_version' => 1], $this->headers('legacy-update'))->assertConflict()->assertJsonPath('code', 'PROFILE_INCOMPLETE');
    }

    public function test_upgrade_stops_before_writes_on_normalized_email_collision(): void
    {
        $collection = $this->isolatedDatabase()->selectCollection('clientes');
        $collection->insertMany([
            ['nombre' => 'One', 'dpi' => '1111111111111', 'email' => 'ONE@example.test', 'external_ref' => 'one'],
            ['nombre' => 'Two', 'dpi' => '2222222222222', 'email' => 'one@example.test', 'external_ref' => 'two'],
        ]);
        try {
            app(RegistrationUpgradeService::class)->run();
            self::fail('Expected duplicate preflight failure');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('duplicados en email', $exception->getMessage());
        }
        self::assertSame(2, $collection->countDocuments(['dpi_hash' => ['$exists' => false]]));
    }
}
