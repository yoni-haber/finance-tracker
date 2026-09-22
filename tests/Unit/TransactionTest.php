<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionException;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class TransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_scopes_filter_transactions(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $food = Category::factory()->for($user)->expense()->create();
        $salary = Category::factory()->for($user)->income()->create();
        $otherFood = Category::factory()->for($otherUser)->expense()->create();

        $januaryExpense = Transaction::factory()->for($user)->for($food)->create([
            'type' => Transaction::TYPE_EXPENSE,
            'date' => '2024-01-15',
        ]);
        $februaryIncome = Transaction::factory()->for($user)->for($salary)->create([
            'type' => Transaction::TYPE_INCOME,
            'date' => '2024-02-01',
        ]);
        Transaction::factory()->for($otherUser)->for($otherFood)->create([
            'type' => Transaction::TYPE_EXPENSE,
            'date' => '2024-01-05',
        ]);

        $this->assertSame(
            [$user->id],
            Transaction::forUser($user->id)->pluck('user_id')->unique()->all(),
        );

        $this->assertTrue(Transaction::income()->whereKey($februaryIncome->id)->exists());
        $this->assertTrue(Transaction::expense()->whereKey($januaryExpense->id)->exists());

        $this->assertCount(2, Transaction::forMonthYear(1, 2024)->get());

        $this->assertCount(
            1,
            Transaction::forUser($user->id)->forMonthYear(1, 2024)->forCategory($food->id)->get(),
        );

        $this->assertCount(
            1,
            Transaction::forUser($user->id)->forMonthYear(1, 2024)->forCategory(null)->get(),
        );
    }

    public function test_non_recurring_transaction_in_month_returns_clone(): void
    {
        $transaction = Transaction::factory()->create([
            'type' => Transaction::TYPE_EXPENSE,
            'date' => '2024-02-10',
        ]);

        $occurrences = $transaction->projectOccurrencesForMonth(2, 2024);

        $this->assertCount(1, $occurrences);
        $occurrence = $occurrences->first();
        $this->assertInstanceOf(Transaction::class, $occurrence);
        $this->assertFalse($occurrence->getAttribute('projected'));
        $this->assertSame('2024-02-10', $occurrence->date->toDateString());
        $this->assertInstanceOf(Category::class, $transaction->category);
        $this->assertInstanceOf(Category::class, $occurrence->category);
        $this->assertSame($transaction->category->id, $occurrence->category->id);
    }

    public function test_non_recurring_transaction_outside_month_returns_empty(): void
    {
        $transaction = Transaction::factory()->create([
            'type' => Transaction::TYPE_EXPENSE,
            'date' => '2024-01-15',
        ]);

        $occurrences = $transaction->projectOccurrencesForMonth(2, 2024);

        $this->assertTrue($occurrences->isEmpty());
    }

    public function test_projection_for_month_includes_a_transaction_on_the_first_day(): void
    {
        $transaction = Transaction::factory()->for(User::factory())->for(Category::factory())->create([
            'type' => Transaction::TYPE_EXPENSE,
            'date' => '2024-02-01',
        ]);

        $occurrences = $transaction->projectOccurrencesForMonth(2, 2024);

        $this->assertCount(1, $occurrences);
        $this->assertSame('2024-02-01', $occurrences->sole()->date->toDateString());
    }

    public function test_recurring_transaction_with_historical_last_recurrence_returns_empty(): void
    {
        $transaction = Transaction::factory()
            ->recurring('monthly')
            ->create([
                'date' => Carbon::create(2024, 1, 1),
                'recurring_until' => Carbon::create(2024, 2, 1),
            ]);

        $occurrences = $transaction->projectOccurrencesForMonth(3, 2024);

        $this->assertTrue($occurrences->isEmpty());
    }

    public function test_recurring_transaction_after_last_recurrence_returns_empty(): void
    {
        $transaction = Transaction::factory()
            ->recurring('monthly')
            ->create([
                'date' => Carbon::create(2024, 3, 15),
                'recurring_until' => Carbon::create(2024, 3, 5),
            ]);

        $occurrences = $transaction->projectOccurrencesForMonth(3, 2024);

        $this->assertTrue($occurrences->isEmpty());
    }

    public function test_recurring_transactions_skip_exceptions_and_use_frequency(): void
    {
        $transaction = Transaction::factory()
            ->recurring('monthly')
            ->create([
                'type' => Transaction::TYPE_INCOME,
                'amount' => 500,
                'date' => Carbon::create(2024, 1, 15),
                'recurring_until' => Carbon::create(2024, 4, 20),
            ]);

        TransactionException::create([
            'transaction_id' => $transaction->id,
            'date' => '2024-03-15',
        ]);

        $marchOccurrences = $transaction->projectOccurrencesForMonth(3, 2024);
        $februaryOccurrences = $transaction->projectOccurrencesForMonth(2, 2024);

        $this->assertTrue($marchOccurrences->isEmpty());
        $this->assertCount(1, $februaryOccurrences);
        $febOccurrence = $februaryOccurrences->first();
        $this->assertInstanceOf(Transaction::class, $febOccurrence);
        $this->assertTrue($febOccurrence->getAttribute('projected'));
        $this->assertSame('2024-02-15', $febOccurrence->date->toDateString());
    }

    public function test_recurring_without_frequency_returns_empty(): void
    {
        $transaction = Transaction::factory()
            ->create([
                'is_recurring' => true,
                'frequency' => null,
                'date' => Carbon::create(2024, 1, 1),
            ]);

        $occurrences = $transaction->projectOccurrencesForMonth(1, 2024);

        $this->assertTrue($occurrences->isEmpty());
    }

    public function test_recurring_with_invalid_frequency_stops_iteration_after_first_occurrence(): void
    {
        $transaction = Transaction::factory()
            ->recurring('weekly')
            ->create([
                'date' => Carbon::create(2024, 2, 5),
            ]);

        $transaction->setAttribute('frequency', 'fortnightly');

        $occurrences = $transaction->projectOccurrencesForMonth(2, 2024);

        $this->assertCount(0, $occurrences);
    }

    public function test_recurring_end_before_month_returns_empty(): void
    {
        $transaction = Transaction::factory()
            ->recurring('weekly')
            ->create([
                'date' => Carbon::create(2024, 1, 1),
                'recurring_until' => Carbon::create(2024, 1, 20),
            ]);

        $occurrences = $transaction->projectOccurrencesForMonth(2, 2024);

        $this->assertTrue($occurrences->isEmpty());
    }

    public function test_recurring_start_after_recurring_end_returns_empty(): void
    {
        $transaction = Transaction::factory()
            ->recurring('monthly')
            ->create([
                'date' => Carbon::create(2024, 3, 1),
                'recurring_until' => Carbon::create(2024, 2, 1),
            ]);

        $occurrences = $transaction->projectOccurrencesForMonth(2, 2024);

        $this->assertTrue($occurrences->isEmpty());
    }

    public function test_out_of_range_series_short_circuit_before_loading_exceptions(): void
    {
        $startsAfterRange = Transaction::factory()
            ->for(User::factory())
            ->for(Category::factory())
            ->recurring('weekly')
            ->create(['date' => '2024-02-01']);

        $endedBeforeRange = Transaction::factory()
            ->for(User::factory())
            ->for(Category::factory())
            ->recurring('weekly')
            ->create([
                'date' => '2024-01-01',
                'recurring_until' => '2024-01-31',
            ]);

        $invalidSeriesBounds = Transaction::factory()
            ->for(User::factory())
            ->for(Category::factory())
            ->recurring('weekly')
            ->create([
                'date' => '2024-02-01',
                'recurring_until' => '2024-01-31',
            ]);

        $cases = [
            [$startsAfterRange, '2024-01-01', '2024-01-31'],
            [$endedBeforeRange, '2024-02-01', '2024-02-29'],
            [$invalidSeriesBounds, '2024-01-15', '2024-02-29'],
        ];

        foreach ($cases as [$transaction, $rangeStart, $rangeEnd]) {
            $this->assertFalse($transaction->relationLoaded('occurrenceExceptions'));
            $this->assertTrue($transaction->projectOccurrencesForRange(
                Carbon::parse($rangeStart),
                Carbon::parse($rangeEnd),
            )->isEmpty());
            $this->assertFalse($transaction->relationLoaded('occurrenceExceptions'));
        }
    }

    public function test_recurrence_generation_considers_earliest_of_recurring_end_and_month_end(): void
    {
        $transaction = Transaction::factory()
            ->recurring('weekly')
            ->create([
                'date' => Carbon::create(2024, 1, 5),
                'recurring_until' => Carbon::create(2024, 1, 15),
            ]);

        $occurrences = $transaction->projectOccurrencesForMonth(1, 2024);

        $this->assertCount(2, $occurrences);
        $lastOccurrence = $occurrences->last();
        $this->assertInstanceOf(Transaction::class, $lastOccurrence);
        $this->assertSame('2024-01-12', $lastOccurrence->date->toDateString());
    }

    public function test_monthly_recurrence_preserves_the_original_month_end_anchor(): void
    {
        $transaction = Transaction::factory()
            ->for(User::factory())
            ->for(Category::factory())
            ->recurring('monthly')
            ->create(['date' => '2024-01-31']);

        $dates = $transaction
            ->projectOccurrencesForRange(Carbon::parse('2024-01-01'), Carbon::parse('2024-04-30'))
            ->pluck('date')
            ->map(fn (Carbon $date): string => $date->toDateString())
            ->all();

        $this->assertSame([
            '2024-01-31',
            '2024-02-29',
            '2024-03-31',
            '2024-04-30',
        ], $dates);
    }

    public function test_monthly_recurrence_restores_anchor_after_non_leap_february(): void
    {
        $transaction = Transaction::factory()
            ->for(User::factory())
            ->for(Category::factory())
            ->recurring('monthly')
            ->create(['date' => '2023-01-29']);

        $dates = $transaction
            ->projectOccurrencesForRange(Carbon::parse('2023-02-01'), Carbon::parse('2023-03-31'))
            ->pluck('date')
            ->map(fn (Carbon $date): string => $date->toDateString())
            ->all();

        $this->assertSame(['2023-02-28', '2023-03-29'], $dates);
    }

    public function test_yearly_leap_day_recurrence_preserves_its_anchor(): void
    {
        $transaction = Transaction::factory()
            ->for(User::factory())
            ->for(Category::factory())
            ->recurring('yearly')
            ->create(['date' => '2020-02-29']);

        $dates = $transaction
            ->projectOccurrencesForRange(Carbon::parse('2021-01-01'), Carbon::parse('2024-12-31'))
            ->pluck('date')
            ->map(fn (Carbon $date): string => $date->toDateString())
            ->all();

        $this->assertSame([
            '2021-02-28',
            '2022-02-28',
            '2023-02-28',
            '2024-02-29',
        ], $dates);
    }

    public function test_yearly_occurrences_are_normalised_to_midnight(): void
    {
        Carbon::setTestNow('2024-06-15 13:14:15');

        try {
            $transaction = Transaction::factory()
                ->for(User::factory())
                ->for(Category::factory())
                ->recurring('yearly')
                ->create(['date' => '2020-01-01']);

            $occurrence = $transaction
                ->projectOccurrencesForRange(Carbon::parse('2025-01-01'), Carbon::parse('2025-01-01'))
                ->sole();

            $this->assertSame('2025-01-01 00:00:00', $occurrence->date->format('Y-m-d H:i:s'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_projection_can_jump_to_a_distant_range_and_respects_end_and_exceptions(): void
    {
        $transaction = Transaction::factory()
            ->for(User::factory())
            ->for(Category::factory())
            ->recurring('monthly')
            ->create([
                'date' => '2000-01-31',
                'recurring_until' => '2099-03-31',
            ]);

        TransactionException::create([
            'transaction_id' => $transaction->id,
            'date' => '2099-02-28',
        ]);

        $dates = $transaction
            ->projectOccurrencesForRange(Carbon::parse('2099-01-01'), Carbon::parse('2099-04-30'))
            ->pluck('date')
            ->map(fn (Carbon $date): string => $date->toDateString())
            ->all();

        $this->assertSame(['2099-01-31', '2099-03-31'], $dates);
    }

    public function test_projection_rejects_an_inverted_range(): void
    {
        $transaction = Transaction::factory()->for(User::factory())->for(Category::factory())->create();

        $this->expectException(InvalidArgumentException::class);

        $transaction->projectOccurrencesForRange(Carbon::parse('2024-02-01'), Carbon::parse('2024-01-31'));
    }
}
