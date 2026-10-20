<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Http\Requests\OperationRequest;
use App\Models\AccessToken;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Repositories\CustomerRepository;
use App\Services\SecurityService;
use App\Support\Money;
use Illuminate\Support\Facades\Hash;

class AdminController extends ApiController
{
    public function user(OperationRequest $r)
    {
        return $this->mutation($r, function ($d, $a) {
            $d['email'] = mb_strtolower(trim($d['email']));
            $d['password'] = Hash::make($d['password']);
            $d['active'] = true;
            $d['failed_attempts'] = 0;
            $u = User::create($d);
            app(SecurityService::class)->record('usuario_creado', $a, ['user_id' => (string) $u->id, 'role' => $u->role]);

            return $u->toArray();
        }, 201);
    }

    public function updateUser(OperationRequest $r, string $id)
    {
        return $this->mutation($r, function ($d, $a) use ($id) {
            if ($id === $a) {
                throw new BusinessException('Otro administrador debe cambiar tu propio acceso.', 'SELF_ROLE_CHANGE', 403);
            }
            $u = User::findOrFail($id);
            $u->update($d + ['failed_attempts' => 0, 'locked_until' => null]);
            AccessToken::where('user_id', $id)->delete();
            app(SecurityService::class)->record('acceso_actualizado', $a, ['user_id' => $id, 'role' => $d['role'], 'active' => $d['active']]);

            return $u->toArray();
        });
    }

    public function customer(OperationRequest $r)
    {
        return $this->mutation($r, function ($d, $a) {
            $d['email'] = mb_strtolower(trim($d['email']));
            // DPI is the key that later links this branch customer to their Banca Virtual profile.
            if (app(CustomerRepository::class)->byDpi($d['dpi']) || Customer::where('email', $d['email'])->exists()) {
                throw new BusinessException('Ya existe un cliente con ese DPI o correo electrónico.', 'CUSTOMER_EXISTS', 409);
            }
            $d['ingreso_centavos'] = Money::cents($d['ingreso_mensual'], true);
            unset($d['ingreso_mensual']);
            if (empty($d['fecha_nacimiento'])) {
                unset($d['fecha_nacimiento']);
            }
            $c = Customer::create($d + ['perfil_version' => 1, 'origen' => 'agencia', 'active' => true, 'failed_attempts' => 0]);
            app(SecurityService::class)->record('cliente_creado', $a, ['cliente_id' => (string) $c->id]);

            return $c->toArray();
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
}
