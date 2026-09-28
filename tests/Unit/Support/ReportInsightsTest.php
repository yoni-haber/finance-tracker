<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ReportInsights;
use PHPUnit\Framework\TestCase;

final class ReportInsightsTest extends TestCase
{
    public function test_empty_series_has_zero_totals_and_no_savings_rate(): void
    {
        $this->assertSame([
            'income' => 0,
            'spending' => 0,
            'savedAndInvested' => 0,
            'netCashFlow' => 0,
            'savingsRate' => null,
            'lastMonthNet' => 0,
            'priorMonthNet' => 0,
            'monthChange' => 0,
        ], ReportInsights::summary([
            'labels' => [],
            'income' => [],
            'spending' => [],
            'savedAndInvested' => [],
        ]));
    }

    public function test_summary_compares_the_latest_month_with_the_previous_month(): void
    {
        $this->assertSame([
            'income' => 30000,
            'spending' => 10000,
            'savedAndInvested' => 5000,
            'netCashFlow' => 15000,
            'savingsRate' => 17,
            'lastMonthNet' => 8000,
            'priorMonthNet' => 7000,
            'monthChange' => 1000,
        ], ReportInsights::summary([
            'labels' => ['May', 'June'],
            'income' => [100.0, 200.0],
            'spending' => [20.0, 80.0],
            'savedAndInvested' => [10.0, 40.0],
        ]));
    }

    public function test_savings_rate_rounds_to_the_nearest_percent(): void
    {
        foreach ([[101.0, 50.0, 50], [99.0, 49.0, 49]] as [$income, $saved, $expected]) {
            $summary = ReportInsights::summary([
                'labels' => ['June'],
                'income' => [$income],
                'spending' => [0.0],
                'savedAndInvested' => [$saved],
            ]);

            $this->assertSame($expected, $summary['savingsRate']);
            $this->assertSame((int) ($income * 100 - $saved * 100), $summary['lastMonthNet']);
        }
    }
}
