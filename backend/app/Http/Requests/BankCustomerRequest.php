<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

class BankCustomerRequest extends OperationRequest
{
    public function rules(): array
    {
        return ['cliente_ref' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D']];
    }

    public function after(): array
    {
        return [$this->rejectUnknownFields(...)];
    }

    protected function rejectUnknownFields(Validator $validator): void
    {
        if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
            // Never echo untrusted field names or values in errors/logs.
            $validator->errors()->add('campos_no_admitidos', 'Envía únicamente los campos del contrato de esta operación.');
        }
    }

    public function messages(): array
    {
        return parent::messages() + [
            'string' => 'Este campo debe ser texto.',
            'integer' => 'Este campo debe ser un número entero.',
            'max' => 'El valor excede el máximo permitido (:max).',
            'date_format' => 'Usa una fecha real en formato dd/mm/aaaa o aaaa-mm-dd.',
            'after_or_equal' => 'La fecha de nacimiento debe ser igual o posterior a 1900-01-01.',
            'before_or_equal' => 'El registro requiere una persona de al menos 18 años.',
        ];
    }
}
