<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Money;
use JsonException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_normalize_returns_integer_pennies(): void
    {
        $this->assertSame(1235, Money::normalize('12.345'));
        $this->assertSame(999, Money::normalize(9.99));
        $this->assertSame(100, Money::normalize(1.0));
        $this->assertSame(0, Money::normalize(-0.0));
        // Verify rounding: a sub-0.5 fractional result must floor, not ceil
        $this->assertSame(100, Money::normalize('1.001'));
    }

    public function test_from_pennies_formats_decimal_string(): void
    {
        $this->assertSame('12.34', Money::fromPennies(1234));
        $this->assertSame('0.00', Money::fromPennies(0));
    }

    public function test_add_sums_amounts_with_precision(): void
    {
        $this->assertSame('12.45', Money::add('10.10', 2.345));
        $this->assertSame('0.50', Money::add(-1.0, '1.50'));
        $this->assertSame('0.30', Money::add('0.10', '0.20'));
    }

    public function test_subtract_calculates_difference(): void
    {
        $this->assertSame('5.00', Money::subtract(10, 5));
        $this->assertSame('-0.25', Money::subtract('1.00', 1.25));
    }

    public function test_format_includes_currency_symbol_and_thousands_separator(): void
    {
        $this->assertSame('£1,234.50', Money::format(1234.5));
        $this->assertSame('£0.00', Money::format(0));
        $this->assertSame('-1,234.50', Money::formatPennies(-123450));
    }

    public function test_large_decimal_values_do_not_pass_through_float_arithmetic(): void
    {
        $this->assertSame(999999999999, Money::normalize('9999999999.99'));
        $this->assertSame('9,999,999,999.99', Money::formatPennies(999999999999));
    }

    public function test_non_finite_float_is_rejected_at_the_json_boundary(): void
    {
        $this->expectException(JsonException::class);

        Money::normalize(INF);
    }
}
