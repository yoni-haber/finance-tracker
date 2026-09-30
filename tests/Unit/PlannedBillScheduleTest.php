<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PlannedBill;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class PlannedBillScheduleTest extends TestCase
{
    public function test_quarterly_schedule_keeps_its_original_day_after_short_months(): void
    {
        $plannedBill = new PlannedBill([
            'next_due_date' => '2024-01-31',
            'anchor_day' => 31,
            'frequency' => 'quarterly',
        ]);

        $first = $plannedBill->dateAfter($plannedBill->next_due_date);
        $second = $plannedBill->dateAfter($first);
        $third = $plannedBill->dateAfter($second);

        $this->assertSame('2024-04-30', $first->toDateString());
        $this->assertSame('2024-07-31', $second->toDateString());
        $this->assertSame('2024-10-31', $third->toDateString());
    }

    public function test_yearly_schedule_returns_to_february_29_in_a_leap_year(): void
    {
        $plannedBill = new PlannedBill([
            'next_due_date' => '2024-02-29',
            'anchor_day' => 29,
            'frequency' => 'yearly',
        ]);

        $date = CarbonImmutable::parse('2024-02-29');
        $dates = [];
        for ($i = 0; $i < 4; $i++) {
            $date = $plannedBill->dateAfter($date);
            $dates[] = $date->toDateString();
        }

        $this->assertSame(['2025-02-28', '2026-02-28', '2027-02-28', '2028-02-29'], $dates);
    }

    public function test_unpaid_dates_include_the_next_due_date_and_stop_at_range_end(): void
    {
        $plannedBill = new PlannedBill([
            'next_due_date' => '2025-11-30',
            'anchor_day' => 30,
            'frequency' => 'quarterly',
        ]);

        $this->assertSame(
            ['2025-11-30', '2026-02-28', '2026-05-30'],
            $plannedBill->unpaidDatesThrough(CarbonImmutable::parse('2026-05-30'))
                ->map(fn (CarbonImmutable $date): string => $date->toDateString())->all(),
        );
    }
}
