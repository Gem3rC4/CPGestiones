<?php

namespace App\Http\Requests;

class AlertReadRequest extends BankCustomerRequest
{
    public function rules(): array
    {
        return ['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['required', 'string', 'distinct', 'regex:/^[a-f0-9]{24}$/D']];
    }
}
