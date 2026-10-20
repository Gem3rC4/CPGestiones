<?php

namespace App\Http\Requests;

class BankAccountRequest extends BankCustomerRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'producto' => ['required', 'string', 'in:ahorro,monetaria'],
            'nombre' => ['sometimes', 'nullable', 'string', 'max:60'],
        ];
    }
}
