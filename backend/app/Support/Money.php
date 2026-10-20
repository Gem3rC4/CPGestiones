<?php

namespace App\Support;

use App\Exceptions\BusinessException;

final class Money
{
    public const MAX = 100_000_000_000;

    public static function cents(mixed $value, bool $allowZero = false): int
    {
        if (! is_string($value) || ! preg_match('/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D', $value)) {
            throw new BusinessException('Usa un importe decimal en texto, por ejemplo "150.25".', 'INVALID_AMOUNT', 422);
        }
        $parts = explode('.', $value);
        $c = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
        if ($c > self::MAX || $c < ($allowZero ? 0 : 1)) {
            throw new BusinessException('El importe está fuera del rango permitido.', 'INVALID_AMOUNT', 422);
        }

        return $c;
    }

    public static function format(int $c): string
    {
        return ($c < 0 ? '-' : '').intdiv(abs($c), 100).'.'.str_pad((string) (abs($c) % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function ratio(int $value, int $numerator, int $denominator): int
    {
        return intdiv($value * $numerator + intdiv($denominator, 2), $denominator);
    }
}
