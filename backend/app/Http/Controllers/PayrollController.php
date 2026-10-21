<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Http\Requests\OperationRequest;
use App\Models\Account;
use App\Models\Company;
use App\Services\CompanyService;
use App\Services\CorporateService;
use App\Services\PayrollService;
use App\Services\SecurityService;
use Illuminate\Http\Request;

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
}
