<?php

namespace App\Services;

use App\Exceptions\BusinessException;

/**
 * Minimal HS256 JSON Web Tokens for the staff panel. The signing key is derived from APP_KEY, so no
 * extra secret has to be configured; rotating APP_KEY invalidates every access token.
 */
class JwtService
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public function encode(array $claims): string
    {
        $segments = [$this->base64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), $this->base64(json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))];
        $segments[] = $this->base64(hash_hmac('sha256', implode('.', $segments), $this->key(), true));

        return implode('.', $segments);
    }

    /**
     * @return array<string, mixed> Verified claims.
     */
    public function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw $this->invalid();
        }
        [$header, $payload, $signature] = $parts;
        $expected = $this->base64(hash_hmac('sha256', $header.'.'.$payload, $this->key(), true));
        if (! hash_equals($expected, $signature)) {
            throw $this->invalid();
        }
        $head = json_decode($this->decodeSegment($header), true);
        $claims = json_decode($this->decodeSegment($payload), true);
        // The algorithm is fixed: "none" or an asymmetric alg in the header is never accepted.
        if (! is_array($head) || ($head['alg'] ?? null) !== 'HS256' || ! is_array($claims)) {
            throw $this->invalid();
        }
        if (($claims['iss'] ?? null) !== config('operations.jwt.issuer') || ! is_int($claims['exp'] ?? null)) {
            throw $this->invalid();
        }
        if ($claims['exp'] <= now()->timestamp) {
            throw new BusinessException('La sesión expiró. Renueva el acceso.', 'TOKEN_EXPIRED', 401);
        }

        return $claims;
    }

    public static function looksLikeJwt(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $token);
    }

    private function key(): string
    {
        return hash_hmac('sha256', 'cp-gestiones-jwt', app('encrypter')->getKey(), true);
    }

    private function base64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decodeSegment(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }

    private function invalid(): BusinessException
    {
        return new BusinessException('El token de acceso no es válido.', 'INVALID_TOKEN', 401);
    }
}
