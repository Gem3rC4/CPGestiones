<?php

namespace App\Services;

use App\Models\SecurityEvent;

class SecurityService
{
    public function record(string $action, string $actor, array $detail = []): void
    {
        SecurityEvent::create(['action' => $action, 'actor' => $actor, 'detail' => $detail, 'ip' => app()->runningInConsole() ? 'console' : request()->ip(), 'request_id' => request()->attributes->get('request_id')]);
    }
}
