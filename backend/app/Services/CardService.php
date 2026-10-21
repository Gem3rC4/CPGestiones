<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Account;
use App\Models\Card;
use App\Models\DailyAccrual;
use App\Models\Movement;
use App\Models\Statement;
use App\Repositories\CustomerRepository;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CardService
{
    public function charge(string $id, array $d, string $actor): array
    {
        $card = Card::findOrFail($id);
        $c = Money::cents($d['monto']);
        $this->owner($card, $d, $actor);
        if ($card->estado !== 'activa') {
            throw new BusinessException('La tarjeta está bloqueada.', 'CARD_BLOCKED');
        }
        if ($card->tipo === 'debito') {
            $clearing = Account::where('system_key', 'comercios')->firstOrFail();
            $result = app(AccountService::class)->move($card->cuenta_id, (string) $clearing->id, $c, $actor, $d['concepto'], 'consumo_debito');
        } else {
            if ($card->principal_centavos + $card->interes_centavos + $c > $card->limite_centavos) {
                throw new BusinessException('El consumo supera el crédito disponible.', 'CREDIT_LIMIT');
            }
            $card->update(['principal_centavos' => $card->principal_centavos + $c]);
            $result = ['referencia' => (string) Str::uuid(), 'estado' => 'completada'];
            Movement::create(['transaccion_id' => $result['referencia'], 'tarjeta_id' => $id, 'importe_centavos' => $c, 'tipo' => 'consumo_credito', 'concepto' => $d['concepto'], 'actor' => $actor]);
            app(SecurityService::class)->record('consumo_credito', $actor, ['tarjeta_id' => $id]);
        }

        return $result;
    }

    public function pay(string $id, array $d, string $actor, string $concept = 'Pago de tarjeta'): array
    {
        $card = Card::findOrFail($id);
        $c = Money::cents($d['monto']);
        if ($card->tipo !== 'credito') {
            throw new BusinessException('Solo las tarjetas de crédito reciben pagos.', 'INVALID_CARD', 422);
        }
        $this->owner($card, $d, $actor);
        $source = Account::findOrFail($d['origen_id']);
        if ($source->cliente_id !== $card->cliente_id) {
            throw new BusinessException('La cuenta debe pertenecer al titular.', 'INVALID_ACCOUNT', 422);
        }
        $debt = $card->principal_centavos + $card->interes_centavos;
        if ($c > $debt) {
            throw new BusinessException('El pago supera la deuda pendiente.', 'PAYMENT_EXCEEDS_DEBT', 422);
        }
        $clearing = Account::where('system_key', 'pagos_tarjeta')->firstOrFail();
        $result = app(AccountService::class)->move((string) $source->id, (string) $clearing->id, $c, $actor, $concept.' '.$card->referencia, 'pago_tarjeta');
        $interest = min($c, $card->interes_centavos);
        $card->update(['interes_centavos' => $card->interes_centavos - $interest, 'principal_centavos' => $card->principal_centavos - ($c - $interest)]);
        // Payments settle the oldest statement first; pagado_centavos keeps tracking the minimum payment.
        $remaining = $c;
        foreach (Statement::where('tarjeta_id', $id)->orderBy('fecha_corte')->get() as $s) {
            $open = (int) $s->saldo_centavos - (int) ($s->abonado_centavos ?? $s->pagado_centavos);
            $allocated = min($remaining, max(0, $open));
            if ($allocated > 0) {
                $paid = (int) ($s->abonado_centavos ?? $s->pagado_centavos) + $allocated;
                $s->update(['abonado_centavos' => $paid, 'pagado_centavos' => min((int) $s->minimo_centavos, $paid)]);
                $remaining -= $allocated;
            }
        }

        return $result;
    }

    public function owner(Card $card, array $d, string $actor): void
    {
        if ($actor === 'service:banca') {
            $customer = app(CustomerRepository::class)->resolve($d);
            if ($card->cliente_id !== (string) $customer->id) {
                throw new BusinessException('La tarjeta no pertenece al cliente.', 'FORBIDDEN', 403);
            }
        }
    }

    /**
     * Daily interest and statements. Days the scheduler missed (a sleeping free host) are caught up with the
     * current balance, so a card never stays stuck and its cut-off still produces the statement.
     */
    public function closeDay(string $date): array
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, config('operations.timezone'));
        if (! $day || $day->format('Y-m-d') !== $date || $date !== now()->timezone(config('operations.timezone'))->toDateString()) {
            throw new BusinessException('El cierre solo puede utilizar el saldo del día actual.', 'INVALID_DATE', 422);
        }
        $count = 0;
        $pending = [];
        foreach (Card::where('tipo', 'credito')->get() as $snapshot) {
            // One failing card must not stop interest and statements for the others.
            try {
                DB::transaction(function () use ($snapshot, $day, &$count) {
                    $card = Card::findOrFail($snapshot->id);
                    $start = $card->ultimo_devengo ? CarbonImmutable::parse($card->ultimo_devengo, config('operations.timezone'))->addDay() : CarbonImmutable::parse($card->emitida_el, config('operations.timezone'));
                    $processed = false;
                    for ($current = $start->startOfDay(); $current->lte($day); $current = $current->addDay()) {
                        $processed = $this->accrue($card, $current) || $processed;
                    }
                    if ($processed) {
                        $count++;
                    }
                }, attempts: 5);
            } catch (BusinessException $e) {
                $pending[] = ['tarjeta_id' => (string) $snapshot->id, 'codigo' => $e->errorCode, 'mensaje' => $e->getMessage()];
            }
        }

        return ['tarjetas_procesadas' => $count, 'fecha' => $date, 'pendientes' => $pending];
    }

    /**
     * Automatic debit on the payment day of each statement: the statement balance not yet paid is charged to
     * the account chosen when the card was approved (as much as the account holds). Catches up missed days.
     */
    public function collectStatements(string $date): array
    {
        $results = [];
        foreach (Statement::where('fecha_pago', '<=', $date)->get() as $snapshot) {
            if (((int) ($snapshot->abonado_centavos ?? $snapshot->pagado_centavos)) >= (int) $snapshot->saldo_centavos || ($snapshot->cargo_automatico['fecha'] ?? null) === $date) {
                continue;
            }
            $card = Card::find($snapshot->tarjeta_id);
            if (! $card || ! ($card->cargo_automatico ?? false) || ! $card->cuenta_id) {
                continue;
            }
            try {
                $results[] = DB::transaction(function () use ($snapshot, $card, $date): array {
                    $statement = Statement::findOrFail($snapshot->id);
                    $card->refresh();
                    $open = (int) $statement->saldo_centavos - (int) ($statement->abonado_centavos ?? $statement->pagado_centavos);
                    $amount = min($open, (int) $card->principal_centavos + (int) $card->interes_centavos, (int) (Account::find($card->cuenta_id)?->saldo_centavos ?? 0));
                    if ($amount > 0) {
                        $this->pay((string) $card->id, ['origen_id' => $card->cuenta_id, 'monto' => Money::format($amount)], 'sistema:cobros', 'Cargo automático de tarjeta');
                    }
                    $outcome = $amount <= 0 ? 'sin_fondos' : ($amount === $open ? 'cobrado' : 'parcial');
                    $statement->refresh()->update(['cargo_automatico' => ['fecha' => $date, 'monto_centavos' => max(0, $amount), 'resultado' => $outcome]]);

                    return ['tarjeta' => $card->referencia, 'periodo' => $statement->periodo, 'resultado' => $outcome, 'monto' => Money::format(max(0, $amount))];
                }, attempts: 5);
            } catch (BusinessException $e) {
                $results[] = ['tarjeta' => $card->referencia, 'resultado' => 'error', 'mensaje' => $e->getMessage()];
            }
        }

        return $results;
    }

    private function accrue(Card $card, CarbonImmutable $day): bool
    {
        $id = (string) $card->id;
        $date = $day->toDateString();
        if ($date < $card->emitida_el || DailyAccrual::where('tarjeta_id', $id)->where('fecha', $date)->exists()) {
            return false;
        }
        $interest = Money::ratio((int) $card->principal_centavos, (int) $card->policy['annual_bps'], 3650000);
        $card->interes_centavos += $interest;
        $card->ultimo_devengo = $date;
        $card->save();
        DailyAccrual::create(['tarjeta_id' => $id, 'fecha' => $date, 'principal_centavos' => $card->principal_centavos, 'interes_centavos' => $interest, 'tasa_bps' => $card->policy['annual_bps']]);
        $cutoff = min((int) $card->policy['cutoff_day'], $day->daysInMonth);
        if ($day->day === $cutoff) {
            $debt = $card->principal_centavos + $card->interes_centavos;
            $minimum = min($debt, max((int) $card->policy['minimum_floor_cents'], Money::ratio($debt, (int) $card->policy['minimum_bps'], 10000)));
            Statement::create(['tarjeta_id' => $id, 'periodo' => $day->format('Y-m'), 'fecha_corte' => $date, 'fecha_pago' => $this->paymentDate($day, $card->policy), 'principal_centavos' => $card->principal_centavos, 'interes_centavos' => $card->interes_centavos, 'saldo_centavos' => $debt, 'minimo_centavos' => $minimum, 'pagado_centavos' => 0, 'abonado_centavos' => 0, 'policy' => $card->policy]);
        }

        return true;
    }

    /**
     * Cards approved with a payment day pay on that day after the cut-off; older cards use "days after cut-off".
     */
    private function paymentDate(CarbonImmutable $cutoff, array $policy): string
    {
        if (! isset($policy['payment_day'])) {
            return $cutoff->addDays((int) $policy['payment_days'])->toDateString();
        }
        $month = (int) $policy['payment_day'] > $cutoff->day ? $cutoff->startOfMonth() : $cutoff->startOfMonth()->addMonthNoOverflow();

        return $month->setDay(min((int) $policy['payment_day'], $month->daysInMonth))->toDateString();
    }
}
