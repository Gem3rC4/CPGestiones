<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class BankServicePaymentRequest extends BankCustomerRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'origen_id' => ['required', 'string', 'regex:/^[a-f0-9]{24}$/D'],
            'servicio' => ['required', 'string', Rule::in(array_keys(config('operations.services')))],
            // Same rule Banca Virtual shows under the field: letters, digits and hyphens, at least 4.
            'referencia_servicio' => ['required', 'string', 'min:4', 'max:40', 'regex:/^[A-Za-z0-9-]+$/D'],
            'monto' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D'],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'referencia_servicio.regex' => 'Usa solo letras, números y guiones.',
            'referencia_servicio.min' => 'Ingresa al menos 4 caracteres.',
            'servicio.in' => 'Selecciona un servicio del catálogo.',
            'monto.regex' => 'Usa un importe decimal en texto, por ejemplo "150.25".',
        ];
    }
}
