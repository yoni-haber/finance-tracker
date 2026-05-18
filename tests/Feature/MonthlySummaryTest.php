<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Support\TransactionReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MonthlySummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_calculates_income_and_expense_for_month(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();

        Transaction::factory()->for($user)->for($category)->create([
            'type' => Transaction::TYPE_INCOME,
            'amount' => 500,
            'date' => '2024-02-05',
        ]);

        Transaction::factory()->for($user)->for($category)->create([
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => 200,
            'date' => '2024-02-10',
        ]);

        $transactions = TransactionReport::projectedForMonth($user->id, 2, 2024);

        $this->assertEqualsWithDelta(500.0, $transactions->where('type', Transaction::TYPE_INCOME)->sum('amount'), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(200.0, $transactions->where('type', Transaction::TYPE_EXPENSE)->sum('amount'), PHP_FLOAT_EPSILON);
    }
}
