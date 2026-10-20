<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Account;
use App\Models\BankEvent;
use App\Models\CardApplication;
use App\Repositories\CustomerRepository;

class BankEventService
{
    public function receive(array $data): array
    {
        ksort($data);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $previous = BankEvent::where('evento_id', $data['evento_id'])->first();
        if ($previous) {
            if (! hash_equals($previous->fingerprint, $hash)) {
                throw new BusinessException('El evento ya existe con otros datos.', 'EVENT_CONFLICT');
            }

            return ['evento_id' => $previous->evento_id, 'alerta_id' => $previous->alerta_id];
        }
        $alerts = app(AlertService::class);
        $customer = $data['tipo'] === 'registro.iniciado' ? null : app(CustomerRepository::class)->byReference($data['cliente_ref']);
        if ($data['tipo'] === 'registro.iniciado') {
            $alert = $alerts->publish('registro.iniciado:'.$data['referencia'], 'clientes.read', 'Registro en proceso', 'Banca Virtual informó el inicio de un registro. Referencia: '.$data['referencia']);
        } elseif ($data['tipo'] === 'cliente.registrado') {
            if ($data['referencia'] !== (string) $customer->id) {
                throw new BusinessException('La referencia no corresponde al cliente.', 'RESOURCE_MISMATCH', 422);
            }
            $alert = $alerts->publish('cliente.registrado:'.$customer->id, 'clientes.read', 'Cliente registrado', 'Un nuevo cliente abrió su cuenta desde Banca Virtual.', 'clientes', (string) $customer->id);
        } elseif ($data['tipo'] === 'cuenta.abierta') {
            $account = Account::where('_id', $data['referencia'])->where('cliente_id', (string) $customer->id)->firstOrFail();
            $alert = $alerts->publish('cuenta.abierta:'.$account->id, 'cuentas.read', 'Cuenta abierta', 'Banca Virtual confirmó la apertura de una cuenta.', 'cuentas', (string) $account->id);
        } else {
            $management = CardApplication::where('_id', $data['referencia'])->where('cliente_id', (string) $customer->id)->firstOrFail();
            if ($management->estado !== 'en_tramite') {
                throw new BusinessException('La gestión aún no está en trámite.', 'INVALID_STATE');
            }
            $alert = $alerts->publish('gestion.en_tramite:'.$management->id, 'solicitudes.read', 'Nueva gestión en trámite', 'El cliente aceptó la oferta. Revisa la gestión y asigna el límite.', 'solicitudes', (string) $management->id);
        }
        BankEvent::create(['evento_id' => $data['evento_id'], 'tipo' => $data['tipo'], 'fingerprint' => $hash, 'alerta_id' => (string) $alert->id]);

        return ['evento_id' => $data['evento_id'], 'alerta_id' => (string) $alert->id];
    }
}
