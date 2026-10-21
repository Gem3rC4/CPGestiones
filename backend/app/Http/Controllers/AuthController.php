<?php

namespace App\Http\Controllers;

use App\Http\Requests\OperationRequest;
use App\Models\Company;
use App\Services\AuthService;
use Illuminate\Http\Request;

class AuthController extends ApiController
{
    public function login(OperationRequest $r, AuthService $auth)
    {
        return $this->ok($auth->login($r->validated('email'), $r->validated('password')));
    }

    /**
     * Exchanges a valid refresh token for a new access JWT and a new refresh token (rotation).
     */
    public function refresh(OperationRequest $r, AuthService $auth)
    {
        return $this->ok($auth->refresh($r->validated('refresh_token')));
    }

    public function me(Request $r)
    {
        $user = $r->user();
        $company = $user?->empresa_id ? Company::find($user->empresa_id) : null;

        return $this->ok(['user' => $user?->toArray(), 'permissions' => $r->attributes->get('permissions'), 'client' => $r->attributes->get('access_token')?->client, 'empresa' => $company ? ['id' => (string) $company->id, 'nombre' => $company->nombre] : null]);
    }

    public function logout(Request $r, AuthService $auth)
    {
        if ($session = $r->attributes->get('user_session')) {
            $auth->closeSession((string) $session->id);
        } else {
            $r->attributes->get('access_token')?->delete();
        }

        return $this->ok(['message' => 'Sesión cerrada.']);
    }
}
