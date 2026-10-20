<?php

namespace App\Repositories;

use App\Exceptions\BusinessException;
use App\Models\Customer;

class CustomerRepository
{
    public function byReference(string $reference): Customer
    {
        return Customer::where('external_ref', $reference)->firstOrFail();
    }

    public function byDpi(string $dpi): ?Customer
    {
        // dpi_hash covers encrypted documents; the plain match covers installs not yet upgraded.
        return Customer::where('dpi_hash', Customer::fingerprint($dpi))->orWhere('dpi', $dpi)->first();
    }

    /**
     * Banca Virtual identifies its customer by DPI (agreed link key); cliente_ref stays accepted
     * so integrations built before the change keep working.
     */
    public function resolve(array $data): Customer
    {
        if (is_string($data['dpi'] ?? null) && $data['dpi'] !== '') {
            return $this->byDpi($data['dpi']) ?? throw new BusinessException('No existe un cliente con ese DPI.', 'CUSTOMER_NOT_FOUND', 404);
        }
        if (is_string($data['cliente_ref'] ?? null) && $data['cliente_ref'] !== '') {
            return $this->byReference($data['cliente_ref']);
        }

        throw new BusinessException('Indica el DPI del cliente autenticado.', 'CLIENT_SCOPE_REQUIRED', 422);
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
