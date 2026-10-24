<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Http\Requests\OperationRequest;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Repositories\CustomerRepository;
use App\Services\AccountService;
use App\Services\AuthService;
use App\Services\BankRegistrationService;
use App\Services\CustomerDeletionService;
use App\Services\MaintenanceService;
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
     * A customer registered at the bank. Accounts are opened afterwards from "Cuentas", and the customer
     * creates their own Banca Virtual password when registering there with their DPI and account number.
     */
    public function customer(OperationRequest $r)
    {
        return $this->mutation($r, function ($d, $a) {
            $d['email'] = mb_strtolower(trim($d['email']));
            // DPI is the single key that links this customer to Banca Virtual: it can never repeat.
            if (app(CustomerRepository::class)->byDpi($d['dpi'])) {
                throw new BusinessException('Ya existe un cliente registrado con ese DPI. Búscalo en el directorio en lugar de crearlo otra vez.', 'CUSTOMER_EXISTS', 409);
            }
            if (Customer::where('email', $d['email'])->exists()) {
                throw new BusinessException('Ya existe un cliente con ese correo electrónico.', 'CUSTOMER_EXISTS', 409);
            }
            app(BankRegistrationService::class)->assertSeniority($d['fecha_nacimiento'], (int) $d['antiguedad_laboral_meses']);
            $names = ['nombres' => trim(preg_replace('/\s+/u', ' ', $d['nombres'])), 'apellidos' => trim(preg_replace('/\s+/u', ' ', $d['apellidos']))];
            $c = Customer::create($names + [
                'nombre' => $names['nombres'].' '.$names['apellidos'], 'email' => $d['email'], 'dpi' => $d['dpi'], 'nit' => mb_strtoupper($d['nit']), 'telefono' => $d['telefono'],
                'fecha_nacimiento' => $d['fecha_nacimiento'], 'ingreso_centavos' => Money::cents($d['ingreso_mensual'], true), 'antiguedad_laboral_meses' => (int) $d['antiguedad_laboral_meses'],
                'perfil_version' => 1, 'origen' => 'agencia', 'active' => true, 'failed_attempts' => 0,
            ]);
            app(SecurityService::class)->record('cliente_creado', $a, ['cliente_id' => (string) $c->id]);

            return $c->refresh()->toArray();
        }, 201);
    }

    /**
     * Corrects or updates the customer file (income, phone, e-mail, a typo…). DPI and e-mail stay unique. The
     * e-mail can change even for Banca Virtual users (they sign in with the new one); the DPI cannot, because it
     * is what links them to Banca Virtual.
     */
    public function updateCustomer(OperationRequest $r, string $id)
    {
        return $this->mutation($r, function ($d, $a) use ($id): array {
            $customer = Customer::where('segmento', '!=', 'empresa')->findOrFail($id);
            $changes = [];
            if (isset($d['email'])) {
                $email = mb_strtolower(trim($d['email']));
                if ($email !== mb_strtolower((string) $customer->email)) {
                    // Allowed even for Banca Virtual users: from now on they sign in there with the new e-mail.
                    if (Customer::where('email', $email)->where('_id', '!=', $customer->id)->exists()) {
                        throw new BusinessException('Ya existe otro cliente con ese correo electrónico.', 'CUSTOMER_EXISTS', 409);
                    }
                    $changes['email'] = $email;
                }
            }
            if (isset($d['dpi']) && $d['dpi'] !== $customer->dpi) {
                if ($customer->password) {
                    throw new BusinessException('El cliente ya usa Banca Virtual con este DPI; no se puede cambiar desde aquí.', 'APP_IDENTITY_LOCKED', 409);
                }
                $owner = app(CustomerRepository::class)->byDpi($d['dpi']);
                if ($owner && (string) $owner->id !== (string) $customer->id) {
                    throw new BusinessException('Ya existe otro cliente registrado con ese DPI.', 'CUSTOMER_EXISTS', 409);
                }
                $changes['dpi'] = $d['dpi'];
            }
            foreach (['nombres', 'apellidos'] as $field) {
                if (isset($d[$field])) {
                    $changes[$field] = trim(preg_replace('/\s+/u', ' ', $d[$field]));
                }
            }
            if (isset($changes['nombres']) || isset($changes['apellidos'])) {
                $changes['nombre'] = trim(($changes['nombres'] ?? $customer->nombres).' '.($changes['apellidos'] ?? $customer->apellidos));
            }
            if (isset($d['nit'])) {
                $changes['nit'] = mb_strtoupper($d['nit']);
            }
            foreach (['telefono', 'fecha_nacimiento'] as $field) {
                if (isset($d[$field])) {
                    $changes[$field] = $d[$field];
                }
            }
            if (isset($d['ingreso_mensual'])) {
                $changes['ingreso_centavos'] = Money::cents($d['ingreso_mensual'], true);
            }
            if (isset($d['antiguedad_laboral_meses'])) {
                $changes['antiguedad_laboral_meses'] = (int) $d['antiguedad_laboral_meses'];
            }
            $changes = array_filter($changes, fn ($value, $field): bool => $customer->{$field} !== $value, ARRAY_FILTER_USE_BOTH);
            $birth = $changes['fecha_nacimiento'] ?? $customer->fecha_nacimiento;
            $months = $changes['antiguedad_laboral_meses'] ?? $customer->antiguedad_laboral_meses;
            if ($birth && $months !== null && (isset($changes['fecha_nacimiento']) || isset($changes['antiguedad_laboral_meses']))) {
                app(BankRegistrationService::class)->assertSeniority($birth, (int) $months);
            }
            if ($changes !== []) {
                $customer->update($changes + ['perfil_version' => (int) ($customer->perfil_version ?? 0) + 1]);
                app(SecurityService::class)->record('cliente_actualizado', $a, ['cliente_id' => (string) $customer->id, 'campos' => array_values(array_diff(array_keys($changes), ['nombre']))]);
            }

            return $customer->refresh()->toArray() + ['campos_actualizados' => array_values(array_diff(array_keys($changes), ['nombre']))];
        });
    }

    /**
     * What the customer still has in the bank, so the panel can warn before deleting (never blocks it).
     */
    public function deletionSummary(string $id, CustomerDeletionService $service)
    {
        return $this->ok($service->summary(Customer::where('segmento', '!=', 'empresa')->findOrFail($id)));
    }

    public function deleteCustomer(OperationRequest $r, string $id, CustomerDeletionService $service)
    {
        return $this->mutation($r, fn ($d, $a): array => $service->delete(Customer::where('segmento', '!=', 'empresa')->findOrFail($id), $a));
    }

    public function companyDeletionSummary(string $id, CustomerDeletionService $service)
    {
        return $this->ok($service->companySummary(Company::findOrFail($id)));
    }

    public function deleteCompany(OperationRequest $r, string $id, CustomerDeletionService $service)
    {
        return $this->mutation($r, fn ($d, $a): array => $service->deleteCompany(Company::findOrFail($id), $a));
    }

    /**
     * Current balance of the master account, for the "add funds" form.
     */
    public function vault(AccountService $accounts)
    {
        $master = $accounts->master();

        return $this->ok(['numero' => $master->numero, 'nombre' => $master->nombre, 'saldo_centavos' => (int) $master->saldo_centavos, 'saldo' => Money::format((int) $master->saldo_centavos)]);
    }

    /**
     * Idempotency-Key makes each deposit apply once, however many times the button is clicked or retried.
     */
    public function fundVault(OperationRequest $r, AccountService $accounts)
    {
        return $this->mutation($r, function ($d, $a) use ($r, $accounts): array {
            $name = $r->user()?->name;

            return $accounts->fundVault(Money::cents($d['monto']), $a, $d['concepto']) + ['responsable_nombre' => $name];
        }, 201);
    }

    public function resetAll(OperationRequest $r, MaintenanceService $maintenance)
    {
        if (! $r->user()?->protegido) {
            throw new BusinessException('Solo el administrador principal puede reiniciar los datos.', 'ADMIN_PROTECTED', 403);
        }

        $r->validated();

        // Runs its own transaction; the confirmation phrase guards it instead of an Idempotency-Key.
        return $this->ok(['limpieza' => 'completada', 'eliminados' => $maintenance->resetAll($r->attributes->get('actor'))]);
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
