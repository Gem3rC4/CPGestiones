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

    public function pay(string $id, array $d, string $actor): array
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
        $result = app(AccountService::class)->move((string) $source->id, (string) $clearing->id, $c, $actor, 'Pago de tarjeta '.$card->referencia, 'pago_tarjeta');
        $interest = min($c, $card->interes_centavos);
        $card->update(['interes_centavos' => $card->interes_centavos - $interest, 'principal_centavos' => $card->principal_centavos - ($c - $interest)]);
        $remaining = $c;
        foreach (Statement::where('tarjeta_id', $id)->orderBy('fecha_corte')->get() as $s) {
            $allocated = min($remaining, max(0, $s->minimo_centavos - $s->pagado_centavos));
            if ($allocated > 0) {
                $s->update(['pagado_centavos' => $s->pagado_centavos + $allocated]);
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

    public function closeDay(string $date): array
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, config('operations.timezone'));
        if (! $day || $day->format('Y-m-d') !== $date || $date !== now()->timezone(config('operations.timezone'))->toDateString()) {
            throw new BusinessException('El cierre solo puede utilizar el saldo del día actual.', 'INVALID_DATE', 422);
        }
        $count = 0;
        $pending = [];
        foreach (Card::where('tipo', 'credito')->get() as $snapshot) {
            // One card awaiting reconciliation must not stop interest and statements for the others.
            try {
                DB::transaction(function () use ($snapshot, $day, &$count) {
                    $card = Card::findOrFail($snapshot->id);
                    $id = (string) $card->id;
                    $date = $day->toDateString();
                    if ($date < $card->emitida_el || DailyAccrual::where('tarjeta_id', $id)->where('fecha', $date)->exists()) {
                        return;
                    }
                    // No historical backfill with today's balance: an operator must reconcile a missed day.
                    if ($card->ultimo_devengo && $date !== CarbonImmutable::parse($card->ultimo_devengo)->addDay()->toDateString()) {
                        throw new BusinessException('Hay un día pendiente de conciliación para '.$card->referencia.'.', 'ACCRUAL_GAP');
                    }
                    if (! $card->ultimo_devengo && $date !== $card->emitida_el) {
                        throw new BusinessException('Debe conciliarse el primer día de la tarjeta.', 'ACCRUAL_GAP');
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
                        Statement::create(['tarjeta_id' => $id, 'periodo' => $day->format('Y-m'), 'fecha_corte' => $date, 'fecha_pago' => $day->addDays((int) $card->policy['payment_days'])->toDateString(), 'principal_centavos' => $card->principal_centavos, 'interes_centavos' => $card->interes_centavos, 'saldo_centavos' => $debt, 'minimo_centavos' => $minimum, 'pagado_centavos' => 0, 'policy' => $card->policy]);
                    }
                    $count++;
                }, attempts: 5);
            } catch (BusinessException $e) {
                $pending[] = ['tarjeta_id' => (string) $snapshot->id, 'codigo' => $e->errorCode, 'mensaje' => $e->getMessage()];
            }
        }

        return ['tarjetas_procesadas' => $count, 'fecha' => $date, 'pendientes' => $pending];
    }
}
