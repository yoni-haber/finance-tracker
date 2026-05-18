<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use App\Support\TransactionReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BudgetComparisonTest extends TestCase
{
    use RefreshDatabase;

    public function test_budget_remaining_is_calculated(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();

        Budget::factory()->for($user)->for($category)->create([
            'month' => 4,
            'year' => 2024,
            'amount' => 200,
        ]);

        Transaction::factory()->for($user)->for($category)->create([
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => 50,
            'date' => '2024-04-05',
        ]);

        Transaction::factory()->for($user)->for($category)->create([
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => 25,
            'date' => '2024-04-10',
        ]);

        $transactions = TransactionReport::projectedForMonth($user->id, 4, 2024);
        $actual = $transactions->where('type', Transaction::TYPE_EXPENSE)->sum('amount');

        $remaining = Money::subtract(200, $actual);

        $this->assertSame('125.00', $remaining);
    }
}
