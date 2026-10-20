<?php

namespace App\Repositories;

use App\Models\Customer;

class CustomerRepository
{
    public function byReference(string $reference): Customer
    {
        return Customer::where('external_ref', $reference)->firstOrFail();
    }

    public function registrationExists(array $data): bool
    {
        return Customer::where('external_ref', $data['cliente_ref'])
            ->orWhere('email', $data['correo_electronico'])
            ->orWhere('dpi_hash', Customer::fingerprint($data['dpi']))
            ->orWhere('dpi', $data['dpi'])->exists();
    }

    public function create(array $data): Customer
    {
        return Customer::create($data);
    }

    public function update(Customer $customer, array $data): Customer
    {
        $customer->update($data);

        return $customer;
    }
}
