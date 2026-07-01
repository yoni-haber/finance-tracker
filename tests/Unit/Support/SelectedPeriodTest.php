<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SelectedPeriod;
use Carbon\Carbon;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SelectedPeriodTest extends TestCase
{
    public function test_current_returns_the_present_month(): void
    {
        Carbon::setTestNow('2024-05-15');

        $selectedPeriod = SelectedPeriod::current();

        $this->assertSame(5, $selectedPeriod->month);
        $this->assertSame(2024, $selectedPeriod->year);
    }

    public function test_label_formats_month_and_year(): void
    {
        $this->assertSame('May 2024', new SelectedPeriod(5, 2024)->label());
    }

    public function test_start_of_month_returns_first_day(): void
    {
        $this->assertSame('2024-05-01', new SelectedPeriod(5, 2024)->startOfMonth()->toDateString());
    }

    public function test_previous_rolls_back_across_year_boundary(): void
    {
        $previous = new SelectedPeriod(1, 2024)->previous();

        $this->assertSame(12, $previous->month);
        $this->assertSame(2023, $previous->year);
    }

    public function test_next_rolls_forward_across_year_boundary(): void
    {
        $next = new SelectedPeriod(12, 2024)->next();

        $this->assertSame(1, $next->month);
        $this->assertSame(2025, $next->year);
    }

    public function test_is_current_month_reflects_now(): void
    {
        Carbon::setTestNow('2024-05-15');

        $this->assertTrue(new SelectedPeriod(5, 2024)->isCurrentMonth());
        $this->assertFalse(new SelectedPeriod(4, 2024)->isCurrentMonth());
    }

    public function test_clamp_passes_through_in_range_values(): void
    {
        $selectedPeriod = SelectedPeriod::clamp(5, 2024);

        $this->assertSame(5, $selectedPeriod->month);
        $this->assertSame(2024, $selectedPeriod->year);
    }

    /**
     * @return Iterator<string, array{int, int}>
     */
    public static function monthBoundaryProvider(): Iterator
    {
        yield 'below floor clamps to 1' => [0, 1];

        yield 'floor stays 1' => [1, 1];

        yield 'ceiling stays 12' => [12, 12];

        yield 'above ceiling clamps to 12' => [13, 12];
    }

    #[DataProvider('monthBoundaryProvider')]
    public function test_clamp_bounds_month(int $input, int $expected): void
    {
        $this->assertSame($expected, SelectedPeriod::clamp($input, 2024)->month);
    }

    /**
     * @return Iterator<string, array{int, int}>
     */
    public static function yearBoundaryProvider(): Iterator
    {
        yield 'below floor clamps to min' => [1999, SelectedPeriod::MIN_YEAR];

        yield 'floor stays min' => [2000, SelectedPeriod::MIN_YEAR];

        yield 'ceiling stays max' => [2100, SelectedPeriod::MAX_YEAR];

        yield 'above ceiling clamps to max' => [2101, SelectedPeriod::MAX_YEAR];
    }

    #[DataProvider('yearBoundaryProvider')]
    public function test_clamp_bounds_year(int $input, int $expected): void
    {
        $this->assertSame($expected, SelectedPeriod::clamp(5, $input)->year);
    }
}
