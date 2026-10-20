<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Account;
use App\Models\Collaborator;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Repositories\CustomerRepository;
use App\Support\Money;
use Illuminate\Support\Facades\Hash;

class CorporateService
{
    public function __construct(private AccountService $accounts, private PayrollService $payrolls, private CustomerRepository $customers) {}

    /**
     * A collaborator is an employee of a company with a position, salary and a bank account that receives payroll.
     */
    public function addCollaborator(array $data, string $actor): array
    {
        $company = $this->payrolls->authorizeCompany($data['empresa_id'], $actor);
        $account = Account::where('numero', $data['cuenta_numero'])->whereNull('system_key')->first();
        if (! $account || $account->estado !== 'activa') {
            throw new BusinessException('No existe una cuenta activa con ese número.', 'ACCOUNT_NOT_FOUND', 404);
        }
        if ((string) $account->id === (string) $company->cuenta_id) {
            throw new BusinessException('La cuenta de la empresa no puede recibir su propia planilla.', 'INVALID_ACCOUNT', 422);
        }
        if (Collaborator::where('empresa_id', (string) $company->id)->where('cuenta_id', (string) $account->id)->exists()) {
            throw new BusinessException('Esa cuenta ya pertenece a un colaborador de la empresa.', 'DUPLICATE_COLLABORATOR');
        }
        $collaborator = Collaborator::create(['empresa_id' => (string) $company->id, 'nombre' => $data['nombre'], 'puesto' => $data['puesto'], 'salario_centavos' => Money::cents($data['salario']), 'cuenta_id' => (string) $account->id, 'cuenta_numero' => $account->numero, 'activo' => true]);
        app(SecurityService::class)->record('colaborador_registrado', $actor, ['empresa_id' => (string) $company->id, 'colaborador_id' => (string) $collaborator->id]);

        return $collaborator->toArray();
    }

    /**
     * Fictitious corporate data to demonstrate payroll: two companies, five collaborators each (with repeated
     * positions to try the filter), funded company accounts, an operator/approver pair per company and one
     * payroll waiting for authorization. The controller's IdempotencyService holds the transaction.
     */
    public function seedDemo(string $password, string $actor): array
    {
        if (Company::where('demo', true)->exists()) {
            throw new BusinessException('Los datos de prueba corporativos ya fueron cargados.', 'DEMO_EXISTS');
        }
        $vault = Account::where('system_key', 'caja')->firstOrFail();
        $summary = [];
        foreach ($this->demoCompanies() as $index => $definition) {
            $owner = $this->demoCustomer($definition['nombre'], $definition['correo'], $index * 10 + 1);
            $account = $this->accounts->open(['cliente_id' => (string) $owner->id, 'producto' => 'monetaria', 'nombre' => 'Cuenta de planillas'], $actor);
            $this->accounts->move((string) $vault->id, $account['id'], 15000000, $actor, 'Depósito inicial de prueba para planillas', 'deposito');
            $users = [];
            foreach (['empresa_operador' => 'operador', 'empresa_aprobador' => 'aprobador'] as $role => $prefix) {
                $email = $prefix.'.'.$definition['clave'].'@demo.cp';
                $user = User::where('email', $email)->first() ?? User::create(['name' => ucfirst($prefix).' '.$definition['corto'], 'email' => $email, 'role' => $role, 'active' => true, 'failed_attempts' => 0, 'password' => Hash::make($password), 'demo' => true]);
                $users[$role] = $user;
            }
            $company = Company::create(['nombre' => $definition['nombre'], 'nit' => $definition['nit'], 'cuenta_id' => $account['id'], 'miembros' => array_map(fn (User $u): string => (string) $u->id, array_values($users)), 'demo' => true]);
            $collaborators = [];
            foreach ($definition['colaboradores'] as $position => [$name, $job, $salary]) {
                $person = $this->demoCustomer($name, null, $index * 10 + $position + 2);
                $personal = $this->accounts->open(['cliente_id' => (string) $person->id, 'producto' => 'ahorro', 'nombre' => 'Cuenta de nómina'], $actor);
                $collaborators[] = Collaborator::create(['empresa_id' => (string) $company->id, 'nombre' => $name, 'puesto' => $job, 'salario_centavos' => $salary, 'cuenta_id' => $personal['id'], 'cuenta_numero' => $personal['numero'], 'activo' => true, 'demo' => true]);
            }
            if ($index === 0) {
                // One payroll waits for the approver, built with the position filter (both "Vendedor").
                $sellers = array_values(array_filter($collaborators, fn (Collaborator $c): bool => $c->puesto === 'Vendedor'));
                $this->payrolls->create(['empresa_id' => (string) $company->id, 'nombre' => 'Planilla de prueba · Vendedores', 'pagos' => array_map(fn (Collaborator $c): array => ['cuenta_id' => $c->cuenta_id, 'nombre' => $c->nombre, 'monto' => Money::format((int) $c->salario_centavos), 'puesto' => $c->puesto, 'colaborador_id' => (string) $c->id], $sellers)], (string) $users['empresa_operador']->id);
            }
            $summary[] = ['empresa' => $company->nombre, 'cuenta' => $account['numero'], 'saldo_inicial' => '150000.00', 'colaboradores' => count($collaborators),
                'operador' => $users['empresa_operador']->email, 'aprobador' => $users['empresa_aprobador']->email];
        }
        app(SecurityService::class)->record('demo_corporativo_cargado', $actor, ['empresas' => count($summary)]);

        return ['empresas' => $summary];
    }

    private function demoCustomer(string $name, ?string $email, int $sequence): Customer
    {
        // Fictitious DPIs in a reserved range, skipping any that already exist.
        do {
            $dpi = '9990'.str_pad((string) $sequence, 9, '0', STR_PAD_LEFT);
            $sequence += 100;
        } while ($this->customers->byDpi($dpi));

        return Customer::create(['nombre' => $name, 'email' => $email ?? 'colaborador'.substr($dpi, -6).'@demo.cp', 'dpi' => $dpi, 'nit' => 'CF', 'telefono' => '0000-0000', 'origen' => 'demo', 'active' => true, 'failed_attempts' => 0, 'demo' => true]);
    }

    private function demoCompanies(): array
    {
        return [
            ['nombre' => 'Comercial Los Pinos, S.A.', 'corto' => 'Los Pinos', 'clave' => 'lospinos', 'nit' => 'DEMO-1001-1', 'correo' => 'planillas@lospinos.demo.cp', 'colaboradores' => [
                ['Ana Lucía Méndez', 'Gerente de ventas', 1200000],
                ['Carlos Estuardo López', 'Vendedor', 450000],
                ['María Fernanda Ruiz', 'Vendedor', 450000],
                ['Diego Alejandro Soto', 'Contador', 700000],
                ['Sofía Isabel Pérez', 'Bodeguero', 380000],
            ]],
            ['nombre' => 'Transportes El Quetzal, S.A.', 'corto' => 'El Quetzal', 'clave' => 'elquetzal', 'nit' => 'DEMO-2002-2', 'correo' => 'planillas@elquetzal.demo.cp', 'colaboradores' => [
                ['José Manuel Ajú', 'Piloto', 520000],
                ['Luis Fernando Coc', 'Piloto', 520000],
                ['Karla Beatriz Morales', 'Supervisora de rutas', 800000],
                ['Byron Alexander Tum', 'Mecánico', 480000],
                ['Andrea Gabriela Chávez', 'Asistente administrativa', 420000],
            ]],
        ];
    }
}
