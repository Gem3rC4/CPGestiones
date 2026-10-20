<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Account;
use App\Models\DailyAccrual;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SavingsService
{
    public function closeDay(string $date): array
    {
        $today = now()->timezone(config('operations.timezone'))->toDateString();
        if ($date !== $today) {
            throw new BusinessException('El cierre de ahorro solo utiliza el saldo del día actual.', 'INVALID_DATE', 422);
        }
        $count = 0;
        $pending = [];
        foreach (Account::where('tipo', 'ahorro')->where('estado', 'activa')->get() as $snapshot) {
            // One account awaiting reconciliation must not stop the interest of the others.
            try {
                DB::transaction(function () use ($snapshot, $date, &$count) {
                    $a = Account::findOrFail($snapshot->id);
                    $id = (string) $a->id;
                    if (DailyAccrual::where('cuenta_id', $id)->where('fecha', $date)->exists()) {
                        return;
                    }
                    if ($a->ultimo_devengo && CarbonImmutable::parse($a->ultimo_devengo)->addDay()->toDateString() !== $date) {
                        throw new BusinessException('Hay un cierre de ahorro pendiente de conciliación.', 'ACCRUAL_GAP');
                    }
                    $interest = Money::ratio((int) $a->saldo_centavos, (int) $a->tasa_bps, 3650000);
                    DailyAccrual::create(['cuenta_id' => $id, 'fecha' => $date, 'saldo_centavos' => $a->saldo_centavos, 'interes_centavos' => $interest]);
                    if ($interest > 0) {
                        $source = Account::where('system_key', 'intereses')->firstOrFail();
                        app(AccountService::class)->move((string) $source->id, $id, $interest, 'scheduler', 'Interés diario de ahorro', 'interes_ahorro');
                    }
                    $a->refresh()->update(['ultimo_devengo' => $date]);
                    $count++;
                }, attempts: 5);
            } catch (BusinessException $e) {
                $pending[] = ['cuenta_id' => (string) $snapshot->id, 'codigo' => $e->errorCode, 'mensaje' => $e->getMessage()];
            }
        }

        return ['cuentas_procesadas' => $count, 'fecha' => $date, 'pendientes' => $pending];
    }
}
