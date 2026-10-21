<?php

namespace App\Repositories;

use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Services\CustomerAuthService;

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
        $session = $this->fromSessionHeader();
        if (is_string($data['dpi'] ?? null) && $data['dpi'] !== '') {
            $customer = $this->byDpi($data['dpi']) ?? throw new BusinessException('No existe un cliente con ese DPI.', 'CUSTOMER_NOT_FOUND', 404);
            if ($session && (string) $session->id !== (string) $customer->id) {
                throw new BusinessException('El DPI no corresponde a la sesión del cliente.', 'FORBIDDEN', 403);
            }

            return $customer;
        }
        if (is_string($data['cliente_ref'] ?? null) && $data['cliente_ref'] !== '') {
            return $this->byReference($data['cliente_ref']);
        }
        // Customers registered in Gestiones may be unknown to Banca's own database: the session identifies them.
        if ($session) {
            return $session;
        }

        throw new BusinessException('Indica el DPI del cliente autenticado.', 'CLIENT_SCOPE_REQUIRED', 422);
    }

    /**
     * Customer of the X-Cliente-Token session, when Banca Virtual sends it (only its service token can).
     */
    public function fromSessionHeader(): ?Customer
    {
        $token = request()->header('X-Cliente-Token');
        if (! is_string($token) || $token === '' || request()->attributes->get('actor') !== 'service:banca') {
            return null;
        }

        return Customer::find(app(CustomerAuthService::class)->session($token)->cliente_id);
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
