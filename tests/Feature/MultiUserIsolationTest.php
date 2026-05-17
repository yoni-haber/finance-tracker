<?php

namespace Tests\Feature;

use App\Livewire\Budgets\BudgetManager;
use App\Livewire\Transactions\TransactionManager;
use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MultiUserIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_transactions_cannot_reference_other_users_categories(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherCategory = Category::factory()->for($otherUser)->expense()->create();

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('amount', '10.00')
            ->set('date', '2024-01-01')
            ->set('category_id', $otherCategory->id)
            ->call('save')
            ->assertHasErrors(['category_id' => 'exists']);
    }

    public function test_budget_updates_are_scoped_to_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $userCategory = Category::factory()->for($user)->expense()->create();

        $otherUser = User::factory()->create();
        $otherCategory = Category::factory()->for($otherUser)->expense()->create();
        $otherBudget = Budget::factory()->for($otherUser)->for($otherCategory)->create([
            'month' => 1,
            'year' => 2024,
            'amount' => 50,
        ]);

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('budgetId', $otherBudget->id)
            ->set('category_id', $userCategory->id)
            ->set('month', 1)
            ->set('year', 2024)
            ->set('amount', '75')
            ->call('save')
            ->assertHasErrors('save');

        $this->assertSame(50.00, (float) $otherBudget->fresh()->amount);
        $this->assertDatabaseMissing('budgets', [
            'id' => $otherBudget->id,
            'user_id' => $user->id,
        ]);
    }
}
