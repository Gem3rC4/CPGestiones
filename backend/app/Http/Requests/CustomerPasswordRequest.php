<?php

namespace App\Http\Requests;

use Closure;

class CustomerPasswordRequest extends BankCustomerRequest
{
    public function rules(): array
    {
        return [
            'contrasena_actual' => ['bail', 'required', 'string', 'max:72', function (string $attribute, mixed $value, Closure $fail): void {
                if (strlen($value) > 72 || str_contains($value, chr(0))) {
                    $fail('Usa una contraseña de hasta 72 bytes, sin caracteres nulos.');
                }
            }],
            'contrasena_nueva' => BankRegistrationRequest::passwordRules(),
        ];
    }

    public function messages(): array
    {
        return parent::messages() + BankRegistrationRequest::passwordMessages('contrasena_nueva');
    }
}
