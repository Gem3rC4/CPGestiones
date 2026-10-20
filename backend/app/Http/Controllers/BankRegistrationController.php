<?php

namespace App\Http\Controllers;

use App\Http\Requests\BankAccountRequest;
use App\Http\Requests\BankApplicationRequest;
use App\Http\Requests\BankCustomerRequest;
use App\Http\Requests\BankFinancialProfileRequest;
use App\Http\Requests\BankRegistrationRequest;
use App\Repositories\CustomerRepository;
use App\Services\BankApplicationService;
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
        return $this->ok($service->findProfile($request->validated()));
    }

    public function summary(BankCustomerRequest $request, BankRegistrationService $service, CustomerRepository $customers): JsonResponse
    {
        return $this->ok($service->summary($customers->resolve($request->validated())));
    }

    public function updateFinancialProfile(BankFinancialProfileRequest $request, BankRegistrationService $service): JsonResponse
    {
        return $this->mutation($request, fn (array $data, string $actor): array => $service->updateFinancialProfile($data, $actor));
    }

    public function openAccount(BankAccountRequest $request, BankRegistrationService $service): JsonResponse
    {
        return $this->mutation($request, fn (array $data, string $actor): array => $service->openAccount($data, $actor), 201);
    }

    public function requestApplication(BankApplicationRequest $request, BankApplicationService $service): JsonResponse
    {
        return $this->mutation($request, fn (array $data, string $actor): array => $service->request($data, $actor), 201);
    }
}
