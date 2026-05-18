<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Support\TransactionReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TransactionReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_filters_transactions_by_category_and_expands_recurring_entries(): void
    {
        $user = User::factory()->create();
        $primaryCategory = Category::factory()->for($user)->create();
        $otherCategory = Category::factory()->for($user)->create();

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
}
