<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Support\TransactionReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class TransactionReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_filters_transactions_by_category_and_expands_recurring_entries(): void
    {
        $user = User::factory()->create();
        $primaryCategory = Category::factory()->for($user)->expense()->create();
        $otherCategory = Category::factory()->for($user)->expense()->create();

        Transaction::factory()->for($user)->for($primaryCategory)->recurring('weekly')->create([
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => 10,
            'date' => '2024-05-01',
        ]);

        Transaction::factory()->for($user)->for($primaryCategory)->create([
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => 20,
            'date' => '2024-05-10',
        ]);

        Transaction::factory()->for($user)->for($otherCategory)->create([
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => 99,
            'date' => '2024-05-05',
        ]);

        $transactions = TransactionReport::projectedForMonth($user->id, 5, 2024, $primaryCategory->id);

        $this->assertCount(6, $transactions);
        $this->assertTrue($transactions->every(fn ($transaction): bool => $transaction->category_id === $primaryCategory->id));
        $this->assertEqualsWithDelta(70.0, $transactions->sum('amount'), PHP_FLOAT_EPSILON);
    }

    public function test_eager_loads_category_parent_for_reporting_classification(): void
    {
        $user = User::factory()->create();
        $parent = Category::factory()->for($user)->expense()->create();
        $subcategory = Category::factory()->subcategoryOf($parent)->create();
        Transaction::factory()->for($user)->for($subcategory)->create([
            'type' => Transaction::TYPE_EXPENSE,
            'date' => '2024-05-10',
        ]);

        $transaction = TransactionReport::projectedForMonth($user->id, 5, 2024)->sole();

        $this->assertTrue($transaction->relationLoaded('category'));
        $this->assertInstanceOf(Category::class, $transaction->category);
        $this->assertTrue($transaction->category->relationLoaded('parent'));
    }

    public function test_projects_a_range_with_one_transaction_query(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();

        Transaction::factory()->for($user)->for($category)->recurring('monthly')->create([
            'date' => '2024-01-31',
        ]);
        Transaction::factory()->for($user)->for($category)->create([
            'date' => '2024-02-10',
        ]);

        $transactionQueries = 0;
        DB::listen(function ($query) use (&$transactionQueries): void {
            if (preg_match('/from [`"]transactions[`"]/', $query->sql) === 1) {
                $transactionQueries++;
            }
        });

        $transactions = TransactionReport::projectedForRange(
            $user->id,
            Carbon::parse('2024-01-01'),
            Carbon::parse('2024-03-31'),
        );

        $this->assertCount(4, $transactions);
        $this->assertSame(1, $transactionQueries);
        $this->assertSame(
            ['2024-01-31', '2024-02-10', '2024-02-29', '2024-03-31'],
            $transactions->pluck('date')->sort()->map(fn (Carbon $date): string => $date->toDateString())->values()->all(),
        );
    }

    public function test_range_query_excludes_inactive_recurring_and_out_of_range_one_off_transactions(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();

        Transaction::factory()->for($user)->for($category)->recurring('monthly')->create([
            'date' => '2024-01-01',
            'recurring_until' => '2024-02-01',
        ]);
        Transaction::factory()->for($user)->for($category)->recurring('monthly')->create([
            'date' => '2024-05-01',
        ]);
        Transaction::factory()->for($user)->for($category)->create([
            'date' => '2024-01-15',
        ]);

        $this->assertTrue(TransactionReport::projectedForRange(
            $user->id,
            Carbon::parse('2024-03-01'),
            Carbon::parse('2024-03-31'),
        )->isEmpty());
    }

    public function test_range_query_only_hydrates_transactions_that_can_contribute_occurrences(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();

        Transaction::factory()->for($user)->for($category)->recurring('monthly')->create([
            'date' => '2024-01-01',
        ]);
        Transaction::factory()->for($user)->for($category)->recurring('monthly')->create([
            'date' => '2024-01-01',
            'recurring_until' => '2024-02-01',
        ]);
        Transaction::factory()->for($user)->for($category)->recurring('monthly')->create([
            'date' => '2024-04-01',
        ]);
        Transaction::factory()->for($user)->for($category)->create([
            'date' => '2024-03-15',
        ]);
        Transaction::factory()->for($user)->for($category)->create([
            'date' => '2024-01-15',
        ]);

        $retrievedTransactions = 0;
        $eventName = 'eloquent.retrieved: ' . Transaction::class;

        Event::listen($eventName, function () use (&$retrievedTransactions): void {
            $retrievedTransactions++;
        });

        try {
            $transactions = TransactionReport::projectedForRange(
                $user->id,
                Carbon::parse('2024-03-01'),
                Carbon::parse('2024-03-31'),
            );
        } finally {
            Event::forget($eventName);
        }

        $this->assertCount(2, $transactions);
        $this->assertSame(2, $retrievedTransactions);
    }
}
