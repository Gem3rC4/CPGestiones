<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerLoginRequest;
use App\Http\Requests\CustomerPasswordRequest;
use App\Http\Requests\CustomerRecoveryRequest;
use App\Models\Customer;
use App\Services\BankRegistrationService;
use App\Services\CustomerAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerAuthController extends ApiController
{
    public function login(CustomerLoginRequest $request, CustomerAuthService $service): JsonResponse
    {
        return $this->ok($service->login($request->validated()));
    }

    public function session(Request $request, CustomerAuthService $service, BankRegistrationService $profiles): JsonResponse
    {
        $session = $service->session($request->header('X-Cliente-Token', ''));
        $customer = Customer::findOrFail($session->cliente_id);

        return $this->ok(['cliente' => $profiles->sessionProfile($customer), 'expires_at' => $session->expires_at]);
    }

    public function logout(Request $request, CustomerAuthService $service): JsonResponse
    {
        $service->session($request->header('X-Cliente-Token', ''))->delete();

        return $this->ok(['sesion_cerrada' => true]);
    }

    public function password(CustomerPasswordRequest $request, CustomerAuthService $service): JsonResponse
    {
        $session = $service->session($request->header('X-Cliente-Token', ''));

        return $this->ok($service->changePassword($session, $request->validated('contrasena_actual'), $request->validated('contrasena_nueva')));
    }

    public function recover(CustomerRecoveryRequest $request, CustomerAuthService $service): JsonResponse
    {
        return $this->ok($service->recover($request->validated()));
    }

    public function sessions(Request $request, CustomerAuthService $service): JsonResponse
    {
        return $this->ok($service->sessions($service->session($request->header('X-Cliente-Token', ''))));
    }

    public function revokeSession(Request $request, string $id, CustomerAuthService $service): JsonResponse
    {
        return $this->ok($service->revokeSession($service->session($request->header('X-Cliente-Token', '')), $id));
    }
}
