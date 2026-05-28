<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_casts_attributes_correctly(): void
    {
        $budget = Budget::factory()->create([
            'amount' => 150.5,
            'month' => 3,
            'year' => 2024,
        ])->fresh();

        $this->assertNotNull($budget);
        $this->assertSame('150.50', $budget->amount);
        $this->assertSame(3, $budget->month);
        $this->assertSame(2024, $budget->year);
    }

    public function test_it_belongs_to_a_user_and_category(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();

        $budget = Budget::factory()
            ->for($user)
            ->for($category)
            ->create();

        $this->assertTrue($budget->user->is($user));
        $this->assertTrue($budget->category->is($category));
    }

    public function test_it_retrieves_transactions_for_budget_month_and_category(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();
        $otherCategory = Category::factory()->for($user)->create();

        $budget = Budget::factory()
            ->for($user)
            ->for($category)
            ->create([
                'month' => 3,
                'year' => 2025,
            ]);

        // matches the conditions for $budget
        $categoryTransaction = Transaction::factory()
            ->for($user)
            ->for($category)
            ->create([
                'date' => '2025-03-10',
            ]);

        // different category
        Transaction::factory()
            ->for($user)
            ->for($otherCategory)
            ->create([
                'date' => '2025-03-12',
            ]);

        // different month
        Transaction::factory()
            ->for($user)
            ->for($category)
            ->create([
                'date' => '2025-04-01',
            ]);

        // different user
        Transaction::factory()
            ->for(User::factory())
            ->for($category)
            ->create([
                'date' => '2025-03-15',
            ]);

        // matches the conditions for $budget
        $anotherCategoryTransaction = Transaction::factory()
            ->for($user)
            ->for($category)
            ->create([
                'date' => '2025-03-25',
            ]);

        $transactions = $budget->transactions;

        $this->assertCount(2, $transactions);
        $firstTx = $transactions->first();
        $lastTx = $transactions->last();
        $this->assertInstanceOf(Transaction::class, $firstTx);
        $this->assertInstanceOf(Transaction::class, $lastTx);
        $this->assertTrue($firstTx->is($categoryTransaction));
        $this->assertTrue($lastTx->is($anotherCategoryTransaction));
    }
}
