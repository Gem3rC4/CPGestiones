<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Http\Requests\OperationRequest;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Repositories\CustomerRepository;
use App\Services\AccountService;
use App\Services\AuthService;
use App\Services\SecurityService;
use App\Support\Money;
use Illuminate\Support\Facades\Hash;

class AdminController extends ApiController
{
    public function user(OperationRequest $r)
    {
        return $this->mutation($r, function ($d, $a) {
            $d['email'] = mb_strtolower(trim($d['email']));
            if (User::where('email', $d['email'])->exists()) {
                throw new BusinessException('Ya existe un usuario con ese correo.', 'USER_EXISTS', 409);
            }
            $d = $this->companyLink($d);
            $d['password'] = Hash::make($d['password']);
            $d['active'] = true;
            $d['failed_attempts'] = 0;
            $u = User::create($d);
            $this->syncCompanyMembers($u, null);
            app(SecurityService::class)->record('usuario_creado', $a, ['user_id' => (string) $u->id, 'role' => $u->role]);

            return $u->toArray();
        }, 201);
    }

    public function resetUserPassword(OperationRequest $r, string $id)
    {
        return $this->mutation($r, function ($d, $a) use ($id) {
            $u = User::findOrFail($id);
            if ($u->protegido && $id !== $a) {
                throw new BusinessException('El administrador principal está protegido: solo él puede cambiar su contraseña.', 'ADMIN_PROTECTED', 403);
            }
            $u->update(['password' => Hash::make($d['contrasena']), 'failed_attempts' => 0, 'locked_until' => null]);
            // Other sessions of that person end; an administrator changing their own password keeps working.
            $session = request()->attributes->get('user_session');
            $token = request()->attributes->get('access_token');
            app(AuthService::class)->closeUserSessions($id, $id === $a && $session ? (string) $session->id : null, $id === $a ? $token?->token_hash : null);
            app(SecurityService::class)->record('contrasena_usuario_restablecida', $a, ['user_id' => $id]);

            return $u->refresh()->toArray();
        });
    }

    public function updateUser(OperationRequest $r, string $id)
    {
        return $this->mutation($r, function ($d, $a) use ($id) {
            if ($id === $a) {
                throw new BusinessException('Otro administrador debe cambiar tu propio acceso.', 'SELF_ROLE_CHANGE', 403);
            }
            $u = User::findOrFail($id);
            if ($u->protegido) {
                throw new BusinessException('El administrador principal está protegido: su rol y su acceso no se pueden cambiar.', 'ADMIN_PROTECTED', 403);
            }
            $previousCompany = $u->empresa_id;
            $d = $this->companyLink($d);
            // Only the "empresa" role belongs to a company.
            $u->update($d + ['failed_attempts' => 0, 'locked_until' => null] + ($d['role'] !== 'empresa' ? ['empresa_id' => null] : []));
            $this->syncCompanyMembers($u->refresh(), $previousCompany);
            app(AuthService::class)->closeUserSessions($id);
            app(SecurityService::class)->record('acceso_actualizado', $a, ['user_id' => $id, 'role' => $d['role'], 'active' => $d['active']]);

            return $u->toArray();
        });
    }

    /**
     * A customer registered at the bank. Optionally it gets its Banca Virtual access (password) and its first
     * savings or checking account in the same atomic operation, so it can log in and receive payroll right away.
     */
    public function customer(OperationRequest $r)
    {
        return $this->mutation($r, function ($d, $a) {
            $d['email'] = mb_strtolower(trim($d['email']));
            // DPI is the key that links this branch customer to their Banca Virtual profile.
            if (app(CustomerRepository::class)->byDpi($d['dpi']) || Customer::where('email', $d['email'])->exists()) {
                throw new BusinessException('Ya existe un cliente con ese DPI o correo electrónico.', 'CUSTOMER_EXISTS', 409);
            }
            $password = $d['contrasena'] ?? null;
            $accountProduct = $d['producto_cuenta'] ?? null;
            $d['ingreso_centavos'] = Money::cents($d['ingreso_mensual'], true);
            unset($d['ingreso_mensual'], $d['contrasena'], $d['producto_cuenta']);
            if (empty($d['fecha_nacimiento'])) {
                unset($d['fecha_nacimiento']);
            }
            $names = preg_split('/\s+/', trim($d['nombre']), 2);
            $c = Customer::create($d + ['nombres' => $names[0], 'apellidos' => $names[1] ?? '', 'perfil_version' => 1, 'origen' => 'agencia', 'active' => true, 'failed_attempts' => 0]
                + ($password ? ['password' => Hash::make($password), 'acceso_app_desde' => now()->toIso8601String()] : []));
            $account = $accountProduct ? app(AccountService::class)->open(['cliente_id' => (string) $c->id, 'producto' => $accountProduct], $a) : null;
            app(SecurityService::class)->record('cliente_creado', $a, ['cliente_id' => (string) $c->id, 'acceso_app' => (bool) $password, 'cuenta' => $account['numero'] ?? null]);

            return $c->refresh()->toArray() + ['cuenta' => $account];
        }, 201);
    }

    public function product(OperationRequest $r, string $id)
    {
        return $this->mutation($r, function ($d, $a) use ($id) {
            $p = Product::findOrFail($id);
            $p->update($d);
            app(SecurityService::class)->record('politica_producto', $a, ['producto' => $p->code, 'policy' => $d]);

            return $p->toArray();
        });
    }

    /**
     * The "empresa" role belongs to exactly one registered company.
     */
    private function companyLink(array $d): array
    {
        if ($d['role'] === 'empresa') {
            if (empty($d['empresa_id']) || ! Company::where('_id', $d['empresa_id'])->exists()) {
                throw new BusinessException('Selecciona la empresa que representará este usuario.', 'COMPANY_REQUIRED', 422);
            }
        } else {
            unset($d['empresa_id']);
        }

        return $d;
    }

    private function syncCompanyMembers(User $user, ?string $previousCompany): void
    {
        $id = (string) $user->id;
        $current = $user->role === 'empresa' ? $user->empresa_id : null;
        if ($previousCompany && $previousCompany !== $current && $company = Company::find($previousCompany)) {
            $company->update(['miembros' => array_values(array_diff($company->miembros ?? [], [$id]))]);
        }
        if ($current && $company = Company::find($current)) {
            $company->update(['miembros' => array_values(array_unique([...($company->miembros ?? []), $id]))]);
        }
    }
}
