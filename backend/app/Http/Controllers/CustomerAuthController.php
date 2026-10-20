<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerLoginRequest;
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

        return $this->ok(['cliente' => $profiles->findProfile($customer->external_ref), 'expires_at' => $session->expires_at]);
    }

    public function logout(Request $request, CustomerAuthService $service): JsonResponse
    {
        $service->session($request->header('X-Cliente-Token', ''))->delete();

        return $this->ok(['sesion_cerrada' => true]);
    }
}
