<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Http\Requests\OperationRequest;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
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

    public function company(OperationRequest $r)
    {
        return $this->mutation($r, function ($d, $a) {
            $account = Account::findOrFail($d['cuenta_id']);
            if ($account->system_key || $account->tipo !== 'monetaria' || $account->estado !== 'activa') {
                throw new BusinessException('La empresa requiere una cuenta monetaria activa.', 'INVALID_ACCOUNT', 422);
            }
            $roles = [];
            foreach (array_unique($d['miembros']) as $id) {
                $u = User::findOrFail($id);
                if (! $u->active || ! str_starts_with($u->role, 'empresa_')) {
                    throw new BusinessException('Selecciona usuarios corporativos activos.', 'INVALID_MEMBER', 422);
                }$roles[] = $u->role;
            }
            if (! in_array('empresa_operador', $roles, true) || ! in_array('empresa_aprobador', $roles, true)) {
                throw new BusinessException('Agrega al menos un operador y un aprobador.', 'INVALID_MEMBERS', 422);
            }
            $company = Company::create($d);
            app(SecurityService::class)->record('empresa_creada', $a, ['empresa_id' => (string) $company->id]);

            return $company->toArray();
        }, 201);
    }
}
