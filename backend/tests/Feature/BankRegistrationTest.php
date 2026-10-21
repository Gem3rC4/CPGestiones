<?php

namespace Tests\Feature;

use App\Models\AccessToken;
use App\Models\Account;
use App\Models\Card;
use App\Models\CardApplication;
use App\Models\Credit;
use App\Models\Customer;
use App\Models\IdempotencyRecord;
use App\Models\OperationAlert;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\AuditService;
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
        return ['Authorization' => 'Bearer '.($token ?? $this->token), 'Idempotency-Key' => strlen($key) < 8 ? 'test-key-'.$key : $key];
    }

    public function test_registration_creates_an_encrypted_access_profile_without_opening_accounts(): void
    {
        $result = $this->postJson('/api/v1/banca/registro', $this->payload(), $this->headers())
            ->assertCreated()->assertJsonPath('data.cliente.nombre', 'Juan')->assertJsonPath('data.cliente.apellido', 'Pérez')
            ->assertJsonPath('data.cliente.nombre_completo', 'Juan Pérez')->assertJsonPath('data.cliente.fecha_nacimiento', '1990-10-25')
            ->assertJsonPath('data.cliente.ingresos_mensuales_q', '5000.00')->assertJsonPath('data.cliente.ingreso_centavos', 500000)
            ->assertJsonPath('data.cliente.dpi_enmascarado', '*********0123')->assertJsonPath('data.cliente.acceso_app', true)
            ->assertJsonPath('data.vinculado', false)->assertJsonPath('data.cuenta', null)->assertJsonPath('data.cuentas', [])
            ->assertJsonPath('data.resumen.cantidad_cuentas', 0)->assertJsonPath('data.resumen.saldo_total_centavos', 0);
        self::assertSame(1, Customer::count());
        self::assertSame(0, Account::whereNull('system_key')->count());
        self::assertSame(0, User::count());
        self::assertSame(1, AccessToken::count());
        self::assertSame(1, SecurityEvent::count());
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
        self::assertSame(0, Account::whereNull('system_key')->count());
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
        foreach ([null, ['unexpected'], 'Corta1', 'soloLetrasSinNumero', '1234567890', str_repeat('Aa1', 25), 'Aa1'.str_repeat('é', 35), "PasswordSeguro123\0"] as $password) {
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

    public function test_customer_opens_accounts_from_banca_and_failures_roll_back(): void
    {
        $this->postJson('/api/v1/banca/registro', $this->payload(), $this->headers())->assertCreated();
        Product::where('code', 'monetaria')->update(['active' => false]);
        $this->postJson('/api/v1/banca/cuentas', ['dpi' => '1234567890123', 'producto' => 'monetaria'], $this->headers('open-01'))->assertConflict()->assertJsonPath('code', 'PRODUCT_UNAVAILABLE');
        self::assertSame(0, Account::whereNull('system_key')->count());
        $this->postJson('/api/v1/banca/cuentas', ['dpi' => '1234567890123', 'producto' => 'credito-oro'], $this->headers('open-02'))->assertUnprocessable()->assertJsonValidationErrors('producto');
        $this->postJson('/api/v1/banca/cuentas', ['dpi' => '0000000000000', 'producto' => 'ahorro'], $this->headers('open-03'))->assertNotFound()->assertJsonPath('code', 'CUSTOMER_NOT_FOUND');
        $opened = $this->postJson('/api/v1/banca/cuentas', ['dpi' => '1234567890123', 'producto' => 'ahorro', 'nombre' => 'Mi ahorro'], $this->headers('open-04'))
            ->assertCreated()->assertJsonPath('data.tipo', 'ahorro')->assertJsonPath('data.nombre', 'Mi ahorro')->assertJsonPath('data.saldo', '0.00')->json('data');
        self::assertMatchesRegularExpression('/^[0-9]-[0-9]{3}-[0-9]{6}$/D', $opened['numero_formateado']);
        $this->getJson('/api/v1/banca/resumen?dpi=1234567890123', $this->headers())->assertOk()->assertJsonPath('data.resumen.cantidad_cuentas', 1);
        self::assertSame(1, OperationAlert::where('recurso', 'cuentas')->count());
    }

    public function test_profile_and_summary_are_scoped_and_blocked_balances_are_not_available(): void
    {
        $this->postJson('/api/v1/banca/registro', $this->payload(), $this->headers())->assertCreated();
        $this->postJson('/api/v1/banca/registro', $this->payload(['cliente_ref' => 'other', 'dpi' => '9999999999999', 'correo_electronico' => 'other@example.test']), $this->headers('register-other'))->assertCreated();
        $opened = $this->postJson('/api/v1/banca/cuentas', ['dpi' => '1234567890123', 'producto' => 'ahorro'], $this->headers('open-juan'))->assertCreated()->json('data');
        $this->getJson('/api/v1/banca/resumen', $this->headers())->assertUnprocessable()->assertJsonValidationErrors('dpi');
        $this->getJson('/api/v1/banca/perfil?cliente_ref=missing', $this->headers())->assertNotFound();
        $this->getJson('/api/v1/banca/perfil?dpi=0000000000000', $this->headers())->assertNotFound()->assertJsonPath('code', 'CUSTOMER_NOT_FOUND');
        $this->getJson('/api/v1/banca/perfil?dpi=1234567890123', $this->headers())->assertOk()->assertJsonPath('data.nombre', 'Juan')->assertJsonMissingPath('data.dpi');
        $this->getJson('/api/v1/banca/perfil?cliente_ref=banca-juan-001', $this->headers())->assertOk()->assertJsonPath('data.nombre', 'Juan');
        $this->getJson('/api/v1/banca/resumen?dpi=1234567890123', $this->headers())->assertOk()->assertJsonCount(1, 'data.cuentas')->assertJsonPath('data.resumen.cantidad_cuentas', 1);
        $this->getJson('/api/v1/banca/resumen?dpi=9999999999999', $this->headers())->assertOk()->assertJsonCount(0, 'data.cuentas');
        $this->getJson('/api/v1/clientes?dpi=1234567890123', $this->headers())->assertOk()->assertJsonCount(1, 'data.data')->assertJsonMissingPath('data.data.0.dpi')->assertJsonPath('data.data.0.dpi_enmascarado', '*********0123');
        $this->getJson('/api/v1/cuentas/'.$opened['id'].'/movimientos?dpi=9999999999999', $this->headers())->assertForbidden();
        $account = Account::findOrFail($opened['id']);
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
        self::assertSame(0, Account::whereNull('system_key')->count());
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

    public function test_concurrent_registrations_with_distinct_keys_create_only_one_customer(): void
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
        self::assertSame(0, Account::whereNull('system_key')->count());
        self::assertSame(1, IdempotencyRecord::count());
        self::assertSame(1, SecurityEvent::count());
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
        $this->getJson('/api/v1/banca/perfil?cliente_ref=legacy', $this->headers())->assertOk()->assertJsonPath('data.perfil_completo', false)->assertJsonPath('data.nombre', 'Legacy')->assertJsonPath('data.fecha_nacimiento', null);
        $this->patchJson('/api/v1/banca/perfil/finanzas', ['cliente_ref' => 'legacy', 'tipo_empleo' => 'Asalariado', 'ingresos_mensuales_q' => 50, 'antiguedad_laboral_meses' => 0, 'perfil_version' => 1], $this->headers('legacy-update'))->assertConflict()->assertJsonPath('code', 'PROFILE_INCOMPLETE');
    }

    private function staff(string $role = 'administrador'): string
    {
        $user = User::create(['name' => $role, 'email' => $role.'-'.bin2hex(random_bytes(3)).'@example.test', 'role' => $role, 'active' => true]);
        $plain = bin2hex(random_bytes(32));
        AccessToken::create(['kind' => 'user', 'user_id' => (string) $user->id, 'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addHour()]);

        return $plain;
    }

    private function minimal(array $changes = []): array
    {
        return array_replace(['nombre' => 'Ana', 'apellido' => 'López', 'correo_electronico' => 'ana@ejemplo.com', 'contrasena' => 'clave2026', 'dpi' => '3068226670601', 'fecha_nacimiento' => '12/06/2003'], $changes);
    }

    public function test_minimal_registration_needs_only_access_data_and_dpi(): void
    {
        $this->postJson('/api/v1/banca/registro', $this->minimal(['dpi' => null]), $this->headers('min-01'))->assertUnprocessable()->assertJsonValidationErrors('dpi');
        $this->postJson('/api/v1/banca/registro', $this->minimal(['fecha_nacimiento' => null]), $this->headers('min-02'))->assertUnprocessable()->assertJsonValidationErrors('fecha_nacimiento');
        $profile = $this->postJson('/api/v1/banca/registro', $this->minimal(), $this->headers('min-03'))->assertCreated()->json('data.cliente');
        self::assertSame(['Ana', 'López', null, null, null, 1, false, true], [$profile['nombre'], $profile['apellido'], $profile['cliente_ref'], $profile['nit'], $profile['ingresos_mensuales_q'], $profile['perfil_version'], $profile['perfil_completo'], $profile['acceso_app']]);
        $this->postJson('/api/v1/banca/registro', $this->minimal(['dpi' => '3068226670602', 'correo_electronico' => 'otra@ejemplo.com']), $this->headers('min-04'))->assertCreated();
        self::assertSame(2, Customer::count());
        $this->postJson('/api/v1/banca/auth/login', ['correo_electronico' => 'ana@ejemplo.com', 'contrasena' => 'clave2026'], $this->headers('login'))->assertOk()->assertJsonPath('data.cliente.nombre_completo', 'Ana López');
    }

    public function test_branch_customer_is_linked_by_dpi_only_when_email_and_birth_date_match(): void
    {
        $staff = $this->staff();
        $created = $this->postJson('/api/v1/clientes', ['nombre' => 'Fernando Fajardo', 'email' => 'FerFajardo@creditpulse.com', 'dpi' => '3068226670601', 'nit' => '116698640', 'telefono' => '55513002', 'fecha_nacimiento' => '2003-06-12', 'ingreso_mensual' => '3500.00'], $this->headers('branch-01', $staff))->assertCreated()->json('data');
        self::assertFalse($created['acceso_app']);
        $this->postJson('/api/v1/clientes', ['nombre' => 'Copia', 'email' => 'copia@creditpulse.com', 'dpi' => '3068226670601', 'nit' => '1', 'telefono' => '1', 'ingreso_mensual' => '1.00'], $this->headers('branch-02', $staff))->assertConflict()->assertJsonPath('code', 'CUSTOMER_EXISTS');
        $this->postJson('/api/v1/cuentas', ['cliente_id' => $created['id'], 'producto' => 'ahorro'], $this->headers('branch-03', $staff))->assertCreated();

        $this->postJson('/api/v1/banca/registro', $this->minimal(['correo_electronico' => 'otro@correo.com']), $this->headers('link-01'))->assertConflict()->assertJsonPath('code', 'CUSTOMER_LINK_REJECTED');
        $this->postJson('/api/v1/banca/registro', $this->minimal(['correo_electronico' => 'ferfajardo@creditpulse.com', 'fecha_nacimiento' => '01/01/2000']), $this->headers('link-02'))->assertConflict()->assertJsonPath('code', 'CUSTOMER_LINK_REJECTED');
        self::assertFalse(Customer::first()->acceso_app);
        $linked = $this->postJson('/api/v1/banca/registro', $this->minimal(['nombre' => 'Fernando', 'apellido' => 'Fajardo', 'correo_electronico' => 'ferfajardo@creditpulse.com']), $this->headers('link-03'))
            ->assertCreated()->assertJsonPath('data.vinculado', true)->assertJsonPath('data.resumen.cantidad_cuentas', 1)->assertJsonPath('data.cliente.nit', '116698640')->json('data');
        self::assertSame($created['id'], $linked['cliente']['id']);
        self::assertSame($linked['cuentas'][0]['id'], $linked['cuenta']['id']);
        self::assertSame(1, Customer::count());
        self::assertTrue(Customer::first()->acceso_app);
        $this->postJson('/api/v1/banca/registro', $this->minimal(['correo_electronico' => 'ferfajardo@creditpulse.com']), $this->headers('link-04'))->assertConflict()->assertJsonPath('code', 'CUSTOMER_EXISTS');
        $this->getJson('/api/v1/clientes', $this->headers('list', $staff))->assertOk()->assertJsonPath('data.data.0.acceso_app', true)->assertJsonMissingPath('data.data.0.password');
    }

    public function test_gestiones_arrive_from_banca_with_the_detailed_data_and_staff_cannot_create_them(): void
    {
        $this->postJson('/api/v1/banca/registro', $this->minimal(), $this->headers('reg'))->assertCreated();
        $request = ['dpi' => '3068226670601', 'producto' => 'credito-oro', 'monto_solicitado' => 20000];
        $this->postJson('/api/v1/banca/solicitudes', $request, $this->headers('ges-01'))->assertUnprocessable()
            ->assertJsonValidationErrors(['nit', 'tipo_empleo', 'ingresos_mensuales_q', 'antiguedad_laboral_meses']);
        self::assertSame(0, CardApplication::count());
        $details = ['nit' => '1234567-8', 'tipo_empleo' => 'Asalariado', 'ingresos_mensuales_q' => 9500, 'antiguedad_laboral_meses' => 30, 'telefono' => '55513002'];
        $this->postJson('/api/v1/banca/solicitudes', $request + $details + ['fecha_nacimiento' => '01/01/1990'], $this->headers('ges-02'))->assertUnprocessable()->assertJsonValidationErrors('fecha_nacimiento');
        $this->postJson('/api/v1/banca/solicitudes', array_replace($request + $details, ['antiguedad_laboral_meses' => 400]), $this->headers('ges-03'))->assertUnprocessable()->assertJsonValidationErrors('antiguedad_laboral_meses');
        $gestion = $this->postJson('/api/v1/banca/solicitudes', $request + $details + ['gestion_ref' => '100040'], $this->headers('ges-04'))
            ->assertCreated()->assertJsonPath('data.estado', 'en_evaluacion')->assertJsonPath('data.origen', 'banca_virtual')->assertJsonPath('data.limite_solicitado_centavos', 2000000)
            ->assertJsonPath('data.solicitante.ingresos_mensuales_q', '9500.00')->assertJsonPath('data.gestion_ref', '100040')->json('data');
        self::assertSame(2, Customer::first()->perfil_version);
        $this->getJson('/api/v1/banca/perfil?dpi=3068226670601', $this->headers())->assertJsonPath('data.perfil_completo', true);
        self::assertSame('Gestión por aprobar', OperationAlert::where('recurso_id', $gestion['id'])->first()->titulo);
        self::assertSame(0, OutboxEvent::where('type', 'riesgo.solicitado')->count(), 'Analytics already evaluated it before Banca sent it.');
        $this->postJson('/api/v1/banca/solicitudes', ['dpi' => '3068226670601', 'producto' => 'credito-oro', 'monto_solicitado' => '100.00'], $this->headers('ges-05'))->assertConflict()->assertJsonPath('code', 'MANAGEMENT_IN_PROGRESS');
        $this->postJson('/api/v1/banca/solicitudes', ['dpi' => '3068226670601', 'producto' => 'credito-platino', 'monto_solicitado' => '999999.00'], $this->headers('ges-06'))->assertUnprocessable()->assertJsonPath('code', 'PRODUCT_LIMIT');
        $this->postJson('/api/v1/banca/solicitudes', ['dpi' => '3068226670601', 'producto' => 'debito'], $this->headers('ges-07'))->assertUnprocessable()->assertJsonValidationErrors('cuenta_id');
        $this->postJson('/api/v1/banca/cuentas', ['dpi' => '3068226670601', 'producto' => 'ahorro'], $this->headers('ges-08'))->assertCreated();
        $this->postJson('/api/v1/banca/solicitudes', ['dpi' => '3068226670601', 'producto' => 'debito'], $this->headers('ges-09'))->assertCreated()->assertJsonPath('data.estado', 'en_evaluacion');
        $this->getJson('/api/v1/solicitudes?dpi=3068226670601', $this->headers())->assertOk()->assertJsonCount(2, 'data.data');
        $customer = (string) Customer::first()->id;
        $this->postJson('/api/v1/solicitudes', ['cliente_id' => $customer, 'producto' => 'credito-clasica', 'limite_solicitado' => '1000.00'], $this->headers('staff-ges', $this->staff()))
            ->assertForbidden()->assertJsonPath('code', 'BANK_ORIGIN_REQUIRED');
    }

    public function test_staff_approve_banca_gestiones_assigning_a_limit_up_to_the_authorized_maximum(): void
    {
        $this->postJson('/api/v1/banca/registro', $this->minimal(), $this->headers('reg'))->assertCreated();
        $details = ['nit' => '1234567-8', 'tipo_empleo' => 'Asalariado', 'ingresos_mensuales_q' => 9500, 'antiguedad_laboral_meses' => 30];
        $personal = $this->postJson('/api/v1/banca/solicitudes', ['dpi' => '3068226670601', 'producto' => 'credito-personal', 'monto_solicitado' => '30000.00'] + $details, $this->headers('ap-01'))->json('data');
        $card = $this->postJson('/api/v1/banca/solicitudes', ['dpi' => '3068226670601', 'producto' => 'credito-oro', 'monto_solicitado' => '20000.00', 'monto_aprobado' => '15000.00', 'evaluacion_ref' => 'eval-77'], $this->headers('ap-02'))
            ->assertCreated()->assertJsonPath('data.limite_aprobado_centavos', 1500000)->json('data');
        $this->postJson('/api/v1/banca/solicitudes', ['dpi' => '3068226670601', 'producto' => 'credito-platino', 'monto_solicitado' => '100.00', 'monto_aprobado' => '200.00'], $this->headers('ap-03'))->assertUnprocessable()->assertJsonValidationErrors('monto_aprobado');
        app(InstallService::class)->fundMasterAccount();
        $account = $this->postJson('/api/v1/banca/cuentas', ['dpi' => '3068226670601', 'producto' => 'ahorro'], $this->headers('ap-acct'))->assertCreated()->json('data');
        $loanTerms = ['plazo_meses' => 24, 'tasa_anual' => '18', 'dia_pago' => 10, 'cuenta_id' => $account['id']];
        $cardTerms = ['dia_corte' => 5, 'dia_pago' => 25, 'cuenta_id' => $account['id']];
        $manager = $this->staff('administrador');
        $approve = fn (array $gestion, array $body, string $key, ?string $token = null) => $this->postJson('/api/v1/solicitudes/'.$gestion['id'].'/transiciones', $body + ['version' => 1, 'nota' => 'Gestión aprobada por el banco.'], $this->headers($key, $token ?? $manager));

        $approve($personal, ['estado' => 'rechazada'], 'ap-04')->assertConflict()->assertJsonPath('code', 'INVALID_STATE');
        $approve($personal, ['estado' => 'emitida', 'limite_asignado' => '30000.01'] + $loanTerms, 'ap-05')->assertUnprocessable()->assertJsonPath('code', 'RISK_LIMIT_EXCEEDED');
        $approve($personal, ['estado' => 'emitida', 'limite_asignado' => '25000.00'] + $loanTerms, 'ap-06', $this->staff('corporativo'))->assertForbidden();
        $approve($personal, ['estado' => 'emitida', 'limite_asignado' => '25000.00', 'dpi' => '3068226670601'], 'ap-07', $this->token)->assertForbidden();
        $approve($personal, ['estado' => 'emitida', 'limite_asignado' => '25000.00'], 'ap-08a')->assertUnprocessable()->assertJsonPath('code', 'LOAN_TERMS_REQUIRED');
        $approve($personal, ['estado' => 'emitida', 'limite_asignado' => '25000.00'] + $loanTerms, 'ap-08')->assertOk()->assertJsonPath('data.estado', 'emitida')->assertJsonPath('data.limite_asignado_centavos', 2500000);
        self::assertSame(2500000, Credit::first()->limite_centavos);
        self::assertSame(2500000, Account::findOrFail($account['id'])->saldo_centavos, 'The loan is disbursed into the chosen account.');
        self::assertCount(24, Credit::first()->cuotas);
        $approve($card, ['estado' => 'emitida', 'limite_asignado' => '15000.01'] + $cardTerms, 'ap-09')->assertUnprocessable()->assertJsonPath('code', 'RISK_LIMIT_EXCEEDED');
        $approve($card, ['estado' => 'emitida', 'limite_asignado' => '15000.00'], 'ap-10a')->assertUnprocessable()->assertJsonPath('code', 'CARD_TERMS_REQUIRED');
        $approve($card, ['estado' => 'emitida', 'limite_asignado' => '15000.00'] + $cardTerms, 'ap-10')->assertOk();
        self::assertSame(1500000, Card::where('tipo', 'credito')->first()->limite_centavos);
        self::assertSame(5, Card::where('tipo', 'credito')->first()->dia_corte);
        self::assertSame(['cutoff_day' => 5, 'payment_day' => 25], array_intersect_key(Card::where('tipo', 'credito')->first()->policy, ['cutoff_day' => 1, 'payment_day' => 1]));

        $this->postJson('/api/v1/banca/cuentas', ['dpi' => '3068226670601', 'producto' => 'ahorro'], $this->headers('ap-11'))->assertCreated();
        $debit = $this->postJson('/api/v1/banca/solicitudes', ['dpi' => '3068226670601', 'producto' => 'debito'], $this->headers('ap-12'))->json('data');
        $approve($debit, ['estado' => 'emitida'], 'ap-13')->assertOk()->assertJsonPath('data.estado', 'emitida');
        self::assertSame(1, Card::where('tipo', 'debito')->count());
        $this->getJson('/api/v1/solicitudes?dpi=3068226670601&estado=emitida', $this->headers())->assertOk()->assertJsonCount(3, 'data.data');
        $this->getJson('/api/v1/solicitudes?estado=en_evaluacion', $this->headers('list', $manager))->assertOk()->assertJsonCount(0, 'data.data');
        self::assertTrue(app(AuditService::class)->verify(CardApplication::findOrFail($personal['id'])));
    }

    public function test_forgotten_password_is_reset_with_dpi_email_and_birth_date(): void
    {
        $this->postJson('/api/v1/banca/registro', $this->minimal(), $this->headers('reg'))->assertCreated();
        $token = $this->postJson('/api/v1/banca/auth/login', ['correo_electronico' => 'ana@ejemplo.com', 'contrasena' => 'clave2026'], $this->headers('login'))->json('data.token_cliente');
        $body = ['dpi' => '3068226670601', 'correo_electronico' => 'ANA@ejemplo.com', 'fecha_nacimiento' => '12/06/2003', 'contrasena_nueva' => 'nuevaClave99'];
        $this->postJson('/api/v1/banca/auth/recuperar', ['fecha_nacimiento' => '13/06/2003'] + $body, $this->headers())->assertUnprocessable()->assertJsonPath('code', 'RECOVERY_REJECTED');
        $this->postJson('/api/v1/banca/auth/recuperar', ['correo_electronico' => 'otra@ejemplo.com'] + $body, $this->headers())->assertUnprocessable()->assertJsonPath('code', 'RECOVERY_REJECTED');
        $this->postJson('/api/v1/banca/auth/recuperar', ['dpi' => '0000000000000'] + $body, $this->headers())->assertUnprocessable()->assertJsonPath('code', 'RECOVERY_REJECTED');
        $this->postJson('/api/v1/banca/auth/recuperar', ['contrasena_nueva' => 'corta'] + $body, $this->headers())->assertUnprocessable()->assertJsonValidationErrors('contrasena_nueva');
        self::assertSame(2, Customer::first()->failed_attempts);
        $this->postJson('/api/v1/banca/auth/recuperar', $body, $this->headers())->assertOk()->assertJsonPath('data.contrasena_restablecida', true)->assertJsonPath('data.sesiones_cerradas', 1);
        $this->getJson('/api/v1/banca/auth/sesion', $this->headers() + ['X-Cliente-Token' => $token])->assertUnauthorized();
        $this->postJson('/api/v1/banca/auth/login', ['correo_electronico' => 'ana@ejemplo.com', 'contrasena' => 'clave2026'], $this->headers('login-2'))->assertUnauthorized();
        $this->postJson('/api/v1/banca/auth/login', ['correo_electronico' => 'ana@ejemplo.com', 'contrasena' => 'nuevaClave99'], $this->headers('login-3'))->assertOk();
        self::assertSame(0, Customer::first()->failed_attempts);
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
