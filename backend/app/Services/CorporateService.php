<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Account;
use App\Models\Collaborator;
use App\Models\Customer;
use App\Support\Money;

class CorporateService
{
    public function __construct(private PayrollService $payrolls) {}

    /**
     * A collaborator is a customer registered at the bank (with Banca Virtual access and an account of their
     * own) assigned to a company with a position and a salary. Payroll is paid into that account.
     */
    public function addCollaborator(array $data, string $actor): array
    {
        $company = $this->payrolls->authorizeCompany($data['empresa_id'], $actor);
        $account = isset($data['cuenta_id']) ? Account::find($data['cuenta_id']) : Account::where('numero', $data['cuenta_numero'] ?? '')->whereNull('system_key')->first();
        if (! $account || $account->system_key || $account->estado !== 'activa') {
            throw new BusinessException('No existe una cuenta activa con ese número.', 'ACCOUNT_NOT_FOUND', 404);
        }
        $customer = Customer::find($account->cliente_id);
        if (! $customer || (isset($data['cliente_id']) && $data['cliente_id'] !== (string) $customer->id)) {
            throw new BusinessException('La cuenta seleccionada no pertenece a ese cliente.', 'INVALID_ACCOUNT', 422);
        }
        if (! in_array($account->tipo, ['ahorro', 'monetaria'], true)) {
            throw new BusinessException('La planilla se paga en una cuenta de ahorro o monetaria.', 'INVALID_ACCOUNT', 422);
        }
        if ((string) $account->id === (string) $company->cuenta_id) {
            throw new BusinessException('La cuenta de la empresa no puede recibir su propia planilla.', 'INVALID_ACCOUNT', 422);
        }
        if (Collaborator::where('empresa_id', (string) $company->id)->where('cliente_id', (string) $customer->id)->exists() || Collaborator::where('empresa_id', (string) $company->id)->where('cuenta_id', (string) $account->id)->exists()) {
            throw new BusinessException('Esa persona ya es colaboradora de la empresa.', 'DUPLICATE_COLLABORATOR');
        }
        $collaborator = Collaborator::create(['empresa_id' => (string) $company->id, 'empresa_nombre' => $company->nombre, 'cliente_id' => (string) $customer->id, 'nombre' => $customer->nombre, 'correo' => $customer->email,
            'puesto' => $data['puesto'], 'salario_centavos' => Money::cents($data['salario']), 'cuenta_id' => (string) $account->id, 'cuenta_numero' => $account->numero, 'cuenta_tipo' => $account->tipo, 'activo' => true]);
        app(SecurityService::class)->record('colaborador_registrado', $actor, ['empresa_id' => (string) $company->id, 'colaborador_id' => (string) $collaborator->id]);

        return $collaborator->toArray();
    }
}
