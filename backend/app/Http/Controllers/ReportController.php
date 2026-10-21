<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Models\Account;
use App\Models\Card;
use App\Models\Company;
use App\Models\Credit;
use App\Models\Customer;
use App\Models\Movement;
use App\Models\Payroll;
use App\Services\ExchangeRateService;
use App\Services\LoanService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Data for the three PDF reports (generated in the panel): payroll per company, a customer's account
 * statement and the consolidated transactions of every customer. Every report accepts filters.
 */
class ReportController extends ApiController
{
    private const MAX_ROWS = 5000;

    public function options(Request $r)
    {
        $can = fn (string $permission): bool => $this->allowed($r, $permission);
        $companies = $can('reportes.planillas') ? Company::orderBy('nombre')->when($r->user()?->role === 'empresa', fn ($q) => $q->where('_id', $r->user()->empresa_id))->get()->map(fn (Company $c): array => ['id' => (string) $c->id, 'nombre' => $c->nombre, 'nit' => $c->nit])->values() : [];
        $customers = [];
        if ($can('reportes.cuentas')) {
            $accounts = Account::whereNull('system_key')->whereNull('empresa_id')->get()->groupBy('cliente_id');
            $customers = Customer::where('segmento', '!=', 'empresa')->orderBy('nombre')->get()->map(fn (Customer $c): array => ['id' => (string) $c->id, 'nombre' => $c->nombre, 'dpi_enmascarado' => $c->dpi_enmascarado,
                'cuentas' => ($accounts[(string) $c->id] ?? collect())->map(fn (Account $a): array => ['id' => (string) $a->id, 'numero' => $a->numero, 'tipo' => $a->tipo])->values()])->values();
        }

        return $this->ok(['empresas' => $companies, 'clientes' => $customers, 'tipos_movimiento' => ['deposito', 'retiro', 'transferencia', 'planilla', 'pago_servicio', 'pago_tarjeta', 'consumo_debito', 'desembolso_prestamo', 'pago_prestamo', 'interes_ahorro']]);
    }

