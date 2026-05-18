<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_normalize_returns_integer_pennies(): void
    {
        $this->assertSame(1235, Money::normalize('12.345'));
        $this->assertSame(999, Money::normalize(9.99));
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
    }
}
