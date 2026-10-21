<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BankCustomerRequest extends OperationRequest
{
    /**
     * The customer is identified by DPI; cliente_ref is still accepted from earlier integrations.
     */
    public static function identityRules(): array
    {
        return [
            // With X-Cliente-Token the logged-in customer is known, so the DPI becomes optional.
            'dpi' => [Rule::requiredIf(fn (): bool => ! request()->filled('cliente_ref') && ! request()->hasHeader('X-Cliente-Token')), 'string', 'regex:/^[0-9]{13}$/D'],
            'cliente_ref' => ['sometimes', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D'],
        ];
    }

    public function rules(): array
    {
        return self::identityRules();
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
            'dpi.required' => 'Indica el DPI del cliente (13 dígitos) o envía X-Cliente-Token.',
            'dpi.required_without' => 'Indica el DPI del cliente (13 dígitos).',
            'dpi.regex' => 'El DPI debe tener 13 dígitos, sin espacios ni guiones.',
            'cliente_ref.required_without' => 'Indica el DPI del cliente (13 dígitos).',
            'string' => 'Este campo debe ser texto.',
            'integer' => 'Este campo debe ser un número entero.',
            'max' => 'El valor excede el máximo permitido (:max).',
            'date_format' => 'Usa una fecha real en formato dd/mm/aaaa o aaaa-mm-dd.',
            'after_or_equal' => 'La fecha de nacimiento debe ser igual o posterior a 1900-01-01.',
            'before_or_equal' => 'El registro requiere una persona de al menos 18 años.',
        ];
    }
}
