<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Account;
use App\Models\CardApplication;
use App\Models\Credit;
use App\Repositories\CustomerRepository;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Loans: approval disburses the money from the bank's master account into the customer's account and
 * creates a fixed-installment (French) schedule. Installments are paid from Banca Virtual or debited
 * automatically on the payment day. Callers that mutate hold a MongoDB transaction.
 */
class LoanService
{
    public function __construct(private AccountService $accounts) {}

    /**
     * @param  array{plazo_meses: int, tasa_anual: string, dia_pago: int, cuenta_id: string}  $terms
     */
    public function issue(CardApplication $application, int $principal, array $terms, string $actor): Credit
    {
        $account = Account::find($terms['cuenta_id'] ?? '');
        if (! $account || $account->cliente_id !== $application->cliente_id || $account->estado !== 'activa' || $account->system_key) {
            throw new BusinessException('Selecciona una cuenta activa del cliente para depositar el préstamo.', 'INVALID_ACCOUNT', 422);
        }
        $months = (int) $terms['plazo_meses'];
        $day = (int) $terms['dia_pago'];
        $bps = self::rateToBps($terms['tasa_anual']);
        $issuedOn = CarbonImmutable::now(config('operations.timezone'))->startOfDay();
        $installments = $this->schedule($principal, $bps, $months, $day, $issuedOn);
        $credit = Credit::create([
            'solicitud_id' => (string) $application->id, 'cliente_id' => $application->cliente_id, 'producto' => $application->producto,
            'referencia' => 'CRE-'.strtoupper(bin2hex(random_bytes(6))), 'moneda' => 'GTQ', 'operador_id' => $actor,
            'limite_centavos' => $principal, 'monto_centavos' => $principal, 'desembolsado_centavos' => $principal, 'saldo_centavos' => $principal,
            'tasa_bps' => $bps, 'plazo_meses' => $months, 'dia_pago' => $day, 'cuota_centavos' => $installments[0]['monto_centavos'],
            'total_a_pagar_centavos' => array_sum(array_column($installments, 'monto_centavos')), 'pagado_centavos' => 0, 'intereses_pagados_centavos' => 0,
            'cuenta_id' => (string) $account->id, 'cuenta_numero' => $account->numero, 'cuotas' => $installments,
            'estado' => 'vigente', 'desembolsado_el' => $issuedOn->toDateString(),
        ]);
        $master = Account::where('system_key', 'concentradora')->firstOrFail();
        $this->accounts->move((string) $master->id, (string) $account->id, $principal, $actor, 'Desembolso de préstamo '.$credit->referencia, 'desembolso_prestamo');

        return $credit;
    }

    /**
     * Fixed installment: cuota = P·r / (1 − (1 + r)^−n), r = annual rate / 12. Interest is computed on the
     * outstanding balance and the last installment absorbs the rounding, so the principal is repaid exactly.
     *
     * @return list<array<string, mixed>>
     */
    public function schedule(int $principal, int $annualBps, int $months, int $day, CarbonImmutable $issuedOn): array
    {
        $rate = $annualBps / 120000;
        $installment = $rate > 0 ? (int) round($principal * $rate / (1 - (1 + $rate) ** -$months)) : (int) ceil($principal / $months);
        $balance = $principal;
        $rows = [];
        for ($number = 1; $number <= $months; $number++) {
            $interest = Money::ratio($balance, $annualBps, 120000);
            $capital = $number === $months ? $balance : min($balance, max(0, $installment - $interest));
            $balance -= $capital;
            $month = $issuedOn->startOfMonth()->addMonthsNoOverflow($number);
            $rows[] = ['numero' => $number, 'fecha_vencimiento' => $month->setDay(min($day, $month->daysInMonth))->toDateString(),
                'capital_centavos' => $capital, 'interes_centavos' => $interest, 'monto_centavos' => $capital + $interest,
                'saldo_despues_centavos' => $balance, 'pagado_centavos' => 0, 'estado' => 'pendiente'];
        }

        return $rows;
    }

