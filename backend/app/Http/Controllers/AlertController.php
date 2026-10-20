<?php

namespace App\Http\Controllers;

use App\Http\Requests\AlertReadRequest;
use App\Services\AlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertController extends ApiController
{
    public function index(Request $request, AlertService $service): JsonResponse
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1|max:100000']);

        return $this->ok($service->feed($request, (int) ($data['page'] ?? 1)));
    }

    public function read(AlertReadRequest $request, AlertService $service): JsonResponse
    {
        return $this->ok($service->markRead($request, $request->validated('ids')));
    }
}
