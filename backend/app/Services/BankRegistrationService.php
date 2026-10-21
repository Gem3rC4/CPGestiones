<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Account;
use App\Models\Customer;
use App\Repositories\CustomerRepository;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class BankRegistrationService
{
    public function __construct(private CustomerRepository $customers, private AccountService $accounts, private SecurityService $security) {}

    /**
     * Creates the Banca Virtual access profile only. Bank accounts are opened later from "Cuentas" and the
     * detailed profile is completed with the first gestión. A customer the bank already registered at a
     * branch is linked by DPI when the e-mail on file matches and the customer has no app access yet.
     * The controller's IdempotencyService holds the transaction.
     */
    public function register(array $data, string $actor): array
    {
        $existing = $this->customers->byDpi($data['dpi']);
        $emailOwner = Customer::where('email', $data['correo_electronico'])->first();
        $referenceOwner = isset($data['cliente_ref']) ? Customer::where('external_ref', $data['cliente_ref'])->first() : null;
        foreach ([$emailOwner, $referenceOwner] as $owner) {
            if ($owner && (! $existing || (string) $owner->id !== (string) $existing->id)) {
                throw new BusinessException('Ya existe un registro con esos datos. No se vinculó ni se modificó ningún cliente.', 'CUSTOMER_EXISTS', 409);
            }
        }
        $customer = $existing ? $this->link($existing, $data) : $this->customers->create($this->profileData($data) + [
            'dpi' => $data['dpi'], 'email' => $data['correo_electronico'],
            'nombre' => $data['nombre'].' '.$data['apellido'], 'nombres' => $data['nombre'], 'apellidos' => $data['apellido'],
            'fecha_nacimiento' => $data['fecha_nacimiento'], 'perfil_version' => 1, 'origen' => 'banca_virtual',
            'password' => Hash::make($data['contrasena']), 'active' => true, 'failed_attempts' => 0,
            // The unique index on external_ref only covers strings; omit the field instead of storing null.
        ] + (isset($data['cliente_ref']) ? ['external_ref' => $data['cliente_ref']] : []));
        $this->security->record($existing ? 'perfil_app_vinculado' : 'cliente_registrado_banca', $actor, ['cliente_id' => (string) $customer->id]);
        $existing
            ? app(AlertService::class)->publish('cliente.vinculado:'.$customer->id, 'clientes.read', 'Cliente vinculado a Banca Virtual', 'Un cliente del banco activó su acceso a Banca Virtual con su DPI.', 'clientes', (string) $customer->id)
            : app(AlertService::class)->publish('cliente.registrado:'.$customer->id, 'clientes.read', 'Cliente registrado', 'Un nuevo cliente creó su perfil en Banca Virtual.', 'clientes', (string) $customer->id);
        $summary = $this->summary($customer);

        // 'cuenta' keeps the shape of the previous contract: null until the customer opens an account.
        return ['cliente' => $summary['cliente'], 'vinculado' => (bool) $existing, 'cuenta' => $summary['cuentas'][0] ?? null] + $summary;
    }

    private function link(Customer $customer, array $data): Customer
    {
        if ($customer->password) {
            throw new BusinessException('Ya existe un registro con esos datos. No se vinculó ni se modificó ningún cliente.', 'CUSTOMER_EXISTS', 409);
        }
        $sameEmail = $customer->email && hash_equals(mb_strtolower($customer->email), $data['correo_electronico']);
        $sameBirth = ! $customer->fecha_nacimiento || $customer->fecha_nacimiento === $data['fecha_nacimiento'];
        if (! $sameEmail || ! $sameBirth) {
            // Do not reveal which value differs: knowing a DPI must not be enough to claim a customer.
            throw new BusinessException('El DPI ya está registrado en el banco con otros datos. Acude a una agencia para habilitar tu acceso a Banca Virtual.', 'CUSTOMER_LINK_REJECTED', 409);
        }
        $updates = ['password' => Hash::make($data['contrasena']), 'active' => true, 'failed_attempts' => 0, 'locked_until' => null, 'acceso_app_desde' => now()->toIso8601String()];
        foreach (['nombres' => $data['nombre'], 'apellidos' => $data['apellido'], 'fecha_nacimiento' => $data['fecha_nacimiento'], 'external_ref' => $data['cliente_ref'] ?? null, 'perfil_version' => 1] as $field => $value) {
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
        return [
            'id' => (string) $customer->id, 'cliente_ref' => $customer->external_ref,
            'nombre' => $customer->nombres, 'apellido' => $customer->apellidos, 'nombre_completo' => $customer->nombre,
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
