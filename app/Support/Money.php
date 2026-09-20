<?php

declare(strict_types=1);

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class Money
{
    public static function normalize(string|int|float $amount): int
    {
        // BigDecimal intentionally rejects floats. Convert legacy float inputs at
        // this boundary using a fixed decimal representation so their binary
        // approximation never participates in the integer arithmetic below.
        $decimal = is_float($amount)
            ? rtrim(rtrim(sprintf('%.14F', $amount), '0'), '.')
            : $amount;

        return BigDecimal::of($decimal === '-0' ? '0' : $decimal)
            ->multipliedBy(100)
            ->toScale(0, RoundingMode::HalfUp)
            ->toInt();
    }

    public static function fromPennies(int $pennies): string
    {
        $sign = $pennies < 0 ? '-' : '';
        $absolute = abs($pennies);

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100);
    }

    public static function add(string|int|float $a, string|int|float $b): string
    {
        $total = self::normalize($a) + self::normalize($b);

        return self::fromPennies($total);
    }

    public static function subtract(string|int|float $a, string|int|float $b): string
    {
        $difference = self::normalize($a) - self::normalize($b);

        return self::fromPennies($difference);
    }

    public static function format(string|int|float $amount): string
    {
        return '£' . self::formatPennies(self::normalize($amount));
    }

    public static function formatPennies(int $pennies): string
    {
        $decimal = self::fromPennies($pennies);
        $negative = str_starts_with($decimal, '-');
        [$whole, $fraction] = explode('.', ltrim($decimal, '-'), 2);

        return ($negative ? '-' : '') . number_format((int) $whole) . '.' . $fraction;
    }
}
