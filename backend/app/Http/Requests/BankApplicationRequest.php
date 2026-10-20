<?php

namespace App\Http\Requests;

use App\Exceptions\BusinessException;
use App\Support\Money;

class BankApplicationRequest extends BankRegistrationRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        // Like ingresos_mensuales_q, the requested amount may arrive as a JSON number from the form.
        foreach (['monto_solicitado', 'monto_aprobado'] as $field) {
            $amount = $this->input($field);
            if (is_int($amount) || is_float($amount)) {
                $amount = json_encode($amount, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            }
            if (is_string($amount)) {
                try {
                    $this->merge([$field => Money::format(Money::cents($amount))]);
                } catch (BusinessException) {
                    $this->merge([$field => $amount]);
                }
            }
        }
    }

    // The detailed customer data is collected here, the first time the customer requests a product.
    // Fields already on the profile may be omitted; the service reports any that are still missing.
    public function rules(): array
    {
        return BankCustomerRequest::identityRules() + [
            'producto' => ['required', 'string', 'max:60'],
            'monto_solicitado' => ['sometimes', 'string', 'regex:/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D'],
            'monto_aprobado' => ['sometimes', 'string', 'regex:/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D'],
            'evaluacion_ref' => ['sometimes', 'string', 'max:100'],
            'cuenta_id' => ['sometimes', 'string', 'regex:/^[a-f0-9]{24}$/D'],
            'gestion_ref' => ['sometimes', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:-]+$/D'],
            'nit' => ['sometimes', ...$this->nitRules()],
            'fecha_nacimiento' => ['sometimes', ...array_slice($this->birthDateRules(), 1)],
            'telefono' => ['sometimes', 'string', 'max:20', 'regex:/^[0-9+ -]{8,20}$/D'],
        ] + $this->optionalFinancialRules();
    }

    public function messages(): array
    {
        return parent::messages() + ['monto_solicitado.regex' => 'Usa un monto positivo con máximo dos decimales.', 'telefono.regex' => 'Usa solo números, espacios, guiones o +.'];
    }

    public function after(): array
    {
        // Seniority against age is checked in the service, where the stored birth date is known.
        return [$this->rejectUnknownFields(...)];
    }
}
