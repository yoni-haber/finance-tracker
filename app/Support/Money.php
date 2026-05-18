<?php

declare(strict_types=1);

namespace App\Support;

class Money
{
    public static function normalize(string|int|float $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function fromPennies(int $pennies): string
    {
        return number_format($pennies / 100, 2, '.', '');
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
        return '£'.number_format(((float) $amount), 2, '.', ',');
    }
}
