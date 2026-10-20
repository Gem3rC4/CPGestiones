<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Repositories\CustomerRepository;

class ClientScope
{
    public function customerId(): ?string
    {
        if (request()->attributes->get('actor') !== 'service:banca') {
            return null;
        }
        $identity = array_filter(request()->only(['dpi', 'cliente_ref']), 'is_string');
        if ($identity === []) {
            throw new BusinessException('El servidor de banca debe indicar el DPI del cliente autenticado.', 'CLIENT_SCOPE_REQUIRED', 422);
        }

        return (string) app(CustomerRepository::class)->resolve($identity)->id;
    }

    public function check(string $id): void
    {
        $scoped = $this->customerId();
        if ($scoped !== null && $scoped !== $id) {
            throw new BusinessException('El recurso no corresponde al cliente.', 'FORBIDDEN', 403);
        }
    }
}
