<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\AccessToken;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function login(string $email, string $password): array
    {
        $email = mb_strtolower(trim($email));
        $result = DB::transaction(function () use ($email, $password) {
            $user = User::where('email', $email)->first();
            if (! $user) {
                Hash::check($password, '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');

                return null;
            }
            if (! $user->active || ($user->locked_until && $user->locked_until->isFuture())) {
                return null;
            }
            if (! Hash::check($password, $user->password)) {
                // An expired lock starts a new window; otherwise one typo after the lock re-locks the user.
                $count = ($user->locked_until?->isPast() ? 0 : (int) $user->failed_attempts) + 1;
                $locked = $count >= config('operations.max_failures');
                $user->update(['failed_attempts' => $count, 'locked_until' => $locked ? now()->addMinutes(config('operations.lock_minutes')) : null]);
                app(SecurityService::class)->record('login_fallido', (string) $user->id, ['bloqueado' => $locked]);

                return null;
            }
            $user->update(['failed_attempts' => 0, 'locked_until' => null]);
            app(SecurityService::class)->record('login', (string) $user->id);

            return $this->startSession($user);
        }, attempts: 5);
        if (! $result) {
            throw new BusinessException('Credenciales incorrectas o acceso temporalmente bloqueado.', 'INVALID_CREDENTIALS', 401);
        }

        return $result;
    }

    /**
     * Opens a panel session: a short-lived JWT access token plus an opaque refresh token (only its hash is stored).
     */
    public function startSession(User $user): array
    {
        $refresh = bin2hex(random_bytes(32));
        $session = UserSession::create(['user_id' => (string) $user->id, 'refresh_hash' => hash('sha256', $refresh), 'expires_at' => now()->addHours(config('operations.jwt.refresh_hours')), 'ip' => request()->ip(), 'dispositivo' => mb_substr((string) request()->userAgent(), 0, 160), 'renovaciones' => 0]);

        return $this->tokens($user, $session, $refresh);
    }

    /**
     * Rotates the refresh token. Presenting an already rotated token means it was copied: that session is closed.
     */
    public function refresh(string $refresh): array
    {
        $hash = hash('sha256', $refresh);
        $result = DB::transaction(function () use ($hash): ?array {
            $session = UserSession::where('refresh_hash', $hash)->first();
            if (! $session) {
                $reused = UserSession::where('refresh_prev_hash', $hash)->whereNull('revoked_at')->first();
                if ($reused) {
                    $reused->update(['revoked_at' => now(), 'motivo_cierre' => 'refresh_reutilizado']);
                    app(SecurityService::class)->record('refresh_reutilizado', (string) $reused->user_id, ['sesion_id' => (string) $reused->id]);
                }

                return null;
            }
            $user = User::find($session->user_id);
            if ($session->revoked_at || $session->expires_at->isPast() || ! $user || ! $user->active || $user->locked_until?->isFuture()) {
                return null;
            }
            $next = bin2hex(random_bytes(32));
            $session->update(['refresh_prev_hash' => $hash, 'refresh_hash' => hash('sha256', $next), 'renovaciones' => (int) $session->renovaciones + 1, 'ultima_renovacion' => now()]);

            return $this->tokens($user, $session, $next);
        }, attempts: 5);
        if (! $result) {
            throw new BusinessException('La sesión terminó. Inicia sesión de nuevo.', 'SESSION_EXPIRED', 401);
        }

        return $result;
    }

    public function closeSession(string $sessionId): void
    {
        UserSession::where('_id', $sessionId)->whereNull('revoked_at')->update(['revoked_at' => now(), 'motivo_cierre' => 'logout']);
    }

    /**
     * Ends the open panel sessions of a user (password reset, role change, deactivation).
     */
    public function closeUserSessions(string $userId, ?string $exceptSession = null, ?string $exceptTokenHash = null): void
    {
        UserSession::where('user_id', $userId)->whereNull('revoked_at')->when($exceptSession, fn ($q) => $q->where('_id', '!=', $exceptSession))->update(['revoked_at' => now(), 'motivo_cierre' => 'cambio_de_acceso']);
        AccessToken::where('user_id', $userId)->when($exceptTokenHash, fn ($q) => $q->where('token_hash', '!=', $exceptTokenHash))->delete();
    }

    public function permissions(User $user): array
    {
        return config('operations.roles.'.$user->role, []);
    }

    public function issueService(string $client, int $days = 30, string $actor = 'console'): string
    {
        return $this->createServiceToken($client, $days, $actor)['token'];
    }

    /**
     * The plain token is returned once and never stored; only its hash and last four characters are kept.
     *
     * @return array{id: string, token: string, client: string, hint: string, expires_at: string}
     */
    public function createServiceToken(string $client, int $days, string $actor): array
    {
        $permissions = self::servicePermissions($client);
        $plain = bin2hex(random_bytes(32));
        $token = AccessToken::create(['token_hash' => hash('sha256', $plain), 'client' => $client, 'permissions' => $permissions, 'kind' => 'service', 'hint' => substr($plain, -4), 'created_by' => $actor, 'expires_at' => now()->addDays($days)]);

        return ['id' => (string) $token->id, 'token' => $plain, 'client' => $client, 'hint' => $token->hint, 'expires_at' => $token->expires_at->toIso8601String()];
    }

    /**
     * Evaluated on every request, so tokens already handed to Banca Virtual gain new APIs without reissuing them.
     */
    public static function servicePermissions(string $client): array
    {
        return match ($client) {
            'banca' => ['clientes.read', 'clientes.write', 'productos.read', 'cuentas.read', 'cuentas.write', 'dinero.write', 'solicitudes.read', 'solicitudes.write', 'solicitudes.accept', 'tarjetas.read', 'tarjetas.write', 'creditos.read', 'creditos.pay'],
            'riesgos' => ['riesgos.read', 'riesgos.decide'],
            default => throw new BusinessException('Cliente de integración desconocido.', 'INVALID_CLIENT', 422),
        };
    }

    private function tokens(User $user, UserSession $session, string $refresh): array
    {
        $minutes = config('operations.jwt.access_minutes');
        $now = now()->timestamp;
        $access = app(JwtService::class)->encode(['iss' => config('operations.jwt.issuer'), 'sub' => (string) $user->id, 'sid' => (string) $session->id, 'role' => $user->role, 'iat' => $now, 'exp' => $now + $minutes * 60, 'jti' => bin2hex(random_bytes(8))]);

        return ['token' => $access, 'token_type' => 'Bearer', 'expires_in' => $minutes * 60, 'refresh_token' => $refresh, 'refresh_expires_at' => $session->expires_at->toIso8601String(), 'user' => $user->toArray(), 'permissions' => $this->permissions($user)];
    }
}
