<?php

namespace App\Http\Requests;

class RiskAssessmentRequest extends BankCustomerRequest
{
    public function rules(): array
    {
        $money = ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D'];

        return parent::rules() + ['evaluacion_ref' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:-]+$/D'],
            'producto' => ['required', 'string', 'max:60'], 'resultado' => ['required', 'in:aprobada,rechazada'],
            'monto_solicitado' => $money, 'limite_aprobado' => $money, 'motivo' => ['required', 'string', 'min:5', 'max:500']];
    }
}
