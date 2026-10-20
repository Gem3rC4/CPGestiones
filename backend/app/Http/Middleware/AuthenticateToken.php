<?php

namespace App\Http\Middleware;

use App\Exceptions\BusinessException;
use App\Models\AccessToken;
use App\Models\User;
use App\Services\AuthService;
use Closure;
use Illuminate\Http\Request;

class AuthenticateToken
{
    public function handle(Request $r, Closure $next)
    {
        $plain = $r->bearerToken();
        if (! $plain || strlen($plain) !== 64) {
            throw new BusinessException('Inicia sesión para continuar.', 'UNAUTHENTICATED', 401);
        }
        $token = AccessToken::where('token_hash', hash('sha256', $plain))->first();
        if (! $token || ! $token->expires_at || $token->expires_at->isPast()) {
            throw new BusinessException('La sesión venció. Ingresa de nuevo.', 'SESSION_EXPIRED', 401);
        }
        if ($token->kind === 'user') {
            $user = User::find($token->user_id);
            if (! $user || ! $user->active || ($user->locked_until && $user->locked_until->isFuture())) {
                throw new BusinessException('El acceso está bloqueado.', 'ACCESS_BLOCKED', 401);
            }
            $r->setUserResolver(fn () => $user);
            $r->attributes->set('actor', (string) $user->id);
            $r->attributes->set('permissions', app(AuthService::class)->permissions($user));
        } else {
            $r->attributes->set('actor', 'service:'.$token->client);
            $r->attributes->set('permissions', $token->permissions);
        }
        $r->attributes->set('access_token', $token);

        return $next($r);
    }
}
