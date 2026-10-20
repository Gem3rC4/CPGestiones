<?php

namespace App\Http\Requests;

class CustomerRecoveryRequest extends BankRegistrationRequest
{
    // "Olvidé mi contraseña" without e-mail: the customer proves identity with DPI, e-mail and birth date.
    public function rules(): array
    {
        return [
            'dpi' => ['required', 'string', 'regex:/^[0-9]{13}$/D'],
            'correo_electronico' => ['required', 'string', 'email', 'max:180'],
            'fecha_nacimiento' => ['required', 'string', 'date_format:Y-m-d'],
            'contrasena_nueva' => self::passwordRules(),
        ];
    }

    public function messages(): array
    {
        return parent::messages() + self::passwordMessages('contrasena_nueva');
    }

    public function after(): array
    {
        return [$this->rejectUnknownFields(...)];
    }
}