    /**
     * Pays installments in order (interest first within each one). Without an amount it pays the next installment.
     */
    public function pay(string $id, array $d, string $actor, string $concept = 'Pago de préstamo'): array
    {
        $credit = Credit::findOrFail($id);
        if ($actor === 'service:banca' && $credit->cliente_id !== (string) app(CustomerRepository::class)->resolve($d)->id) {
            throw new BusinessException('El préstamo no pertenece al cliente.', 'FORBIDDEN', 403);
        }
        if ($credit->estado !== 'vigente') {
            throw new BusinessException('El préstamo no tiene saldo pendiente.', 'LOAN_SETTLED', 409);
        }
        $source = Account::findOrFail($d['origen_id']);
        if ($source->cliente_id !== $credit->cliente_id) {
            throw new BusinessException('La cuenta debe pertenecer al titular del préstamo.', 'INVALID_ACCOUNT', 422);
        }
        $pending = $this->pendingTotal($credit->cuotas);
        $next = collect($credit->cuotas)->first(fn (array $row): bool => $row['estado'] !== 'pagada');
        $amount = isset($d['monto']) ? Money::cents($d['monto']) : $next['monto_centavos'] - $next['pagado_centavos'];
        if ($amount > $pending) {
            throw new BusinessException('El pago supera el saldo pendiente del préstamo (Q '.Money::format($pending).').', 'PAYMENT_EXCEEDS_DEBT', 422);
        }
        $master = Account::where('system_key', 'concentradora')->firstOrFail();
        $movement = $this->accounts->move((string) $source->id, (string) $master->id, $amount, $actor, $concept.' '.$credit->referencia, 'pago_prestamo');
        [$installments, $capital, $interest, $covered] = $this->allocate($credit->cuotas, $amount, now()->toIso8601String());
        $settled = $this->pendingTotal($installments) === 0;
        $credit->update(['cuotas' => $installments, 'saldo_centavos' => (int) $credit->saldo_centavos - $capital,
            'pagado_centavos' => (int) $credit->pagado_centavos + $amount, 'intereses_pagados_centavos' => (int) $credit->intereses_pagados_centavos + $interest,
            'estado' => $settled ? 'pagado' : 'vigente', 'ultimo_pago' => ['fecha' => now()->toIso8601String(), 'monto_centavos' => $amount, 'transaccion_id' => $movement['transaccion_id'], 'actor' => $actor]]
            + ($settled ? ['pagado_el' => now()->toIso8601String()] : []));
        app(AuditService::class)->append(CardApplication::findOrFail($credit->solicitud_id), $actor, 'pago_prestamo', 'Pago de Q '.Money::format($amount).' aplicado a '.($covered ? 'la cuota '.implode(', ', $covered) : 'las cuotas pendientes').'. Saldo de capital: Q '.Money::format((int) $credit->saldo_centavos).'.'.($settled ? ' Préstamo cancelado en su totalidad.' : ''));

        return $movement + ['credito' => $this->view($credit->refresh())];
    }

    /**
     * Automatic debit of installments due up to $date (catches up days the scheduler missed).
     */
    public function collectDue(string $date): array
    {
        $collected = [];
        foreach (Credit::where('estado', 'vigente')->get() as $snapshot) {
            $due = collect($snapshot->cuotas)->filter(fn (array $row): bool => $row['estado'] !== 'pagada' && $row['fecha_vencimiento'] <= $date);
            if ($due->isEmpty()) {
                continue;
            }
            try {
                $result = DB::transaction(function () use ($snapshot, $date): ?array {
                    $credit = Credit::findOrFail($snapshot->id);
                    $owed = collect($credit->cuotas)->filter(fn (array $row): bool => $row['estado'] !== 'pagada' && $row['fecha_vencimiento'] <= $date)->sum(fn (array $row): int => $row['monto_centavos'] - $row['pagado_centavos']);
                    $available = (int) (Account::find($credit->cuenta_id)?->saldo_centavos ?? 0);
                    $amount = min($owed, $available);
                    if ($amount < 1) {
                        return ['credito' => $credit->referencia, 'resultado' => 'sin_fondos', 'adeudado' => Money::format($owed)];
                    }
                    $this->pay((string) $credit->id, ['origen_id' => $credit->cuenta_id, 'monto' => Money::format($amount)], 'sistema:cobros', 'Débito automático de cuota');

                    return ['credito' => $credit->referencia, 'resultado' => $amount === $owed ? 'cobrado' : 'parcial', 'monto' => Money::format($amount)];
                }, attempts: 5);
                $collected[] = $result;
            } catch (BusinessException $e) {
                $collected[] = ['credito' => $snapshot->referencia, 'resultado' => 'error', 'mensaje' => $e->getMessage()];
            }
        }

        return $collected;
    }

