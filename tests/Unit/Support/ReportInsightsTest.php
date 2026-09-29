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
            'invested' => 0,
            'savings' => 0,
            'investmentRate' => null,
            'savingsRate' => null,
            'lastMonthSavings' => 0,
            'priorMonthSavings' => 0,
            'monthChange' => 0,
        ], ReportInsights::summary([
            'labels' => [],
            'income' => [],
            'spending' => [],
            'invested' => [],
            'savings' => [],
        ]));
    }

    public function test_summary_compares_the_latest_month_with_the_previous_month(): void
    {
        $this->assertSame([
            'income' => 30000,
            'spending' => 10000,
            'invested' => 5000,
            'savings' => 15000,
            'investmentRate' => 17,
            'savingsRate' => 50,
            'lastMonthSavings' => 8000,
            'priorMonthSavings' => 7000,
            'monthChange' => 1000,
        ], ReportInsights::summary([
            'labels' => ['May', 'June'],
            'income' => [100.0, 200.0],
            'spending' => [20.0, 80.0],
            'invested' => [10.0, 40.0],
            'savings' => [70.0, 80.0],
        ]));
    }

    public function test_savings_rate_rounds_to_the_nearest_percent(): void
    {
        foreach ([[101.0, 50.0, 50], [99.0, 49.0, 49]] as [$income, $saved, $expected]) {
            $summary = ReportInsights::summary([
                'labels' => ['June'],
                'income' => [$income],
                'spending' => [0.0],
                'invested' => [$income - $saved],
                'savings' => [$saved],
            ]);

            $this->assertSame($expected, $summary['savingsRate']);
            $this->assertSame((int) ($saved * 100), $summary['lastMonthSavings']);
        }
    }

    public function test_investment_rate_rounds_and_is_unavailable_without_income(): void
    {
        $allIncomeInvested = ReportInsights::summary([
            'labels' => ['June'],
            'income' => [100.0], 'spending' => [0.0], 'invested' => [100.0], 'savings' => [0.0],
        ]);
        $this->assertSame(100, $allIncomeInvested['investmentRate']);

        $summary = ReportInsights::summary([
            'labels' => ['June'],
            'income' => [99.0], 'spending' => [0.0], 'invested' => [49.0], 'savings' => [50.0],
        ]);
        $this->assertSame(49, $summary['investmentRate']);

        $withoutIncome = ReportInsights::summary([
            'labels' => ['June'],
            'income' => [0.0], 'spending' => [0.0], 'invested' => [20.0], 'savings' => [-20.0],
        ]);
        $this->assertNull($withoutIncome['investmentRate']);
    }
}
