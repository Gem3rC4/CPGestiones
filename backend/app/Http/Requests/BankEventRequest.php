<?php

namespace App\Http\Requests;

class BankEventRequest extends BankCustomerRequest
{
    public function rules(): array
    {
        return ['evento_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:-]+$/D'],
            'tipo' => ['required', 'in:registro.iniciado,cliente.registrado,cuenta.abierta,gestion.en_tramite'],
            'referencia' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:@-]+$/D'],
            'cliente_ref' => ['required_unless:tipo,registro.iniciado', 'string', 'max:100']];
    }
}
