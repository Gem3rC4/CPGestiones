<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Http\Requests\OperationRequest;
use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Services\CorporateService;
use App\Services\PayrollService;
use App\Services\SecurityService;

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
     * A company pays payroll from a checking account at the bank. Its representatives are users with the
     * "empresa" role, assigned to the company when the administrator creates them.
     */
    public function company(OperationRequest $r)
    {
        return $this->mutation($r, function ($d, $a) {
            $account = Account::findOrFail($d['cuenta_id']);
            if ($account->system_key || $account->tipo !== 'monetaria' || $account->estado !== 'activa') {
                throw new BusinessException('La empresa requiere una cuenta monetaria activa.', 'INVALID_ACCOUNT', 422);
            }
            if (Company::where('nit', $d['nit'])->exists() || Company::where('cuenta_id', $d['cuenta_id'])->exists()) {
                throw new BusinessException('Ya existe una empresa con ese NIT o esa cuenta.', 'COMPANY_EXISTS', 409);
            }
            $company = Company::create($d + ['cuenta_numero' => $account->numero, 'titular_cuenta' => Customer::find($account->cliente_id)?->nombre, 'miembros' => []]);
            app(SecurityService::class)->record('empresa_creada', $a, ['empresa_id' => (string) $company->id]);

            return $company->toArray();
        }, 201);
    }
}
