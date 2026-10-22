<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Account;
use App\Models\Customer;
use App\Repositories\CustomerRepository;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BankRegistrationService
{
    public function __construct(private CustomerRepository $customers, private AccountService $accounts, private SecurityService $security) {}

    /**
     * Creates the Banca Virtual access of a customer the bank already registered. Customers and accounts are
     * created only in Gestiones: the person proves who they are with the data printed on their opening
     * receipt (DPI, account number, names, e-mail and birth date) and only chooses a password here.
     * No customer, account or account number is ever created by this call.
     * The controller's IdempotencyService holds the transaction.
     */
    public function register(array $data, string $actor): array
    {
        $customer = $this->customers->byDpi($data['dpi']);
        if (! $customer) {
            throw new BusinessException('No encontramos un cliente con ese DPI en CP Gestiones. Primero el banco debe registrarte y abrirte una cuenta.', 'CUSTOMER_NOT_FOUND', 404);
        }
        if ($customer->password) {
            throw new BusinessException('Este DPI ya tiene acceso a Banca Virtual. Ingresa con tu correo y contraseña.', 'CUSTOMER_EXISTS', 409);
        }
        if (! isset($data['numero_cuenta'])) {
            throw ValidationException::withMessages(['numero_cuenta' => 'Ingresa el número de cuenta de tu comprobante de apertura.']);
        }
        $account = Account::where('numero', $data['numero_cuenta'])->where('cliente_id', (string) $customer->id)->whereNull('system_key')->first();
        if (! $account) {
            throw new BusinessException('El número de cuenta no pertenece al DPI ingresado. Verifícalo en tu comprobante de apertura.', 'ACCOUNT_MISMATCH', 422);
        }
        $referenceOwner = isset($data['cliente_ref']) ? Customer::where('external_ref', $data['cliente_ref'])->first() : null;
        if ($referenceOwner && (string) $referenceOwner->id !== (string) $customer->id) {
            throw new BusinessException('Ya existe un registro con esos datos. No se vinculó ni se modificó ningún cliente.', 'CUSTOMER_EXISTS', 409);
        }
        $different = $this->mismatches($customer, $data);
        if ($different !== []) {
            throw new BusinessException('Los datos no coinciden con los registrados en CP Gestiones ('.implode(', ', $different).'). Escríbelos tal como aparecen en tu comprobante de apertura.', 'CUSTOMER_LINK_REJECTED', 409);
        }
        $customer = $this->link($customer, $data);
        $this->security->record('perfil_app_vinculado', $actor, ['cliente_id' => (string) $customer->id, 'cuenta' => $account->numero]);
        app(AlertService::class)->publish('cliente.vinculado:'.$customer->id, 'clientes.read', 'Cliente registrado en Banca Virtual', 'Un cliente del banco activó su acceso a Banca Virtual con su DPI y número de cuenta.', 'clientes', (string) $customer->id);
        $summary = $this->summary($customer);

        // 'cuenta' is the account the customer used to register; 'cuentas' lists all of them with their balance.
        return ['cliente' => $summary['cliente'], 'vinculado' => true, 'cuenta' => $this->accountView($account->toArray())] + $summary;
    }

    /**
     * @return list<string> Receipt fields the person typed differently from the customer file.
     */
    private function mismatches(Customer $customer, array $data): array
    {
        $different = [];
        if (! $customer->email || ! hash_equals(mb_strtolower($customer->email), $data['correo_electronico'])) {
            $different[] = 'correo';
        }
        if ($customer->fecha_nacimiento && $customer->fecha_nacimiento !== $data['fecha_nacimiento']) {
            $different[] = 'fecha de nacimiento';
        }
        $parts = preg_split('/\s+/', trim((string) $customer->nombre), 2);
        foreach (['nombre' => $customer->nombres ?: ($parts[0] ?? ''), 'apellido' => $customer->apellidos ?: ($parts[1] ?? '')] as $field => $stored) {
            if (! $this->sameName($stored, $data[$field])) {
                $different[] = $field;
            }
        }
        if (isset($data['nit']) && $customer->nit && self::plainNit($customer->nit) !== self::plainNit($data['nit'])) {
            $different[] = 'NIT';
        }

        return $different;
    }

    /**
     * Names are compared without accents, case or extra spaces; the first name alone is also accepted.
     */
    private function sameName(string $stored, string $typed): bool
    {
        $normalize = fn (string $value): string => trim(preg_replace('/\s+/', ' ', mb_strtolower(Str::ascii($value))));
        [$stored, $typed] = [$normalize($stored), $normalize($typed)];

        return $typed !== '' && ($stored === $typed || str_starts_with($stored, $typed.' '));
    }

    private static function plainNit(string $nit): string
    {
        return mb_strtoupper(str_replace(['-', ' '], '', $nit));
    }

    private function link(Customer $customer, array $data): Customer
    {
        $updates = ['password' => Hash::make($data['contrasena']), 'active' => true, 'failed_attempts' => 0, 'locked_until' => null, 'acceso_app_desde' => now()->toIso8601String()];
        foreach (['fecha_nacimiento' => $data['fecha_nacimiento'], 'external_ref' => $data['cliente_ref'] ?? null, 'perfil_version' => 1] as $field => $value) {
            if ($value !== null && ($customer->{$field} === null || $customer->{$field} === '')) {
                $updates[$field] = $value;
            }
        }
        foreach ($this->profileData($data) as $field => $value) {
            if ($customer->{$field} === null || $customer->{$field} === '') {
                $updates[$field] = $value;
            }
        }

        return $this->customers->update($customer, $updates);
    }

    /**
     * Optional detailed data the form may already send; normalized the same way everywhere.
     */
    public function profileData(array $data): array
    {
        $profile = [];
        if (isset($data['nit'])) {
            $profile['nit'] = mb_strtoupper($data['nit']);
        }
        if (isset($data['tipo_empleo'])) {
            $profile['tipo_empleo'] = $data['tipo_empleo'];
        }
        if (isset($data['ingresos_mensuales_q'])) {
            $profile['ingreso_centavos'] = Money::cents($data['ingresos_mensuales_q'], true);
        }
        if (isset($data['antiguedad_laboral_meses'])) {
            $profile['antiguedad_laboral_meses'] = (int) $data['antiguedad_laboral_meses'];
        }
        if (isset($data['telefono'])) {
            $profile['telefono'] = $data['telefono'];
        }

        return $profile;
    }

    public function findProfile(array $identity): array
    {
        return $this->profile($this->customers->resolve($identity));
    }

    public function summary(Customer $customer): array
    {
        $accounts = Account::where('cliente_id', (string) $customer->id)->whereNull('system_key')
            ->orderBy('created_at')->get()->map(fn (Account $account): array => $this->accountView($account->toArray()))->all();

        return ['cliente' => $this->profile($customer), 'cuentas' => $accounts, 'resumen' => $this->totals($accounts)];
    }

    public function openAccount(array $data, string $actor): array
    {
        $customer = $this->customers->resolve($data);
        $account = $this->accounts->open(['cliente_id' => (string) $customer->id, 'producto' => $data['producto'], 'nombre' => $data['nombre'] ?? null], $actor);
        app(AlertService::class)->publish('cuenta.abierta:'.$account['id'], 'cuentas.read', 'Cuenta abierta', 'Un cliente abrió una cuenta desde Banca Virtual.', 'cuentas', (string) $account['id']);

        return $this->accountView($account);
    }

    public function updateFinancialProfile(array $data, string $actor): array
    {
        $customer = $this->customers->resolve($data);
        if (! $customer->fecha_nacimiento || ! $customer->perfil_version) {
            throw new BusinessException('El perfil anterior requiere completar su identidad mediante una revisión del banco.', 'PROFILE_INCOMPLETE', 409);
        }
        if ((int) $customer->perfil_version !== (int) $data['perfil_version']) {
            throw new BusinessException('El perfil cambió. Consulta su versión actual antes de guardar.', 'STALE_PROFILE', 409);
        }
        $this->assertSeniority($customer->fecha_nacimiento, (int) $data['antiguedad_laboral_meses']);
        $customer = $this->customers->update($customer, $this->profileData($data) + ['perfil_version' => (int) $customer->perfil_version + 1]);
        $this->security->record('perfil_financiero_actualizado', $actor, ['cliente_id' => (string) $customer->id, 'version' => $customer->perfil_version]);

        return $this->profile($customer);
    }

    public function assertSeniority(string $birthDate, int $months): void
    {
        $birth = CarbonImmutable::createFromFormat('!Y-m-d', $birthDate, 'America/Guatemala');
        if ($months > (int) $birth->diffInMonths(CarbonImmutable::now('America/Guatemala'))) {
            throw ValidationException::withMessages(['antiguedad_laboral_meses' => 'La antigüedad laboral no puede superar la edad del cliente en meses.']);
        }
    }

    /**
     * @return list<string> Profile fields still missing to evaluate a credit product.
     */
    public function missingCreditData(Customer $customer): array
    {
        $required = ['nit' => $customer->nit, 'fecha_nacimiento' => $customer->fecha_nacimiento, 'tipo_empleo' => $customer->tipo_empleo, 'ingresos_mensuales_q' => $customer->ingreso_centavos, 'antiguedad_laboral_meses' => $customer->antiguedad_laboral_meses];

        return array_keys(array_filter($required, fn ($value): bool => $value === null || $value === ''));
    }

    public function profile(Customer $customer): array
    {
        // Customers registered in the panel may only have the full name.
        $parts = preg_split('/\s+/', trim((string) $customer->nombre), 2);

        return [
            'id' => (string) $customer->id, 'cliente_ref' => $customer->external_ref,
            'nombre' => $customer->nombres ?: ($parts[0] ?? null), 'apellido' => $customer->apellidos ?: ($parts[1] ?? ''), 'nombre_completo' => $customer->nombre,
            'correo_electronico' => $customer->email, 'dpi_enmascarado' => $customer->dpi_enmascarado,
            'nit' => $customer->nit, 'telefono' => $customer->telefono, 'fecha_nacimiento' => $customer->fecha_nacimiento,
            'tipo_empleo' => $customer->tipo_empleo === null ? null : ucfirst($customer->tipo_empleo),
            'ingresos_mensuales_q' => $customer->ingreso_centavos === null ? null : Money::format((int) $customer->ingreso_centavos),
            'ingreso_centavos' => $customer->ingreso_centavos,
            'antiguedad_laboral_meses' => $customer->antiguedad_laboral_meses,
            'perfil_version' => $customer->perfil_version,
            'perfil_completo' => $customer->perfil_version !== null && $this->missingCreditData($customer) === [],
            'acceso_app' => (bool) $customer->password,
        ];
    }

    /**
     * Profile returned to an authenticated customer session. It carries the full DPI because Banca Virtual
     * uses it as the key of every other call, and a customer registered in Gestiones was never typed in the app.
     */
    public function sessionProfile(Customer $customer): array
    {
        return $this->profile($customer) + ['dpi' => $customer->dpi];
    }

    public function accountView(array $account): array
    {
        $number = $account['numero'];
        $balance = (int) $account['saldo_centavos'];
        $available = $account['estado'] === 'activa' ? $balance : 0;

        return [
            'id' => (string) ($account['id'] ?? $account['_id']), 'numero' => $number,
            'numero_formateado' => preg_match('/^[0-9]{10}$/D', $number) ? substr($number, 0, 1).'-'.substr($number, 1, 3).'-'.substr($number, 4) : $number,
            'nombre' => $account['nombre'], 'tipo' => $account['tipo'], 'moneda' => $account['moneda'], 'estado' => $account['estado'],
            'saldo_centavos' => $balance, 'saldo_disponible_centavos' => $available,
            'saldo' => Money::format($balance), 'saldo_disponible' => Money::format($available),
            // The opening deposit and the reference Banca Virtual received it with (CP-<transaction>), so it is credited only once.
            'monto_apertura_centavos' => (int) ($account['monto_apertura_centavos'] ?? 0),
            'apertura_referencia' => isset($account['apertura_transaccion_id']) ? 'CP-'.$account['apertura_transaccion_id'] : null,
        ];
    }

    private function totals(array $accounts): array
    {
        $balance = array_sum(array_column($accounts, 'saldo_centavos'));
        $available = array_sum(array_column($accounts, 'saldo_disponible_centavos'));

        return ['moneda' => 'GTQ', 'cantidad_cuentas' => count($accounts), 'saldo_total_centavos' => $balance,
            'saldo_disponible_centavos' => $available, 'saldo_total' => Money::format($balance), 'saldo_disponible' => Money::format($available)];
    }
}
