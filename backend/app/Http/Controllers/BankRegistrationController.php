<?php

namespace App\Http\Controllers;

use App\Http\Requests\BankCustomerRequest;
use App\Http\Requests\BankFinancialProfileRequest;
use App\Http\Requests\BankRegistrationRequest;
use App\Services\BankRegistrationService;
use Illuminate\Http\JsonResponse;

class BankRegistrationController extends ApiController
{
    public function store(BankRegistrationRequest $request, BankRegistrationService $service): JsonResponse
    {
        return $this->mutation($request, fn (array $data, string $actor): array => $service->register($data, $actor), 201);
    }

    public function profile(BankCustomerRequest $request, BankRegistrationService $service): JsonResponse
    {
        return $this->ok($service->findProfile($request->validated('cliente_ref')));
    }

    public function summary(BankCustomerRequest $request, BankRegistrationService $service): JsonResponse
    {
        return $this->ok($service->summary($request->validated('cliente_ref')));
    }

    public function updateFinancialProfile(BankFinancialProfileRequest $request, BankRegistrationService $service): JsonResponse
    {
        return $this->mutation($request, fn (array $data, string $actor): array => $service->updateFinancialProfile($data, $actor));
    }
}
