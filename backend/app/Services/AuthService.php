<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\AccessToken;
use App\Models\User;
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
            $plain = bin2hex(random_bytes(32));
            AccessToken::create(['token_hash' => hash('sha256', $plain), 'user_id' => (string) $user->id, 'expires_at' => now()->addMinutes(config('operations.token_minutes')), 'kind' => 'user']);
            app(SecurityService::class)->record('login', (string) $user->id);

            return ['token' => $plain, 'token_type' => 'Bearer', 'expires_in' => config('operations.token_minutes') * 60, 'user' => $user->toArray(), 'permissions' => $this->permissions($user)];
        }, attempts: 5);
        if (! $result) {
            throw new BusinessException('Credenciales incorrectas o acceso temporalmente bloqueado.', 'INVALID_CREDENTIALS', 401);
        }

        return $result;
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
        $permissions = match ($client) {
            'banca' => ['clientes.read', 'clientes.write', 'productos.read', 'cuentas.read', 'cuentas.write', 'dinero.write', 'solicitudes.read', 'solicitudes.write', 'solicitudes.accept', 'tarjetas.read', 'tarjetas.write'],
            'riesgos' => ['riesgos.read', 'riesgos.decide'],
            default => throw new BusinessException('Cliente de integración desconocido.', 'INVALID_CLIENT', 422),
        };
        $plain = bin2hex(random_bytes(32));
        $token = AccessToken::create(['token_hash' => hash('sha256', $plain), 'client' => $client, 'permissions' => $permissions, 'kind' => 'service', 'hint' => substr($plain, -4), 'created_by' => $actor, 'expires_at' => now()->addDays($days)]);

        return ['id' => (string) $token->id, 'token' => $plain, 'client' => $client, 'hint' => $token->hint, 'expires_at' => $token->expires_at->toIso8601String()];
    }
}
