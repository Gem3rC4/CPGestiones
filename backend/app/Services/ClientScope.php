<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Customer;

class ClientScope
{
    public function customerId(): ?string
    {
        if (request()->attributes->get('actor') !== 'service:banca') {
            return null;
        }
        $ref = request()->input('cliente_ref');
        if (! is_string($ref) || $ref === '') {
            throw new BusinessException('El servidor de banca debe indicar cliente_ref autenticado.', 'CLIENT_SCOPE_REQUIRED', 422);
        }

        return (string) Customer::where('external_ref', $ref)->firstOrFail()->id;
    }

    public function check(string $id): void
    {
        $scoped = $this->customerId();
        if ($scoped !== null && $scoped !== $id) {
            throw new BusinessException('El recurso no corresponde al cliente.', 'FORBIDDEN', 403);
        }
    }
}
