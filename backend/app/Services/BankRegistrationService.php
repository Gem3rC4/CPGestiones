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

    // The controller's IdempotencyService holds the transaction for every mutation.
    public function register(array $data, string $actor): array
    {
        if ($this->customers->registrationExists($data)) {
            throw new BusinessException('Ya existe un registro con esos datos. No se vinculó ni se modificó ningún cliente.', 'CUSTOMER_EXISTS', 409);
        }
        $customer = $this->customers->create([
            'external_ref' => $data['cliente_ref'], 'nombre' => $data['nombre'].' '.$data['apellido'],
            'nombres' => $data['nombre'], 'apellidos' => $data['apellido'], 'email' => $data['correo_electronico'],
            'dpi' => $data['dpi'], 'nit' => mb_strtoupper($data['nit']), 'fecha_nacimiento' => $data['fecha_nacimiento'],
            'tipo_empleo' => $data['tipo_empleo'], 'ingreso_centavos' => Money::cents($data['ingresos_mensuales_q'], true),
            'antiguedad_laboral_meses' => (int) $data['antiguedad_laboral_meses'], 'deuda_centavos' => null,
            'perfil_version' => 1, 'origen' => 'banca_virtual',
            'password' => Hash::make($data['contrasena']), 'active' => true, 'failed_attempts' => 0,
        ]);
        $account = $this->accounts->open(['cliente_id' => (string) $customer->id, 'producto' => 'ahorro', 'nombre' => 'Mi cuenta de ahorro'], $actor);
        $this->security->record('cliente_registrado_banca', $actor, ['cliente_id' => (string) $customer->id]);
        app(AlertService::class)->publish('cliente.registrado:'.$customer->id, 'clientes.read', 'Cliente registrado', 'Un nuevo cliente abrió su cuenta desde Banca Virtual.', 'clientes', (string) $customer->id);
        $view = $this->accountView($account);

        return ['cliente' => $this->profile($customer), 'cuenta' => $view, 'resumen' => $this->totals([$view])];
    }

    public function findProfile(string $reference): array
    {
        return $this->profile($this->customers->byReference($reference));
    }

    public function summary(string $reference): array
    {
        $customer = $this->customers->byReference($reference);
        $accounts = Account::where('cliente_id', (string) $customer->id)->whereNull('system_key')
            ->orderBy('created_at')->get()->map(fn (Account $account): array => $this->accountView($account->toArray()))->all();

        return ['cliente' => $this->profile($customer), 'cuentas' => $accounts, 'resumen' => $this->totals($accounts)];
    }

    public function updateFinancialProfile(array $data, string $actor): array
    {
        $customer = $this->customers->byReference($data['cliente_ref']);
        if (! $customer->fecha_nacimiento || ! $customer->perfil_version) {
            throw new BusinessException('El perfil anterior requiere completar su identidad mediante una revisión del banco.', 'PROFILE_INCOMPLETE', 409);
        }
        if ((int) $customer->perfil_version !== (int) $data['perfil_version']) {
            throw new BusinessException('El perfil cambió. Consulta su versión actual antes de guardar.', 'STALE_PROFILE', 409);
        }
        $birth = CarbonImmutable::createFromFormat('!Y-m-d', $customer->fecha_nacimiento, 'America/Guatemala');
        if ((int) $data['antiguedad_laboral_meses'] > (int) $birth->diffInMonths(CarbonImmutable::now('America/Guatemala'))) {
            throw ValidationException::withMessages(['antiguedad_laboral_meses' => 'La antigüedad laboral no puede superar la edad del cliente en meses.']);
        }
        $customer = $this->customers->update($customer, [
            'tipo_empleo' => $data['tipo_empleo'], 'ingreso_centavos' => Money::cents($data['ingresos_mensuales_q'], true),
            'antiguedad_laboral_meses' => (int) $data['antiguedad_laboral_meses'], 'perfil_version' => (int) $customer->perfil_version + 1,
        ]);
        $this->security->record('perfil_financiero_actualizado', $actor, ['cliente_id' => (string) $customer->id, 'version' => $customer->perfil_version]);

        return $this->profile($customer);
    }

    private function profile(Customer $customer): array
    {
        return [
            'id' => (string) $customer->id, 'cliente_ref' => $customer->external_ref,
            'nombre' => $customer->nombres, 'apellido' => $customer->apellidos, 'nombre_completo' => $customer->nombre,
            'correo_electronico' => $customer->email, 'dpi_enmascarado' => $customer->dpi_enmascarado,
            'nit' => $customer->nit, 'fecha_nacimiento' => $customer->fecha_nacimiento,
            'tipo_empleo' => $customer->tipo_empleo === null ? null : ucfirst($customer->tipo_empleo),
            'ingresos_mensuales_q' => $customer->ingreso_centavos === null ? null : Money::format((int) $customer->ingreso_centavos),
            'ingreso_centavos' => $customer->ingreso_centavos,
            'antiguedad_laboral_meses' => $customer->antiguedad_laboral_meses,
            'deuda_declarada' => $customer->deuda_centavos !== null,
            'perfil_version' => $customer->perfil_version,
            'perfil_completo' => $customer->perfil_version !== null && $customer->fecha_nacimiento !== null,
        ];
    }

    private function accountView(array $account): array
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
