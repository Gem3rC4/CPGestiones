<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Http\Requests\OperationRequest;
use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Services\AccountService;
use App\Services\AuthService;
use App\Services\CompanyService;
use App\Services\CorporateService;
use App\Services\PayrollService;
use App\Services\SecurityService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PayrollController extends ApiController
{
    public function store(OperationRequest $r, PayrollService $s)
    {
        return $this->mutation($r, fn ($d, $a) => $s->create($d, $a), 201);
    }

    public function approve(OperationRequest $r, string $id, PayrollService $s)
    {
        return $this->mutation($r, fn ($d, $a) => $s->approve($id, $a));
    }

    public function collaborator(OperationRequest $r, CorporateService $s)
    {
        return $this->mutation($r, fn ($d, $a) => $s->addCollaborator($d, $a), 201);
    }

    /**
     * Corporate banking: the company is created with its own account (and optionally its representative).
     * cuenta_id (an existing checking account) is still accepted from integrations built before.
     */
    public function company(OperationRequest $r, CompanyService $companies)
    {
        return $this->mutation($r, function ($d, $a) use ($companies) {
            if (empty($d['cuenta_id'])) {
                return $companies->create($d, $a);
            }
            $account = Account::findOrFail($d['cuenta_id']);
            if ($account->system_key || $account->tipo !== 'monetaria' || $account->estado !== 'activa') {
                throw new BusinessException('La empresa requiere una cuenta monetaria activa.', 'INVALID_ACCOUNT', 422);
            }
            if (Company::where('nit', $d['nit'])->exists() || Company::where('cuenta_id', $d['cuenta_id'])->exists()) {
                throw new BusinessException('Ya existe una empresa con ese NIT o esa cuenta.', 'COMPANY_EXISTS', 409);
            }
            $company = Company::create(['nombre' => $d['nombre'], 'nit' => $d['nit'], 'cuenta_id' => $d['cuenta_id'], 'cuenta_numero' => $account->numero, 'miembros' => [], 'segmento' => 'empresarial']);
            $account->update(['empresa_id' => (string) $company->id]);
            app(SecurityService::class)->record('empresa_creada', $a, ['empresa_id' => (string) $company->id]);

            return $companies->detail($company);
        }, 201);
    }

    public function companyDetail(Request $r, string $id, CompanyService $companies)
    {
        $company = Company::findOrFail($id);
        if ($r->user()?->role === 'empresa' && $r->user()->empresa_id !== (string) $company->id) {
            throw new BusinessException('No tienes acceso a esta empresa.', 'FORBIDDEN', 403);
        }

        return $this->ok($companies->detail($company));
    }

    public function companyAccount(OperationRequest $r, string $id, CompanyService $companies)
    {
        return $this->mutation($r, fn ($d, $a) => $companies->openAccount($id, $d, $a), 201);
    }

    public function companyUser(OperationRequest $r, string $id, CompanyService $companies)
    {
        return $this->mutation($r, fn ($d, $a) => $companies->createRepresentative(Company::findOrFail($id), ['name' => $d['nombre'], 'email' => $d['correo'], 'password' => $d['contrasena']], $a), 201);
    }

    /**
     * Banca corporativa manages only the users of a company (role empresa), never bank users.
     */
    public function companyUserPassword(OperationRequest $r, string $id, string $userId, AuthService $auth)
    {
        return $this->mutation($r, function ($d, $a) use ($id, $userId, $auth) {
            $user = $this->companyMember($id, $userId);
            $user->update(['password' => Hash::make($d['contrasena']), 'failed_attempts' => 0, 'locked_until' => null]);
            $auth->closeUserSessions($userId);
            app(SecurityService::class)->record('contrasena_usuario_restablecida', $a, ['user_id' => $userId, 'empresa_id' => $id]);

            return $user->refresh()->toArray();
        });
    }

    public function companyUserState(OperationRequest $r, string $id, string $userId, AuthService $auth)
    {
        return $this->mutation($r, function ($d, $a) use ($id, $userId, $auth) {
            $user = $this->companyMember($id, $userId);
            $user->update(['active' => (bool) $d['activo'], 'failed_attempts' => 0, 'locked_until' => null]);
            $auth->closeUserSessions($userId);
            app(SecurityService::class)->record('acceso_actualizado', $a, ['user_id' => $userId, 'empresa_id' => $id, 'active' => (bool) $d['activo']]);

            return $user->refresh()->toArray();
        });
    }

    /**
     * Funding or withdrawal of a company account against the bank's master account.
     */
    public function companyFunding(OperationRequest $r, string $id, AccountService $accounts)
    {
        return $this->mutation($r, function ($d, $a) use ($id, $accounts) {
            $account = Account::findOrFail($d['cuenta_id']);
            if ($account->empresa_id !== (string) Company::findOrFail($id)->id) {
                throw new BusinessException('La cuenta no pertenece a esta empresa.', 'INVALID_ACCOUNT', 422);
            }

            // The company brings the money in: it is added to the vault (concentradora); a withdrawal leaves it.
            return $d['tipo'] === 'deposito'
                ? $accounts->cashIn((string) $account->id, Money::cents($d['monto']), $a, $d['concepto'], 'deposito')
                : $accounts->cashOut((string) $account->id, Money::cents($d['monto']), $a, $d['concepto'], 'retiro');
        }, 201);
    }

    /**
     * People who can be assigned as collaborators: personal customers with an active savings or checking
     * account. Only the data the form needs, so Banca corporativa does not read the personal directory.
     */
    public function candidates()
    {
        $accounts = Account::whereNull('system_key')->whereNull('empresa_id')->where('estado', 'activa')->whereIn('tipo', ['ahorro', 'monetaria'])->get()->groupBy('cliente_id');

        return $this->ok(Customer::where('segmento', '!=', 'empresa')->whereIn('_id', $accounts->keys()->filter()->values()->all())->orderBy('nombre')->get()
            ->map(fn (Customer $c): array => ['id' => (string) $c->id, 'nombre' => $c->nombre, 'dpi_enmascarado' => $c->dpi_enmascarado, 'email' => $c->email,
                'cuentas' => ($accounts[(string) $c->id] ?? collect())->map(fn (Account $a): array => ['id' => (string) $a->id, 'numero' => $a->numero, 'tipo' => $a->tipo])->values()])->values());
    }

    private function companyMember(string $companyId, string $userId): User
    {
        $user = User::findOrFail($userId);
        if ($user->role !== 'empresa' || $user->empresa_id !== (string) Company::findOrFail($companyId)->id) {
            throw new BusinessException('Ese usuario no pertenece a esta empresa.', 'FORBIDDEN', 403);
        }

        return $user;
    }
}
