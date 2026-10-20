<?php

namespace App\Http\Requests;

use Closure;

class CustomerLoginRequest extends BankCustomerRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('correo_electronico'))) {
            $this->merge(['correo_electronico' => mb_strtolower(trim($this->input('correo_electronico')))]);
        }
    }

    public function rules(): array
    {
        return [
            'correo_electronico' => ['required', 'string', 'email', 'max:180'],
            'contrasena' => ['bail', 'required', 'string', 'max:72', function (string $attribute, mixed $value, Closure $fail): void {
                if (strlen($value) > 72 || str_contains($value, chr(0))) {
                    $fail('Usa una contraseña de hasta 72 bytes, sin caracteres nulos.');
                }
            }],
            // Optional: lets Banca Virtual show "Chrome en Windows · IP ..." in the active sessions list.
            'dispositivo' => ['sometimes', 'nullable', 'string', 'max:120'],
            'ip' => ['sometimes', 'nullable', 'ip'],
        ];
    }
}
