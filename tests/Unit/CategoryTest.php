<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use DomainException;
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

    public function test_parent_expense_category_uses_its_reporting_treatment(): void
    {
        $category = Category::factory()->expense()->create([
            'expense_treatment' => Category::TREATMENT_INVESTMENT,
        ]);

        $this->assertSame(Category::TREATMENT_INVESTMENT, $category->effectiveExpenseTreatment());
        $this->assertSame('Investment', $category->expenseTreatmentLabel());
    }

    public function test_expense_treatment_labels_cover_spending_and_saving(): void
    {
        $spending = Category::factory()->expense()->create([
            'expense_treatment' => Category::TREATMENT_SPENDING,
        ]);
        $saving = Category::factory()->expense()->create([
            'expense_treatment' => Category::TREATMENT_SAVING,
        ]);

        $this->assertSame('Spending', $spending->expenseTreatmentLabel());
        $this->assertSame('Saving', $saving->expenseTreatmentLabel());
    }

    public function test_subcategory_inherits_its_parent_reporting_treatment(): void
    {
        $parent = Category::factory()->expense()->create([
            'expense_treatment' => Category::TREATMENT_SAVING,
        ]);
        $subcategory = Category::factory()->subcategoryOf($parent)->create();

        $this->assertNull($subcategory->expense_treatment);
        $this->assertSame(Category::TREATMENT_SAVING, $subcategory->effectiveExpenseTreatment());
    }

    public function test_income_category_has_no_expense_treatment(): void
    {
        $category = Category::factory()->income()->create();

        $this->assertNull($category->effectiveExpenseTreatment());
        $this->assertNull($category->expenseTreatmentLabel());
    }

    public function test_existing_empty_category_can_be_moved_beneath_a_valid_parent(): void
    {
        $user = User::factory()->create();
        $parent = Category::factory()->for($user)->expense()->create();
        $category = Category::factory()->for($user)->expense()->create();

        $category->update(['parent_id' => $parent->id]);

        $this->assertSame($parent->id, $category->fresh()?->parent_id);
    }

    public function test_model_rejects_self_parenting_with_the_expected_error(): void
    {
        $category = Category::factory()->expense()->create();

        try {
            $category->update(['parent_id' => $category->id]);
            $this->fail('Expected self-parenting to be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('A category cannot be its own parent.', $exception->getMessage());
        }

        $this->assertNull($category->fresh()?->parent_id);
    }

    public function test_model_rejects_missing_cross_user_cross_type_and_nested_parents(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $expenseParent = Category::factory()->for($owner)->expense()->create();
        $incomeParent = Category::factory()->for($owner)->income()->create();
        $child = Category::factory()->subcategoryOf($expenseParent)->create();
        $expected = 'Subcategories require a top-level parent owned by the same user and with the same type.';

        foreach ([
            fn (): Category => Category::factory()->for($owner)->expense()->create(['parent_id' => 999999]),
            fn (): Category => Category::factory()->for($other)->expense()->create(['parent_id' => $expenseParent->id]),
            fn (): Category => Category::factory()->for($owner)->expense()->create(['parent_id' => $incomeParent->id]),
            fn (): Category => Category::factory()->for($owner)->expense()->create(['parent_id' => $child->id]),
        ] as $saveInvalidCategory) {
            try {
                $saveInvalidCategory();
                $this->fail('Expected an invalid parent relationship to be rejected.');
            } catch (DomainException $exception) {
                $this->assertSame($expected, $exception->getMessage());
            }
        }
    }

    public function test_structural_dependencies_distinguish_children_transactions_budgets_and_empty_categories(): void
    {
        $user = User::factory()->create();
        $withChild = Category::factory()->for($user)->expense()->create();
        Category::factory()->subcategoryOf($withChild)->create();

        $withTransaction = Category::factory()->for($user)->expense()->create();
        Transaction::factory()->for($user)->for($withTransaction)->create([
            'type' => Transaction::TYPE_EXPENSE,
        ]);

        $withBudget = Category::factory()->for($user)->expense()->create();
        Budget::factory()->for($user)->for($withBudget)->create();

        $empty = Category::factory()->for($user)->expense()->create();

        $this->assertTrue($withChild->hasStructuralDependencies());
        $this->assertTrue($withTransaction->hasStructuralDependencies());
        $this->assertTrue($withBudget->hasStructuralDependencies());
        $this->assertFalse($empty->hasStructuralDependencies());
    }

    public function test_each_dependency_blocks_a_type_or_parent_change_but_not_a_rename(): void
    {
        $user = User::factory()->create();
        $newParent = Category::factory()->for($user)->expense()->create();
        $categories = [];

        $withChild = Category::factory()->for($user)->expense()->create(['name' => 'With child']);
        Category::factory()->subcategoryOf($withChild)->create();
        $categories[] = [$withChild, ['type' => Category::TYPE_INCOME]];

        $withTransaction = Category::factory()->for($user)->expense()->create(['name' => 'With transaction']);
        Transaction::factory()->for($user)->for($withTransaction)->create([
            'type' => Transaction::TYPE_EXPENSE,
        ]);
        $categories[] = [$withTransaction, ['parent_id' => $newParent->id]];

        $withBudget = Category::factory()->for($user)->expense()->create(['name' => 'With budget']);
        Budget::factory()->for($user)->for($withBudget)->create();
        $categories[] = [$withBudget, ['type' => Category::TYPE_INCOME]];

        foreach ($categories as [$category, $structuralChange]) {
            $originalName = $category->name;
            $category->update(['name' => $originalName . ' renamed']);
            $this->assertSame($originalName . ' renamed', $category->fresh()?->name);

            try {
                $category->update($structuralChange);
                $this->fail('Expected a structural edit to be rejected.');
            } catch (DomainException $exception) {
                $this->assertSame(
                    'A category with subcategories, transactions, or budgets cannot change type or parent.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_model_clears_treatment_for_income_and_child_categories_but_preserves_expense_parent_treatment(): void
    {
        $user = User::factory()->create();
        $expenseParent = Category::factory()->for($user)->expense()->create([
            'expense_treatment' => Category::TREATMENT_INVESTMENT,
        ]);
        $income = Category::factory()->for($user)->income()->create([
            'expense_treatment' => Category::TREATMENT_INVESTMENT,
        ]);
        $child = Category::factory()->subcategoryOf($expenseParent)->create([
            'expense_treatment' => Category::TREATMENT_SAVING,
        ]);

        $this->assertSame(Category::TREATMENT_INVESTMENT, $expenseParent->expense_treatment);
        $this->assertNull($income->expense_treatment);
        $this->assertNull($child->expense_treatment);
    }
}
