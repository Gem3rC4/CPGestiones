<?php

namespace App\Http\Middleware;

use App\Exceptions\BusinessException;
use Closure;
use Illuminate\Http\Request;

class Permission
{
    public function handle(Request $r, Closure $next, string $permission)
    {
        $abilities = $r->attributes->get('permissions', []);
        // Risk decisions are reserved to the risk service, including against administrator tokens.
        if ($permission === 'riesgos.decide' && $r->attributes->get('actor') !== 'service:riesgos') {
            throw new BusinessException('Esta decisión corresponde a CP Analítica.', 'FORBIDDEN', 403);
        }
        if (! in_array('*', $abilities, true) && ! in_array($permission, $abilities, true)) {
            throw new BusinessException('Tu rol no tiene permiso para esta acción.', 'FORBIDDEN', 403);
        }

        return $next($r);
    }
}
