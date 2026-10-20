<?php

namespace App\Http\Requests;

class BankFinancialProfileRequest extends BankRegistrationRequest
{
    public function rules(): array
    {
        return (new BankCustomerRequest)->rules() + $this->financialRules() + ['perfil_version' => ['required', 'integer', 'min:1']];
    }

    public function after(): array
    {
        // Birth date comes from the stored identity and is checked inside the transaction.
        return [$this->rejectUnknownFields(...)];
    }
}
