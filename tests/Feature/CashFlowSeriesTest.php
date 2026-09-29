<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Support\CashFlowSeries;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CashFlowSeriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_series_includes_projected_occurrences_and_preserves_chart_shape(): void
    {
        Carbon::setTestNow('2024-06-15');
        $user = User::factory()->create();
        Transaction::factory()->for($user)->create([
            'type' => Transaction::TYPE_INCOME,
            'amount' => '50.00',
            'date' => '2024-05-01',
            'is_recurring' => true,
            'frequency' => 'monthly',
        ]);

        $projected = CashFlowSeries::endingAt($user->id, 6, 2024, 2);
        $recorded = CashFlowSeries::endingAt($user->id, 6, 2024, 2, false);

        $this->assertSame(['labels', 'income', 'spending', 'savedAndInvested'], array_keys($projected));
        $this->assertSame(['May 2024', 'Jun 2024'], $projected['labels']);
        $this->assertSame([50.0, 50.0], $projected['income']);
        $this->assertSame([50.0, 0.0], $recorded['income']);
    }
}
