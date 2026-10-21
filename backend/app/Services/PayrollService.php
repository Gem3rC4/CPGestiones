<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Account;
use App\Models\Collaborator;
use App\Models\Company;
use App\Models\Payroll;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class PayrollService
{
    /**
     * The "empresa" role only works with the company it represents; the administrator works with all.
     */
    public function authorizeCompany(string $id, string $actor): Company
    {
        $company = Company::findOrFail($id);
        $user = User::findOrFail($actor);
        if ($user->role !== 'administrador' && $user->empresa_id !== (string) $company->id) {
            throw new BusinessException('No tienes acceso a esta empresa.', 'FORBIDDEN', 403);
        }

        return $company;
    }

    public function create(array $d, string $actor): array
    {
        $company = $this->authorizeCompany($d['empresa_id'], $actor);
        $source = Account::findOrFail($company->cuenta_id);
        $rows = [];
        $total = 0;
        $seen = [];
        foreach ($d['pagos'] as $p) {
            $collaborator = isset($p['colaborador_id']) ? Collaborator::where('_id', $p['colaborador_id'])->where('empresa_id', (string) $company->id)->first() : null;
            if (isset($p['colaborador_id']) && ! $collaborator) {
                throw new BusinessException('Un colaborador no pertenece a esta empresa.', 'INVALID_PAYROLL', 422);
            }
            $target = match (true) {
                $collaborator !== null => Account::find($collaborator->cuenta_id),
                isset($p['cuenta_id']) => Account::find($p['cuenta_id']),
                default => Account::where('numero', $p['cuenta_numero'] ?? '')->first(),
            };
            if (! $target || (string) $target->id === (string) $source->id || $target->estado !== 'activa' || $target->moneda !== $source->moneda || $target->system_key) {
                throw new BusinessException('La cuenta de '.($p['nombre'] ?? 'un beneficiario').' no existe o no está activa.', 'INVALID_PAYROLL', 422);
            }
            if (isset($seen[(string) $target->id])) {
                throw new BusinessException('Una cuenta aparece más de una vez en la planilla.', 'DUPLICATE_BENEFICIARY', 422);
            }
            $seen[(string) $target->id] = true;
            $c = Money::cents($p['monto']);
            $total += $c;
            if ($total > Money::MAX) {
                throw new BusinessException('La planilla supera el importe máximo.', 'INVALID_AMOUNT', 422);
            }
            $rows[] = ['cuenta_id' => (string) $target->id, 'cuenta_numero' => $target->numero, 'cliente_id' => $target->cliente_id, 'nombre' => $p['nombre'], 'centavos' => $c]
                + array_filter(['puesto' => $p['puesto'] ?? $collaborator?->puesto, 'colaborador_id' => $collaborator ? (string) $collaborator->id : null]);
        }
        $payroll = Payroll::create(['empresa_id' => $d['empresa_id'], 'empresa_nombre' => $company->nombre, 'nombre' => $d['nombre'], 'cuenta_id' => (string) $source->id, 'pagos' => $rows, 'total_centavos' => $total, 'creado_por' => $actor, 'estado' => 'pendiente_autorizacion']);
        app(SecurityService::class)->record('planilla_cargada', $actor, ['planilla_id' => (string) $payroll->id]);

        return $payroll->toArray();
    }

    /**
     * Approving pays the payroll at once: every transfer from the company account to each collaborator's
     * account happens in the caller's transaction, so either all of them are applied or none is.
     */
    public function approve(string $id, string $actor): array
    {
        $payroll = Payroll::findOrFail($id);
        $this->authorizeCompany($payroll->empresa_id, $actor);
        if ($payroll->estado !== 'pendiente_autorizacion') {
            throw new BusinessException('Esta planilla ya fue autorizada.', 'INVALID_STATE');
        }
        $source = Account::findOrFail($payroll->cuenta_id);
        if ((int) $source->saldo_centavos < (int) $payroll->total_centavos) {
            throw new BusinessException('La cuenta de la empresa tiene Q '.Money::format((int) $source->saldo_centavos).' y la planilla suma Q '.Money::format((int) $payroll->total_centavos).'. Deposita fondos antes de autorizarla.', 'INSUFFICIENT_FUNDS');
        }
        $payroll->update(['estado' => 'procesando', 'autorizado_por' => $actor]);
        $payments = $this->disburse($payroll, $actor);
        $payroll->update(['estado' => 'pagada', 'pagos' => $payments, 'pagada_el' => now()->toIso8601String(), 'ultimo_error' => null]);
        app(SecurityService::class)->record('planilla_autorizada', $actor, ['planilla_id' => $id, 'total' => Money::format((int) $payroll->total_centavos)]);

        return $payroll->toArray();
    }

    /**
     * Payrolls authorized by earlier versions wait in "en_cola" for the scheduled worker.
     */
    public function process(string $id): array
    {
        return DB::transaction(function () use ($id) {
            $payroll = Payroll::findOrFail($id);
            if ($payroll->estado === 'pagada') {
                return $payroll->toArray();
            }
            if ($payroll->estado !== 'en_cola') {
                throw new BusinessException('La planilla no está autorizada.', 'INVALID_STATE');
            }
            $payroll->update(['estado' => 'procesando']);
            $payroll->update(['estado' => 'pagada', 'pagos' => $this->disburse($payroll, 'worker:planillas'), 'pagada_el' => now()->toIso8601String(), 'ultimo_error' => null]);

            return $payroll->toArray();
        }, attempts: 5);
    }

    private function disburse(Payroll $payroll, string $actor): array
    {
        $company = $payroll->empresa_nombre ?? Company::find($payroll->empresa_id)?->nombre;
        $payments = $payroll->pagos;
        foreach ($payments as &$payment) {
            $movement = app(AccountService::class)->move($payroll->cuenta_id, $payment['cuenta_id'], $payment['centavos'], $actor, 'Planilla '.$payroll->nombre.($company ? ' · '.$company : ''), 'planilla');
            $payment['transaccion_id'] = $movement['transaccion_id'];
        }

        return $payments;
    }
}
