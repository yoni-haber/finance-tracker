<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Support\UpcomingPayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class UpcomingPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 2)->startOfDay());
        $this->user = User::factory()->create();
    }

    /** @param array<string, mixed> $attributes */
    private function payment(array $attributes = []): Transaction
    {
        return Transaction::factory()->for($this->user)->create(array_merge([
            'type' => 'expense', 'category_id' => null, 'amount' => '100.00', 'date' => '2026-12-15',
        ], $attributes));
    }

    public function test_a_future_yearly_schedule_needs_no_historical_payment_and_is_not_persisted_by_projection(): void
    {
        $transaction = $this->payment(['description' => 'Car insurance', 'is_recurring' => true, 'frequency' => 'yearly']);
        $forecast = UpcomingPayments::forecast($this->user->id);

        $this->assertSame('2026-10-02', $forecast['start']->toDateString());
        $this->assertSame('2027-03-31', $forecast['end']->toDateString());
        $this->assertSame('2026-12-15', $forecast['payments']->sole()->date->toDateString());
        $this->assertSame($transaction->id, $forecast['payments']->sole()->id);
        $this->assertSame('100.00', $forecast['total']);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame(['October 2026', 'November 2026', 'December 2026', 'January 2027', 'February 2027', 'March 2027'], $forecast['months']->pluck('label')->all());
        $this->assertSame(['0.00', '0.00', '100.00', '0.00', '0.00', '0.00'], $forecast['months']->pluck('total')->all());
    }

    public function test_range_includes_today_and_last_day_but_excludes_adjacent_dates_and_sorts_ties_by_id(): void
    {
        $transaction = $this->payment(['date' => '2027-03-31', 'amount' => '0.10']);
        $first = $this->payment(['date' => '2026-10-02', 'amount' => '0.20']);
        $tie = $this->payment(['date' => '2026-10-02', 'amount' => '0.30']);
        $this->payment(['date' => '2026-10-01']);
        $this->payment(['date' => '2027-04-01']);

        $forecast = UpcomingPayments::forecast($this->user->id);
        $this->assertSame([$first->id, $tie->id, $transaction->id], $forecast['payments']->pluck('id')->all());
        $this->assertSame('0.60', $forecast['total']);
        $this->assertSame('0.50', $forecast['months']->pluck('total')->first());
        $this->assertSame('0.10', $forecast['months']->pluck('total')->last());
    }

    public function test_spending_classification_inherits_parent_and_excludes_income_saving_and_investments(): void
    {
        $spending = Category::factory()->for($this->user)->expense()->create();
        $legacy = Category::factory()->for($this->user)->expense()->create(['expense_treatment' => null]);
        $saving = Category::factory()->for($this->user)->expense()->create(['expense_treatment' => 'saving']);
        $investment = Category::factory()->for($this->user)->expense()->create(['expense_treatment' => 'investment']);
        $spendingChild = Category::factory()->subcategoryOf($spending)->create();
        $savingChild = Category::factory()->subcategoryOf($saving)->create();
        $investmentChild = Category::factory()->subcategoryOf($investment)->create();

        $expected = [];
        foreach ([null, $spending->id, $spendingChild->id, $legacy->id] as $category) {
            $expected[] = $this->payment(['category_id' => $category])->id;
        }

        foreach ([$saving->id, $investment->id, $savingChild->id, $investmentChild->id] as $category) {
            $this->payment(['category_id' => $category]);
        }

        $this->payment(['type' => 'income']);
        Transaction::factory()->create(['type' => 'expense', 'date' => '2026-12-15']);

        $forecast = UpcomingPayments::forecast($this->user->id, includeRegular: true);
        $this->assertSame($expected, $forecast['payments']->pluck('id')->all());
        $this->assertSame('400.00', $forecast['total']);
    }

    public function test_default_frequency_selection_and_regular_toggle(): void
    {
        $transaction = $this->payment(['date' => '2026-12-01']);
        $quarterly = $this->payment(['date' => '2026-12-01', 'is_recurring' => true, 'frequency' => 'quarterly']);
        $yearly = $this->payment(['date' => '2026-12-01', 'is_recurring' => true, 'frequency' => 'yearly']);
        $weekly = $this->payment(['date' => '2026-12-01', 'is_recurring' => true, 'frequency' => 'weekly', 'recurring_until' => '2026-12-01']);
        $monthly = $this->payment(['date' => '2026-12-01', 'is_recurring' => true, 'frequency' => 'monthly', 'recurring_until' => '2026-12-01']);

        $default = UpcomingPayments::forecast($this->user->id);
        $this->assertSame([$transaction->id, $quarterly->id, $yearly->id, $quarterly->id], $default['payments']->pluck('id')->all());
        $regular = UpcomingPayments::forecast($this->user->id, includeRegular: true);
        $this->assertSame([$transaction->id, $quarterly->id, $yearly->id, $weekly->id, $monthly->id, $quarterly->id], $regular['payments']->pluck('id')->all());
    }

    public function test_minimum_is_inclusive_applies_to_all_frequencies_and_can_be_zero(): void
    {
        $this->payment(['amount' => '99.99']);
        $transaction = $this->payment(['amount' => '100.00']);
        $above = $this->payment(['amount' => '100.01', 'is_recurring' => true, 'frequency' => 'yearly']);
        $regular = $this->payment(['amount' => '150.00', 'is_recurring' => true, 'frequency' => 'monthly', 'recurring_until' => '2026-12-15']);
        $this->payment(['amount' => '99.99', 'is_recurring' => true, 'frequency' => 'quarterly']);

        $filtered = UpcomingPayments::forecast($this->user->id, minimum: '100');
        $this->assertSame([$transaction->id, $above->id], $filtered['payments']->pluck('id')->all());
        $this->assertSame('200.01', $filtered['total']);
        $this->assertSame([$transaction->id, $above->id, $regular->id], UpcomingPayments::forecast($this->user->id, includeRegular: true, minimum: '100.00')['payments']->pluck('id')->all());
        $this->assertSame('150.00', UpcomingPayments::forecast($this->user->id, includeRegular: true, minimum: '100.02')['total']);
        $this->assertCount(5, UpcomingPayments::forecast($this->user->id, minimum: '0')['payments']);
    }

    public function test_blank_minimum_matches_explicit_zero_at_the_penny_boundary_for_legacy_amounts(): void
    {
        // Existing data can contain zero or signed amounts even though the editor requires a positive amount.
        $this->payment(['amount' => '-0.01']);
        $transaction = $this->payment(['amount' => '0.00']);
        $penny = $this->payment(['amount' => '0.01']);

        foreach (['', '0', '0.00'] as $minimum) {
            $forecast = UpcomingPayments::forecast($this->user->id, minimum: $minimum);
            $this->assertSame([$transaction->id, $penny->id], $forecast['payments']->pluck('id')->all());
            $this->assertSame('0.01', $forecast['total']);
            $december = $forecast['months']->firstWhere('label', 'December 2026');
            $this->assertIsArray($december);
            $this->assertSame('0.01', $december['total']);
        }

        $this->assertDatabaseCount('transactions', 3);
    }

    public function test_skipped_occurrences_and_ended_series_are_excluded_with_one_transaction_query(): void
    {
        $quarterly = $this->payment(['date' => '2026-09-30', 'is_recurring' => true, 'frequency' => 'quarterly', 'recurring_until' => '2027-03-30']);
        $quarterly->occurrenceExceptions()->create(['date' => '2026-12-30']);
        $this->payment(['date' => '2025-12-15', 'is_recurring' => true, 'frequency' => 'yearly', 'recurring_until' => '2026-10-01']);
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'from `transactions`')) {
                $queries++;
            }
        });
        $forecast = UpcomingPayments::forecast($this->user->id);
        $this->assertSame(1, $queries);
        $this->assertSame(['2027-03-30'], $forecast['payments']->map(fn (Transaction $transaction): string => $transaction->date->toDateString())->all());
        $this->assertSame('100.00', $forecast['total']);
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function horizons(): iterable
    {
        yield 'three months' => ['2026-10-02', 3, '2026-12-31'];

        yield 'six months' => ['2026-10-02', 6, '2027-03-31'];

        yield 'twelve months' => ['2026-10-02', 12, '2027-09-30'];

        yield 'December rollover' => ['2026-12-31', 3, '2027-02-28'];

        yield 'leap February' => ['2027-12-31', 3, '2028-02-29'];

        yield 'short month start' => ['2026-01-31', 6, '2026-06-30'];
    }

    #[DataProvider('horizons')]
    public function test_empty_forecast_has_all_months_and_exact_range(string $today, int $months, string $end): void
    {
        $this->travelTo(now()->parse($today));
        $forecast = UpcomingPayments::forecast($this->user->id, $months);
        $this->assertSame($today, $forecast['start']->toDateString());
        $this->assertSame($end, $forecast['end']->toDateString());
        $this->assertSame('23:59:59', $forecast['end']->format('H:i:s'));
        $this->assertCount($months, $forecast['months']);
        $this->assertTrue($forecast['payments']->isEmpty());
        $this->assertSame('0.00', $forecast['total']);
        $this->assertTrue($forecast['months']->every(fn (array $month): bool => $month['total'] === '0.00' && $month['payments']->isEmpty()));
    }
}
