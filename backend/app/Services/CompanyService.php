<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Account;
use App\Models\Collaborator;
use App\Models\Company;
use App\Models\Movement;
use App\Models\Payroll;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Corporate banking is kept apart from personal banking: a company owns its accounts directly
 * (accounts.empresa_id, no cliente_id) and its users have the "empresa" role. Personal lists never
 * show them and the corporate screens never show personal customers. Callers hold a transaction.
 */
class CompanyService
{
    public function __construct(private AccountService $accounts) {}

    public function create(array $d, string $actor): array
    {
        if (Company::where('nit', $d['nit'])->exists()) {
            throw new BusinessException('Ya existe una empresa con ese NIT.', 'COMPANY_EXISTS', 409);
        }
        $company = Company::create(array_filter(['nombre' => $d['nombre'], 'nit' => $d['nit'], 'correo' => $d['correo'] ?? null, 'telefono' => $d['telefono'] ?? null, 'direccion' => $d['direccion'] ?? null]) + ['miembros' => [], 'segmento' => 'empresarial']);
        $account = $this->openAccount((string) $company->id, ['producto' => $d['producto_cuenta'] ?? 'monetaria', 'nombre' => 'Cuenta de planillas'], $actor);
        $company->update(['cuenta_id' => $account['id'], 'cuenta_numero' => $account['numero']]);
        $representative = null;
        if (! empty($d['representante_correo'])) {
            $representative = $this->createRepresentative($company, ['name' => $d['representante_nombre'] ?? 'Representante '.$company->nombre, 'email' => $d['representante_correo'], 'password' => $d['contrasena'] ?? ''], $actor);
        }
        app(SecurityService::class)->record('empresa_creada', $actor, ['empresa_id' => (string) $company->id]);

        return $this->detail($company->refresh()) + ['representante_creado' => $representative];
    }

    public function openAccount(string $companyId, array $d, string $actor): array
    {
        $company = Company::findOrFail($companyId);

        return $this->accounts->open(['empresa_id' => (string) $company->id, 'producto' => $d['producto'], 'nombre' => $d['nombre'] ?? null], $actor);
    }

    public function createRepresentative(Company $company, array $d, string $actor): array
    {
        $email = mb_strtolower(trim($d['email']));
        if (User::where('email', $email)->exists()) {
            throw new BusinessException('Ya existe un usuario con ese correo.', 'USER_EXISTS', 409);
        }
        if (strlen($d['password']) < 12 || ! preg_match('/[A-Z]/', $d['password']) || ! preg_match('/[a-z]/', $d['password']) || ! preg_match('/[0-9]/', $d['password'])) {
            throw new BusinessException('La contraseña del representante necesita 12 caracteres con mayúscula, minúscula y número.', 'WEAK_PASSWORD', 422);
        }
        $user = User::create(['name' => $d['name'], 'email' => $email, 'password' => Hash::make($d['password']), 'role' => 'empresa', 'empresa_id' => (string) $company->id, 'active' => true, 'failed_attempts' => 0]);
        $company->update(['miembros' => array_values(array_unique([...($company->miembros ?? []), (string) $user->id]))]);
        app(SecurityService::class)->record('usuario_creado', $actor, ['user_id' => (string) $user->id, 'role' => 'empresa', 'empresa_id' => (string) $company->id]);

        return $user->toArray();
    }

    /**
     * Everything of one company: its accounts with balances, representatives, collaborators and recent movements.
     */
    public function detail(Company $company): array
    {
        $id = (string) $company->id;
        $accounts = Account::where('empresa_id', $id)->orWhere('_id', (string) $company->cuenta_id)->orderBy('created_at')->get();
        $accountIds = $accounts->map(fn (Account $a) => (string) $a->id)->all();

        return $company->toArray() + [
            'cuentas' => $accounts->map(fn (Account $a): array => ['id' => (string) $a->id, 'numero' => $a->numero, 'tipo' => $a->tipo, 'nombre' => $a->nombre, 'estado' => $a->estado, 'saldo_centavos' => (int) $a->saldo_centavos, 'principal' => (string) $a->id === (string) $company->cuenta_id])->values(),
            'saldo_total_centavos' => (int) $accounts->sum('saldo_centavos'),
            'representantes' => User::where('role', 'empresa')->where('empresa_id', $id)->orderBy('created_at')->get()->map(fn (User $u): array => $u->toArray())->values(),
            'colaboradores' => Collaborator::where('empresa_id', $id)->count(),
            'planillas_pagadas' => Payroll::where('empresa_id', $id)->where('estado', 'pagada')->count(),
            'movimientos' => Movement::whereIn('cuenta_id', $accountIds)->orderBy('created_at', 'desc')->limit(15)->get()->map(fn (Movement $m): array => $m->toArray() + ['cuenta_numero' => $accounts->firstWhere('id', $m->cuenta_id)?->numero])->values(),
        ];
    }
}
