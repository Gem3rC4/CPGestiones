<?php

namespace App\Http\Requests;

use Closure;

class CustomerPasswordRequest extends BankCustomerRequest
{
    public function rules(): array
    {
        $bytes = function (string $attribute, mixed $value, Closure $fail): void {
            if (strlen($value) > 72 || str_contains($value, chr(0))) {
                $fail('Usa una contraseña de hasta 72 bytes, sin caracteres nulos.');
            }
        };

        return [
            'contrasena_actual' => ['bail', 'required', 'string', 'max:72', $bytes],
            // Same policy as registration.
            'contrasena_nueva' => ['bail', 'required', 'string', 'min:12', 'max:72', 'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/', $bytes],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'contrasena_nueva.min' => 'Usa al menos 12 caracteres.',
            'contrasena_nueva.regex' => 'Incluye al menos una mayúscula, una minúscula y un número.',
        ];
    }
}
