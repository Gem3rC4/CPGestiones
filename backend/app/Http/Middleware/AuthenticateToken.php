<?php

namespace App\Http\Middleware;

use App\Exceptions\BusinessException;
use App\Models\AccessToken;
use App\Models\User;
use App\Models\UserSession;
use App\Services\AuthService;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;

/**
 * Two kinds of bearer credentials:
 * - Staff panel: JWT access token (HS256, 15 min) tied to an open session (sid) that logout revokes.
 * - Integrations (Banca Virtual, CP Analítica): opaque 64-character service tokens issued by the administrator.
 */
class AuthenticateToken
{
    public function handle(Request $r, Closure $next)
    {
        $plain = (string) $r->bearerToken();
        if (JwtService::looksLikeJwt($plain)) {
            $this->staff($r, $plain);

            return $next($r);
        }
        if (strlen($plain) !== 64) {
            throw new BusinessException('Inicia sesión para continuar.', 'UNAUTHENTICATED', 401);
        }
        $token = AccessToken::where('token_hash', hash('sha256', $plain))->first();
        if (! $token || ! $token->expires_at || $token->expires_at->isPast()) {
            throw new BusinessException('La sesión venció. Ingresa de nuevo.', 'SESSION_EXPIRED', 401);
        }
        if ($token->kind === 'user') {
            $this->actAs($r, User::find($token->user_id));
        } else {
            $r->attributes->set('actor', 'service:'.$token->client);
            $r->attributes->set('permissions', AuthService::servicePermissions((string) $token->client));
        }
        $r->attributes->set('access_token', $token);

        return $next($r);
    }

    private function staff(Request $r, string $jwt): void
    {
        $claims = app(JwtService::class)->decode($jwt);
        $session = is_string($claims['sid'] ?? null) ? UserSession::find($claims['sid']) : null;
        if (! $session || $session->revoked_at || $session->expires_at->isPast() || $session->user_id !== ($claims['sub'] ?? null)) {
            throw new BusinessException('La sesión terminó. Inicia sesión de nuevo.', 'SESSION_EXPIRED', 401);
        }
        $this->actAs($r, User::find($session->user_id));
        $r->attributes->set('user_session', $session);
    }

    private function actAs(Request $r, ?User $user): void
    {
        if (! $user || ! $user->active || ($user->locked_until && $user->locked_until->isFuture())) {
            throw new BusinessException('El acceso está bloqueado.', 'ACCESS_BLOCKED', 401);
        }
        $r->setUserResolver(fn () => $user);
        $r->attributes->set('actor', (string) $user->id);
        $r->attributes->set('permissions', app(AuthService::class)->permissions($user));
    }
}
