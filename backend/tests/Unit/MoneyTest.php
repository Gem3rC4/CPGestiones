<?php

namespace Tests\Unit;

use App\Exceptions\BusinessException;
use App\Support\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_decimal_strings_have_exact_cent_values(): void
    {
        self::assertSame(10, Money::cents('0.10'));
        self::assertSame(100001, Money::cents('1000.01'));
        self::assertSame('1000.01', Money::format(100001));
        self::assertSame(10, Money::ratio(10000, 3600, 3650000));
    }

    public function test_invalid_or_ambiguous_amounts_are_rejected(): void
    {
        foreach ([0.1, 100, '-1', '1e3', '1,500', '1.001', '0.00', '1000000001.00'] as $value) {
            try {
                Money::cents($value);
                self::fail('Accepted invalid money: '.json_encode($value));
            } catch (BusinessException $e) {
                self::assertSame('INVALID_AMOUNT', $e->errorCode);
            }
        }
    }
}
