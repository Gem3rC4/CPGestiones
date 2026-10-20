<?php

namespace App\Http\Requests;

class BankManagementRequest extends BankCustomerRequest
{
    public function rules(): array
    {
        return parent::rules() + ['gestion_ref' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:-]+$/D'],
            'evaluacion_ref' => ['required', 'string', 'max:100'],
            'referencia_aceptacion' => ['required', 'string', 'min:3', 'max:150'],
            'aceptada_en' => ['required', 'date_format:Y-m-d\TH:i:sP', 'before_or_equal:now']];
    }
}