    /**
     * What the expediente and Banca Virtual show: schedule with overdue flags, next installment and totals.
     */
    public function view(Credit $credit): array
    {
        $today = now()->timezone(config('operations.timezone'))->toDateString();
        $installments = collect($credit->cuotas ?? [])->map(fn (array $row): array => $row + ['vencida' => $row['estado'] !== 'pagada' && $row['fecha_vencimiento'] < $today]);
        $next = $installments->first(fn (array $row): bool => $row['estado'] !== 'pagada');

        return $credit->toArray() + [
            'cuotas_total' => $installments->count(), 'cuotas_pagadas' => $installments->where('estado', 'pagada')->count(),
            'cuotas_vencidas' => $installments->where('vencida', true)->count(),
            'pendiente_total_centavos' => $this->pendingTotal($installments->all()),
            'proxima_cuota' => $next ? ['numero' => $next['numero'], 'fecha_vencimiento' => $next['fecha_vencimiento'], 'monto_centavos' => $next['monto_centavos'] - $next['pagado_centavos'], 'monto' => Money::format($next['monto_centavos'] - $next['pagado_centavos'])] : null,
            'tasa_anual' => isset($credit->tasa_bps) ? rtrim(rtrim(number_format($credit->tasa_bps / 100, 2, '.', ''), '0'), '.') : null,
            'saldo' => Money::format((int) $credit->saldo_centavos),
        ] + ['cuotas' => $installments->values()->all()];
    }

    public static function rateToBps(string $rate): int
    {
        if (! preg_match('/^(0|[1-9][0-9]?)(\.[0-9]{1,2})?$/D', $rate)) {
            throw new BusinessException('Escribe la tasa anual como porcentaje, por ejemplo 18.5.', 'INVALID_RATE', 422);
        }
        [$whole, $decimals] = array_pad(explode('.', $rate), 2, '');
        $bps = (int) $whole * 100 + (int) str_pad($decimals, 2, '0');
        if ($bps > 6000) {
            throw new BusinessException('La tasa anual no puede superar 60 %.', 'INVALID_RATE', 422);
        }

        return $bps;
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int, 2: int, 3: list<int>} installments, capital, interest, numbers fully paid
     */
    private function allocate(array $installments, int $amount, string $paidAt): array
    {
        $capitalPaid = 0;
        $interestPaid = 0;
        $covered = [];
        foreach ($installments as &$row) {
            if ($amount < 1 || $row['estado'] === 'pagada') {
                continue;
            }
            $applied = min($amount, $row['monto_centavos'] - $row['pagado_centavos']);
            $interestLeft = max(0, $row['interes_centavos'] - $row['pagado_centavos']);
            $interest = min($applied, $interestLeft);
            $interestPaid += $interest;
            $capitalPaid += $applied - $interest;
            $row['pagado_centavos'] += $applied;
            $amount -= $applied;
            if ($row['pagado_centavos'] === $row['monto_centavos']) {
                $row['estado'] = 'pagada';
                $row['pagada_el'] = $paidAt;
                $covered[] = $row['numero'];
            } else {
                $row['estado'] = 'parcial';
            }
        }

        return [$installments, $capitalPaid, $interestPaid, $covered];
    }

    private function pendingTotal(array $installments): int
    {
        return array_sum(array_map(fn (array $row): int => $row['monto_centavos'] - $row['pagado_centavos'], $installments));
    }
}
