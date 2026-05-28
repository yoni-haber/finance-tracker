<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_belongs_to_a_user(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();

        $this->assertInstanceOf(User::class, $category->user);
        $this->assertTrue($category->user->is($user));
    }

    public function test_category_has_parent_and_children_relationships(): void
    {
        $user = User::factory()->create();
        $parent = Category::factory()->for($user)->expense()->create(['name' => 'Food']);
        $child = Category::factory()->subcategoryOf($parent)->create(['name' => 'Groceries']);

        $this->assertInstanceOf(Category::class, $child->parent);
        $this->assertTrue($child->parent->is($parent));
        $this->assertTrue($parent->children->contains($child));
    }

    public function test_parent_category_has_null_parent_id(): void
    {
        $category = Category::factory()->expense()->create();
        $this->assertNull($category->parent_id);
    }

    public function test_category_has_many_transactions(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();
        Transaction::factory()->count(3)->for($user)->for($category)->create(['type' => 'expense']);

        $this->assertCount(3, $category->transactions);
        $this->assertTrue($category->transactions->every(fn ($t): bool => $t->category !== null && $t->category->is($category)));
    }

    public function test_category_has_many_budgets(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();

        Budget::factory()
            ->count(2)
            ->state(new Sequence(
                ['month' => 1, 'year' => 2025],
                ['month' => 2, 'year' => 2025],
            ))
            ->for($user)
            ->for($category)
            ->create();

        $this->assertCount(2, $category->budgets);
    }

    public function test_scope_income_filters_by_income_type(): void
    {
        $user = User::factory()->create();
        $income = Category::factory()->for($user)->income()->create();
        $expense = Category::factory()->for($user)->expense()->create();

        $results = Category::forUser($user->id)->income()->get();

        $this->assertTrue($results->contains($income));
        $this->assertFalse($results->contains($expense));
    }

    public function test_scope_expense_filters_by_expense_type(): void
    {
        $user = User::factory()->create();
        $income = Category::factory()->for($user)->income()->create();
        $expense = Category::factory()->for($user)->expense()->create();

        $results = Category::forUser($user->id)->expense()->get();

        $this->assertTrue($results->contains($expense));
        $this->assertFalse($results->contains($income));
    }

    public function test_scope_parents_returns_only_top_level_categories(): void
    {
        $user = User::factory()->create();
        $parent = Category::factory()->for($user)->expense()->create(['name' => 'Food']);
        $child = Category::factory()->subcategoryOf($parent)->create(['name' => 'Groceries']);

        $results = Category::forUser($user->id)->parents()->get();

        $this->assertTrue($results->contains($parent));
        $this->assertFalse($results->contains($child));
    }

    public function test_scope_subcategories_returns_only_child_categories(): void
    {
        $user = User::factory()->create();
        $parent = Category::factory()->for($user)->expense()->create(['name' => 'Food']);
        $child = Category::factory()->subcategoryOf($parent)->create(['name' => 'Groceries']);

        $results = Category::forUser($user->id)->subcategories()->get();

        $this->assertTrue($results->contains($child));
        $this->assertFalse($results->contains($parent));
    }

    public function test_scope_for_user_filters_by_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ownCat = Category::factory()->for($user)->expense()->create();
        $otherCat = Category::factory()->for($otherUser)->expense()->create();

        $results = Category::forUser($user->id)->get();

        $this->assertTrue($results->contains($ownCat));
        $this->assertFalse($results->contains($otherCat));
    }

    public function test_is_parent_returns_true_when_parent_id_is_null(): void
    {
        $category = Category::factory()->expense()->create();
        $this->assertTrue($category->isParent());
    }

    public function test_is_subcategory_returns_true_when_parent_id_is_set(): void
    {
        $parent = Category::factory()->expense()->create();
        $sub = Category::factory()->subcategoryOf($parent)->create();
        $this->assertTrue($sub->isSubcategory());
    }

    public function test_has_transactions_returns_true_for_direct_transaction(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();
        Transaction::factory()->for($user)->for($category)->create(['type' => 'expense']);

        $this->assertTrue($category->hasTransactions());
    }

    public function test_has_transactions_returns_true_when_child_has_transactions(): void
    {
        $user = User::factory()->create();
        $parent = Category::factory()->for($user)->expense()->create(['name' => 'Food']);
        $child = Category::factory()->subcategoryOf($parent)->create(['name' => 'Groceries']);
        Transaction::factory()->for($user)->for($child)->create(['type' => 'expense']);

        // Parent has no direct transactions but its child does.
        $this->assertTrue($parent->hasTransactions());
    }

    public function test_has_transactions_returns_false_when_empty(): void
    {
        $category = Category::factory()->expense()->create();
        $this->assertFalse($category->hasTransactions());
    }

    public function test_has_budgets_returns_true_when_budget_exists(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();
        Budget::factory()->for($user)->for($category)->create();

        $this->assertTrue($category->hasBudgets());
    }

    public function test_has_budgets_returns_false_when_no_budgets(): void
    {
        $category = Category::factory()->expense()->create();
        $this->assertFalse($category->hasBudgets());
    }
}
