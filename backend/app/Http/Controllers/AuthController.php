<?php

namespace App\Http\Controllers;

use App\Http\Requests\OperationRequest;
use App\Services\AuthService;
use Illuminate\Http\Request;

class AuthController extends ApiController
{
    public function login(OperationRequest $r, AuthService $auth)
    {
        return $this->ok($auth->login($r->validated('email'), $r->validated('password')));
    }

    public function me(Request $r, AuthService $auth)
    {
        return $this->ok(['user' => $r->user()?->toArray(), 'permissions' => $r->attributes->get('permissions'), 'client' => $r->attributes->get('access_token')->client]);
    }

    public function logout(Request $r)
    {
        $r->attributes->get('access_token')->delete();

        return $this->ok(['message' => 'Sesión cerrada.']);
    }
}
