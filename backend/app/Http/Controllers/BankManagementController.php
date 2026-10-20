<?php

namespace App\Http\Controllers;

use App\Http\Requests\BankEventRequest;
use App\Http\Requests\BankManagementRequest;
use App\Http\Requests\RiskAssessmentRequest;
use App\Services\BankEventService;
use App\Services\BankManagementService;
use Illuminate\Http\JsonResponse;

class BankManagementController extends ApiController
{
    public function store(BankManagementRequest $request, BankManagementService $service): JsonResponse
    {
        return $this->mutation($request, fn (array $data): array => $service->receive($data), 201);
    }

    public function assessment(RiskAssessmentRequest $request, BankManagementService $service): JsonResponse
    {
        return $this->mutation($request, fn (array $data): array => $service->assessment($data), 201);
    }

    public function event(BankEventRequest $request, BankEventService $service): JsonResponse
    {
        return $this->mutation($request, fn (array $data): array => $service->receive($data), 201);
    }
}
