<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RequestContext
{
    public function handle(Request $r, Closure $next)
    {
        $r->attributes->set('request_id', (string) Str::uuid());
        $response = $next($r);
        $response->headers->set('X-Request-ID', $r->attributes->get('request_id'));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
