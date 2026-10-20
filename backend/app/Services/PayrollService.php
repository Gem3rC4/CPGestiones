<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Payroll;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class PayrollService
{
    public function authorizeCompany(string $id, string $actor): Company
    {
        $company = Company::findOrFail($id);
        $user = User::findOrFail($actor);
        if (str_starts_with($user->role, 'empresa_') && ! in_array($actor, $company->miembros ?? [], true)) {
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
            $target = isset($p['cuenta_id']) ? Account::findOrFail($p['cuenta_id']) : Account::where('numero', $p['cuenta_numero'])->firstOrFail();
            if ($target->id === $source->id || $target->estado !== 'activa' || $target->moneda !== $source->moneda || $target->system_key) {
                throw new BusinessException('Hay una cuenta de destino inválida.', 'INVALID_PAYROLL', 422);
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
            $rows[] = ['cuenta_id' => (string) $target->id, 'cuenta_numero' => $target->numero, 'nombre' => $p['nombre'], 'centavos' => $c]
                + array_filter(['puesto' => $p['puesto'] ?? null, 'colaborador_id' => $p['colaborador_id'] ?? null]);
        }
        $payroll = Payroll::create(['empresa_id' => $d['empresa_id'], 'nombre' => $d['nombre'], 'cuenta_id' => (string) $source->id, 'pagos' => $rows, 'total_centavos' => $total, 'creado_por' => $actor, 'estado' => 'pendiente_autorizacion']);
        app(SecurityService::class)->record('planilla_cargada', $actor, ['planilla_id' => (string) $payroll->id]);

        return $payroll->toArray();
    }

    public function approve(string $id, string $actor): array
    {
        $payroll = Payroll::findOrFail($id);
        $this->authorizeCompany($payroll->empresa_id, $actor);
        // The administrator may authorize their own payroll (demo/academic use); other roles keep four eyes.
        if ($payroll->creado_por === $actor && User::find($actor)?->role !== 'administrador') {
            throw new BusinessException('Otra persona debe autorizar esta planilla.', 'FOUR_EYES_REQUIRED', 403);
        }
        if ($payroll->estado !== 'pendiente_autorizacion') {
            throw new BusinessException('Esta planilla ya fue autorizada.', 'INVALID_STATE');
        }
        $payroll->update(['estado' => 'en_cola', 'autorizado_por' => $actor]);
        app(SecurityService::class)->record('planilla_autorizada', $actor, ['planilla_id' => $id]);

        return $payroll->toArray();
    }

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
            foreach ($payroll->pagos as $payment) {
                app(AccountService::class)->move($payroll->cuenta_id, $payment['cuenta_id'], $payment['centavos'], 'worker:planillas', 'Planilla '.$payroll->nombre, 'planilla');
            }
            $payroll->update(['estado' => 'pagada', 'pagada_el' => now()->toIso8601String(), 'ultimo_error' => null]);

            return $payroll->toArray();
        }, attempts: 5);
    }
}
