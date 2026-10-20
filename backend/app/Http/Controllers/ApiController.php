<?php

namespace App\Http\Controllers;

use App\Http\Requests\OperationRequest;
use App\Services\IdempotencyService;

abstract class ApiController extends Controller
{
    protected function ok(mixed $data, int $status = 200)
    {
        return response()->json(['ok' => true, 'data' => $data], $status);
    }

    protected function mutation(OperationRequest $r, callable $callback, int $status = 200)
    {
        $actor = $r->attributes->get('actor');
        $data = $r->validated();
        $result = app(IdempotencyService::class)->run($actor, $r->header('Idempotency-Key', ''), $r->method().':'.$r->path(), $data, fn () => $callback($data, $actor));

        return $this->ok($result, $status);
    }
}
