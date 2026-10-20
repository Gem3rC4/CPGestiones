<?php

namespace App\Http\Middleware;

use App\Exceptions\BusinessException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BankIntegration
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('actor') !== 'service:banca') {
            throw new BusinessException('Esta API requiere el token del servidor de Banca Virtual.', 'BANK_SERVICE_REQUIRED', 403);
        }

        return $next($request);
    }
}
