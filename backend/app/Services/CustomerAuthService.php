<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\CustomerSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CustomerAuthService
{
    public function login(array $data): array
    {
        $result = DB::connection('mongodb')->transaction(function () use ($data): ?array {
            $customer = Customer::where('email', $data['correo_electronico'])->first();
            if (! $customer || ! $customer->password || ! $customer->active || $customer->locked_until?->isFuture()) {
                Hash::check($data['contrasena'], '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');

                return null;
            }
            if (! Hash::check($data['contrasena'], $customer->password)) {
                $failures = ($customer->locked_until?->isPast() ? 0 : (int) $customer->failed_attempts) + 1;
                $customer->update(['failed_attempts' => $failures, 'locked_until' => $failures >= config('operations.max_failures') ? now()->addMinutes(config('operations.lock_minutes')) : null]);
                app(SecurityService::class)->record('login_cliente_fallido', 'cliente:'.$customer->id);

                return null;
            }
            $changes = ['failed_attempts' => 0, 'locked_until' => null];
            if (Hash::needsRehash($customer->password)) {
                $changes['password'] = Hash::make($data['contrasena']);
            }
            $customer->update($changes);
            $plain = bin2hex(random_bytes(32));
            CustomerSession::create(['token_hash' => hash('sha256', $plain), 'cliente_id' => (string) $customer->id, 'expires_at' => now()->addMinutes(config('operations.token_minutes'))]);
            app(SecurityService::class)->record('login_cliente', 'cliente:'.$customer->id);

            return ['token_cliente' => $plain, 'expires_in' => config('operations.token_minutes') * 60, 'cliente' => app(BankRegistrationService::class)->findProfile($customer->external_ref)];
        }, attempts: 5);
        if ($result === null) {
            throw new BusinessException('Credenciales incorrectas o acceso temporalmente bloqueado.', 'INVALID_CREDENTIALS', 401);
        }

        return $result;
    }

    public function session(string $plain): CustomerSession
    {
        $session = preg_match('/^[a-f0-9]{64}$/D', $plain) ? CustomerSession::where('token_hash', hash('sha256', $plain))->first() : null;
        $customer = $session ? Customer::find($session->cliente_id) : null;
        if (! $session || ! $session->expires_at || $session->expires_at->isPast() || ! $customer || ! $customer->active || $customer->locked_until?->isFuture()) {
            throw new BusinessException('La sesión del cliente venció. Inicia sesión de nuevo.', 'CUSTOMER_SESSION_EXPIRED', 401);
        }

        return $session;
    }
}
