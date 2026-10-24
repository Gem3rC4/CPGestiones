<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\CustomerSession;
use App\Repositories\CustomerRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

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
            $changes = ['failed_attempts' => 0, 'locked_until' => null, 'ultimo_acceso' => now()->toIso8601String()];
            if (Hash::needsRehash($customer->password)) {
                $changes['password'] = Hash::make($data['contrasena']);
            }
            $customer->update($changes);
            $plain = bin2hex(random_bytes(32));
            CustomerSession::create(['token_hash' => hash('sha256', $plain), 'cliente_id' => (string) $customer->id, 'expires_at' => now()->addMinutes(config('operations.token_minutes')), 'dispositivo' => $data['dispositivo'] ?? null, 'ip' => $data['ip'] ?? null, 'ultima_actividad' => now()]);
            app(SecurityService::class)->record('login_cliente', 'cliente:'.$customer->id);

            return ['token_cliente' => $plain, 'expires_in' => config('operations.token_minutes') * 60, 'cliente' => app(BankRegistrationService::class)->sessionProfile($customer->refresh())];
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
        // Throttled write: enough for "actividad hace X min" without one update per request.
        if (! $session->ultima_actividad || $session->ultima_actividad->lt(now()->subMinute())) {
            CustomerSession::where('_id', $session->id)->update(['ultima_actividad' => now()]);
            $session->ultima_actividad = now();
        }

        return $session;
    }

    public function changePassword(CustomerSession $session, string $current, string $new): array
    {
        if (hash_equals($current, $new)) {
            throw ValidationException::withMessages(['contrasena_nueva' => 'La contraseña nueva debe ser diferente de la actual.']);
        }
        $result = DB::connection('mongodb')->transaction(function () use ($session, $current, $new): ?array {
            $customer = Customer::findOrFail($session->cliente_id);
            if (! Hash::check($current, $customer->password)) {
                // Same lockout as login, so a stolen session cannot be used to guess the password.
                $failures = (int) $customer->failed_attempts + 1;
                $customer->update(['failed_attempts' => $failures, 'locked_until' => $failures >= config('operations.max_failures') ? now()->addMinutes(config('operations.lock_minutes')) : null]);
                app(SecurityService::class)->record('cambio_contrasena_fallido', 'cliente:'.$customer->id);

                return null;
            }
            $customer->update(['password' => Hash::make($new), 'failed_attempts' => 0, 'locked_until' => null]);
            $closed = CustomerSession::where('cliente_id', (string) $customer->id)->where('_id', '!=', $session->id)->delete();
            app(SecurityService::class)->record('contrasena_cliente_actualizada', 'cliente:'.$customer->id, ['sesiones_cerradas' => $closed]);

            return ['contrasena_actualizada' => true, 'sesiones_cerradas' => $closed];
        }, attempts: 5);
        if ($result === null) {
            throw ValidationException::withMessages(['contrasena_actual' => 'La contraseña actual no es correcta.']);
        }

        return $result;
    }

    /**
     * "Olvidé mi contraseña" without e-mail (team decision): DPI, e-mail and birth date must all match an
     * app profile. Failures share the login lockout, so the identity data cannot be guessed endlessly.
     */
    public function recover(array $data): array
    {
        $result = DB::connection('mongodb')->transaction(function () use ($data): ?array {
            $customer = app(CustomerRepository::class)->byDpi($data['dpi']);
            if (! $customer || ! $customer->password || ! $customer->active || $customer->locked_until?->isFuture()) {
                Hash::check($data['contrasena_nueva'], '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');

                return null;
            }
            $matches = $customer->email && hash_equals(mb_strtolower($customer->email), $data['correo_electronico'])
                && $customer->fecha_nacimiento && hash_equals($customer->fecha_nacimiento, $data['fecha_nacimiento']);
            if (! $matches) {
                $failures = ($customer->locked_until?->isPast() ? 0 : (int) $customer->failed_attempts) + 1;
                $customer->update(['failed_attempts' => $failures, 'locked_until' => $failures >= config('operations.max_failures') ? now()->addMinutes(config('operations.lock_minutes')) : null]);
                app(SecurityService::class)->record('recuperacion_cliente_fallida', 'cliente:'.$customer->id);

                return null;
            }
            $customer->update(['password' => Hash::make($data['contrasena_nueva']), 'failed_attempts' => 0, 'locked_until' => null]);
            $closed = CustomerSession::where('cliente_id', (string) $customer->id)->delete();
            app(SecurityService::class)->record('contrasena_cliente_recuperada', 'cliente:'.$customer->id, ['sesiones_cerradas' => $closed]);

            return ['contrasena_restablecida' => true, 'sesiones_cerradas' => $closed];
        }, attempts: 5);
        if ($result === null) {
            throw new BusinessException('Los datos no coinciden con un cliente de Banca Virtual o el acceso está bloqueado temporalmente.', 'RECOVERY_REJECTED', 422);
        }

        return $result;
    }

    public function sessions(CustomerSession $current): array
    {
        return CustomerSession::where('cliente_id', $current->cliente_id)->where('expires_at', '>', now())->orderBy('created_at', 'desc')->get()
            ->map(fn (CustomerSession $session): array => [
                'id' => (string) $session->id, 'actual' => (string) $session->id === (string) $current->id,
                'dispositivo' => $session->dispositivo, 'ip' => $session->ip,
                'inicio' => $session->created_at?->toIso8601String(), 'ultima_actividad' => $session->ultima_actividad?->toIso8601String(),
                'vence' => $session->expires_at?->toIso8601String(),
            ])->values()->all();
    }

    public function revokeSession(CustomerSession $current, string $id): array
    {
        $session = CustomerSession::where('_id', $id)->where('cliente_id', $current->cliente_id)->first();
        if (! $session) {
            throw new BusinessException('No se encontró esa sesión del cliente.', 'NOT_FOUND', 404);
        }
        $session->delete();
        app(SecurityService::class)->record('sesion_cliente_revocada', 'cliente:'.$current->cliente_id, ['sesion_id' => $id]);

        return ['revocada' => true, 'era_actual' => (string) $session->id === (string) $current->id];
    }
}
