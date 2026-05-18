<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionException;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_scopes_filter_transactions(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $food = Category::factory()->for($user)->create();
        $salary = Category::factory()->for($user)->create();

        $januaryExpense = Transaction::factory()->for($user)->for($food)->create([
            'type' => Transaction::TYPE_EXPENSE,
            'date' => '2024-01-15',
        ]);
        $februaryIncome = Transaction::factory()->for($user)->for($salary)->create([
            'type' => Transaction::TYPE_INCOME,
            'date' => '2024-02-01',
        ]);
        Transaction::factory()->for($otherUser)->for($food)->create([
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
        $transaction = Transaction::factory()->for(User::factory())->for(Category::factory())->create([
            'type' => Transaction::TYPE_EXPENSE,
            'date' => '2024-02-10',
        ]);

        $occurrences = $transaction->projectOccurrencesForMonth(2, 2024);

        $this->assertCount(1, $occurrences);
        $this->assertFalse($occurrences->first()->getAttribute('projected'));
        $this->assertSame('2024-02-10', $occurrences->first()->date->toDateString());
        $this->assertSame($transaction->category->id, $occurrences->first()->category->id);
    }

    public function test_non_recurring_transaction_outside_month_returns_empty(): void
    {
        $transaction = Transaction::factory()->for(User::factory())->for(Category::factory())->create([
            'type' => Transaction::TYPE_EXPENSE,
            'date' => '2024-01-15',
        ]);

        $occurrences = $transaction->projectOccurrencesForMonth(2, 2024);

        $this->assertTrue($occurrences->isEmpty());
    }

    public function test_recurring_transaction_with_historical_last_recurrence_returns_empty(): void
    {
        $transaction = Transaction::factory()
            ->for(User::factory())
            ->for(Category::factory())
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
            ->for(User::factory())
            ->for(Category::factory())
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
            ->for(User::factory())
            ->for(Category::factory())
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
        $this->assertTrue($februaryOccurrences->first()->getAttribute('projected'));
        $this->assertSame('2024-02-15', $februaryOccurrences->first()->date->toDateString());
    }

    public function test_recurring_without_frequency_returns_empty(): void
    {
        $transaction = Transaction::factory()
            ->for(User::factory())
            ->for(Category::factory())
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
            ->for(User::factory())
            ->for(Category::factory())
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
            ->for(User::factory())
            ->for(Category::factory())
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
            ->for(User::factory())
            ->for(Category::factory())
            ->recurring('monthly')
            ->create([
                'date' => Carbon::create(2024, 3, 1),
                'recurring_until' => Carbon::create(2024, 2, 1),
            ]);

        $occurrences = $transaction->projectOccurrencesForMonth(2, 2024);

        $this->assertTrue($occurrences->isEmpty());
    }

    public function test_recurrence_generation_considers_earliest_of_recurring_end_and_month_end(): void
    {
        $transaction = Transaction::factory()
            ->for(User::factory())
            ->for(Category::factory())
            ->recurring('weekly')
            ->create([
                'date' => Carbon::create(2024, 1, 5),
                'recurring_until' => Carbon::create(2024, 1, 15),
            ]);

        $occurrences = $transaction->projectOccurrencesForMonth(1, 2024);

        $this->assertCount(2, $occurrences);
        $this->assertSame('2024-01-12', $occurrences->last()->date->toDateString());
    }
}
