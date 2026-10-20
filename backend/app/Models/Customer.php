<?php

namespace App\Models;

use Illuminate\Support\Facades\Crypt;

class Customer extends Document
{
    protected $table = 'clientes';

    protected $hidden = ['dpi', 'dpi_hash', 'password', 'failed_attempts', 'locked_until'];

    protected $casts = ['locked_until' => 'datetime'];

    protected $appends = ['dpi_enmascarado'];

    public static function fingerprint(string $dpi): string
    {
        return hash_hmac('sha256', 'customer-dpi:'.$dpi, app('encrypter')->getKey());
    }

    public function setDpiAttribute(string $value): void
    {
        $this->attributes['dpi'] = Crypt::encryptString($value);
        $this->attributes['dpi_hash'] = self::fingerprint($value);
    }

    public function getDpiAttribute(?string $value): ?string
    {
        // Legacy documents remain readable until cp:upgrade-registration encrypts them.
        return $value !== null && isset($this->attributes['dpi_hash']) ? Crypt::decryptString($value) : $value;
    }

    public function getDpiEnmascaradoAttribute(): ?string
    {
        $dpi = $this->dpi;

        return $dpi === null ? null : str_repeat('*', max(0, strlen($dpi) - 4)).substr($dpi, -4);
    }

    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = mb_strtolower(trim($value));
    }
}