    public function payroll(Request $r)
    {
        $f = $r->validate(['empresa_id' => 'required|string|regex:/^[a-f0-9]{24}$/D', 'estado' => 'sometimes|nullable|in:pendiente_autorizacion,en_cola,pagada', 'desde' => 'sometimes|nullable|date_format:Y-m-d', 'hasta' => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:desde'], $this->messages());
        if ($r->user()?->role === 'empresa' && $r->user()->empresa_id !== $f['empresa_id']) {
            throw new BusinessException('Solo puedes generar reportes de tu empresa.', 'FORBIDDEN', 403);
        }
        $company = Company::findOrFail($f['empresa_id']);
        [$from, $to] = $this->range($f, null);
        $payrolls = Payroll::where('empresa_id', (string) $company->id)->when($f['estado'] ?? null, fn ($q, $s) => $q->where('estado', $s))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->orderBy('created_at')->limit(self::MAX_ROWS)->get();
        $rows = $payrolls->map(fn (Payroll $p): array => ['id' => (string) $p->id, 'nombre' => $p->nombre, 'estado' => $p->estado, 'creada' => $p->created_at?->toIso8601String(), 'pagada_el' => $p->pagada_el,
            'total_centavos' => (int) $p->total_centavos, 'beneficiarios' => count($p->pagos ?? []), 'pagos' => collect($p->pagos ?? [])->map(fn (array $x): array => ['nombre' => $x['nombre'], 'puesto' => $x['puesto'] ?? null, 'cuenta_numero' => $x['cuenta_numero'] ?? null, 'centavos' => (int) $x['centavos']])->values()]);
        $paid = $rows->where('estado', 'pagada');

        return $this->ok($this->envelope($r, 'Pago de planillas', $f) + [
            'empresa' => ['id' => (string) $company->id, 'nombre' => $company->nombre, 'razon_social' => $company->razon_social ?? $company->nombre, 'representante_legal' => $company->representante_legal, 'nit' => $company->nit, 'cuenta_numero' => $company->cuenta_numero ?? Account::find($company->cuenta_id)?->numero],
            'planillas' => $rows->values(),
            'totales' => ['planillas' => $rows->count(), 'pagadas' => $paid->count(), 'pagado_centavos' => $paid->sum('total_centavos'), 'pendiente_centavos' => $rows->where('estado', '!=', 'pagada')->sum('total_centavos'), 'beneficiarios' => $rows->sum('beneficiarios')],
        ]);
    }

    public function statement(Request $r, LoanService $loans)
    {
        $f = $r->validate(['cliente_id' => 'required|string|regex:/^[a-f0-9]{24}$/D', 'cuenta_id' => 'sometimes|nullable|string|regex:/^[a-f0-9]{24}$/D', 'desde' => 'sometimes|nullable|date_format:Y-m-d', 'hasta' => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:desde'], $this->messages());
        $customer = Customer::findOrFail($f['cliente_id']);
        [$from, $to] = $this->range($f, 'month');
        $accounts = Account::where('cliente_id', (string) $customer->id)->whereNull('system_key')->when($f['cuenta_id'] ?? null, fn ($q, $id) => $q->where('_id', $id))->orderBy('created_at')->get();
        $statements = $accounts->map(function (Account $account) use ($from, $to): array {
            $id = (string) $account->id;
            $opening = (int) (Movement::where('cuenta_id', $id)->where('created_at', '<', $from)->orderBy('created_at', 'desc')->first()?->saldo_centavos ?? 0);
            $movements = Movement::where('cuenta_id', $id)->where('created_at', '>=', $from)->where('created_at', '<=', $to)->orderBy('created_at')->limit(self::MAX_ROWS)->get()
                ->map(fn (Movement $m): array => ['fecha' => $m->created_at?->toIso8601String(), 'tipo' => $m->tipo, 'concepto' => $m->concepto, 'importe_centavos' => (int) $m->importe_centavos, 'saldo_centavos' => $m->saldo_centavos === null ? null : (int) $m->saldo_centavos]);

            return ['cuenta' => ['id' => $id, 'numero' => $account->numero, 'tipo' => $account->tipo, 'nombre' => $account->nombre, 'estado' => $account->estado],
                'saldo_inicial_centavos' => $opening, 'saldo_final_centavos' => $movements->last()['saldo_centavos'] ?? $opening, 'saldo_actual_centavos' => (int) $account->saldo_centavos,
                'creditos_centavos' => $movements->where('importe_centavos', '>', 0)->sum('importe_centavos'), 'debitos_centavos' => -$movements->where('importe_centavos', '<', 0)->sum('importe_centavos'),
                'movimientos' => $movements->values()];
        });

        return $this->ok($this->envelope($r, 'Estado de cuenta', $f + ['desde' => $from->timezone(config('operations.timezone'))->toDateString(), 'hasta' => $to->timezone(config('operations.timezone'))->toDateString()]) + [
            'cliente' => ['id' => (string) $customer->id, 'nombre' => $customer->nombre, 'dpi_enmascarado' => $customer->dpi_enmascarado, 'correo' => $customer->email, 'nit' => $customer->nit],
            'cuentas' => $statements->values(),
            'prestamos' => Credit::where('cliente_id', (string) $customer->id)->get()->map(fn (Credit $c): array => array_intersect_key($loans->view($c), array_flip(['referencia', 'producto', 'estado', 'monto_centavos', 'limite_centavos', 'saldo_centavos', 'pendiente_total_centavos', 'cuotas_pagadas', 'cuotas_total', 'proxima_cuota'])))->values(),
            'tarjetas' => Card::where('cliente_id', (string) $customer->id)->get()->map(fn (Card $c): array => ['referencia' => $c->referencia, 'producto' => $c->producto, 'tipo' => $c->tipo, 'limite_centavos' => (int) $c->limite_centavos, 'deuda_centavos' => (int) $c->principal_centavos + (int) $c->interes_centavos, 'estado' => $c->estado])->values(),
        ]);
    }

    public function transactions(Request $r)
    {
        $f = $r->validate(['desde' => 'sometimes|nullable|date_format:Y-m-d', 'hasta' => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:desde', 'tipo' => 'sometimes|nullable|string|max:40'], $this->messages());
        [$from, $to] = $this->range($f, 'month');
        $system = Account::whereNotNull('system_key')->get()->map(fn (Account $a) => (string) $a->id)->all();
        $query = Movement::whereNotNull('cuenta_id')->whereNotIn('cuenta_id', $system)->where('created_at', '>=', $from)->where('created_at', '<=', $to)->when($f['tipo'] ?? null, fn ($q, $t) => $q->where('tipo', $t));
        $total = (clone $query)->count();
        $movements = $query->orderBy('created_at')->limit(self::MAX_ROWS)->get();
        $accounts = Account::whereIn('_id', $movements->pluck('cuenta_id')->unique()->values()->all())->get()->keyBy(fn (Account $a) => (string) $a->id);
        $names = Customer::whereIn('_id', $accounts->pluck('cliente_id')->filter()->unique()->values()->all())->get()->keyBy(fn (Customer $c) => (string) $c->id);
        $rows = $movements->map(function (Movement $m) use ($accounts, $names): array {
            $account = $accounts[$m->cuenta_id] ?? null;

            return ['fecha' => $m->created_at?->toIso8601String(), 'cuenta_numero' => $account?->numero, 'titular' => $account?->empresa_id ? (Company::find($account->empresa_id)?->nombre ?? 'Empresa').' (empresa)' : ($names->get((string) $account?->cliente_id)?->nombre ?? '—'), 'tipo' => $m->tipo, 'concepto' => $m->concepto, 'importe_centavos' => (int) $m->importe_centavos];
        });
        $byType = $rows->groupBy('tipo')->map(fn ($group, $type): array => ['tipo' => $type, 'cantidad' => $group->count(), 'ingresos_centavos' => $group->where('importe_centavos', '>', 0)->sum('importe_centavos'), 'egresos_centavos' => -$group->where('importe_centavos', '<', 0)->sum('importe_centavos')])->sortBy('tipo')->values();

        return $this->ok($this->envelope($r, 'Transacciones globales', $f + ['desde' => $from->timezone(config('operations.timezone'))->toDateString(), 'hasta' => $to->timezone(config('operations.timezone'))->toDateString()]) + [
            'movimientos' => $rows->values(), 'por_tipo' => $byType,
            'totales' => ['movimientos' => $rows->count(), 'clientes' => $rows->pluck('titular')->unique()->count(), 'ingresos_centavos' => $rows->where('importe_centavos', '>', 0)->sum('importe_centavos'), 'egresos_centavos' => -$rows->where('importe_centavos', '<', 0)->sum('importe_centavos')],
            'truncado' => $total > self::MAX_ROWS, 'total_encontrado' => $total,
        ]);
    }

    private function envelope(Request $r, string $title, array $filters): array
    {
        return ['reporte' => $title, 'generado_el' => now()->toIso8601String(), 'generado_por' => $r->user()?->name, 'filtros' => array_filter($filters, fn ($v) => $v !== null && $v !== ''), 'tipo_cambio' => app(ExchangeRateService::class)->current()];
    }

    /**
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} UTC limits of the Guatemala calendar days chosen.
     */
    private function range(array $f, ?string $default): array
    {
        $zone = config('operations.timezone');
        $today = CarbonImmutable::now($zone);
        $from = isset($f['desde']) ? CarbonImmutable::createFromFormat('!Y-m-d', $f['desde'], $zone) : ($default === 'month' ? $today->startOfMonth() : null);
        $to = isset($f['hasta']) ? CarbonImmutable::createFromFormat('!Y-m-d', $f['hasta'], $zone)->endOfDay() : ($default === 'month' ? $today->endOfDay() : null);

        return [$from?->utc(), $to?->utc()];
    }

    private function allowed(Request $r, string $permission): bool
    {
        $permissions = $r->attributes->get('permissions', []);

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    private function messages(): array
    {
        return ['required' => 'Selecciona este filtro.', 'regex' => 'El valor no es válido.', 'date_format' => 'Usa una fecha válida.', 'after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.', 'in' => 'Selecciona una opción válida.'];
    }
}
