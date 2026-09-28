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
}
