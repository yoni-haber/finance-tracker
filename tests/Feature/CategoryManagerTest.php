<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Categories\CategoryManager;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class CategoryManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_render_shows_only_authenticated_users_categories(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ownIncome = Category::factory()->for($user)->income()->create(['name' => 'Salary']);
        Category::factory()->for($otherUser)->income()->create(['name' => 'Other']);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->assertViewHas('incomeParents', fn ($parents): bool => $parents->count() === 1 && $parents->first()->id === $ownIncome->id);
    }

    public function test_render_income_and_expense_sections_are_separate(): void
    {
        $user = User::factory()->create();

        $incomeParent = Category::factory()->for($user)->income()->create(['name' => 'Employment']);
        $expenseParent = Category::factory()->for($user)->expense()->create(['name' => 'Housing']);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->assertViewHas('incomeParents', fn ($p) => $p->contains('id', $incomeParent->id))
            ->assertViewHas('expenseParents', fn ($p) => $p->contains('id', $expenseParent->id))
            ->assertViewHas('incomeParents', fn ($p) => $p->doesntContain('id', $expenseParent->id))
            ->assertViewHas('expenseParents', fn ($p) => $p->doesntContain('id', $incomeParent->id));
    }

    public function test_render_parent_options_filtered_by_selected_type(): void
    {
        $user = User::factory()->create();

        $incomeParent = Category::factory()->for($user)->income()->create(['name' => 'Employment']);
        $expenseParent = Category::factory()->for($user)->expense()->create(['name' => 'Housing']);

        // Default type is expense — only expense parents should appear in parentOptions.
        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->assertViewHas('parentOptions', fn ($p) => $p->contains('id', $expenseParent->id))
            ->assertViewHas('parentOptions', fn ($p) => $p->doesntContain('id', $incomeParent->id));
    }

    public function test_save_creates_income_parent_category(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('type', 'income')
            ->set('name', 'Employment')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('categories', [
            'user_id' => $user->id,
            'name' => 'Employment',
            'type' => 'income',
            'parent_id' => null,
        ]);
    }

    public function test_save_creates_expense_parent_category(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('type', 'expense')
            ->set('name', 'Housing')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('categories', [
            'user_id' => $user->id,
            'name' => 'Housing',
            'type' => 'expense',
            'parent_id' => null,
        ]);
    }

    public function test_save_creates_subcategory_under_matching_parent(): void
    {
        $user = User::factory()->create();
        $parent = Category::factory()->for($user)->expense()->create(['name' => 'Food']);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('type', 'expense')
            ->set('parentId', $parent->id)
            ->set('name', 'Groceries')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('categories', [
            'user_id' => $user->id,
            'name' => 'Groceries',
            'type' => 'expense',
            'parent_id' => $parent->id,
        ]);
    }

    public function test_save_rejects_subcategory_under_wrong_type_parent(): void
    {
        $user = User::factory()->create();
        $incomeParent = Category::factory()->for($user)->income()->create(['name' => 'Employment']);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('type', 'expense')      // expense, but parent is income
            ->set('parentId', $incomeParent->id)
            ->set('name', 'Groceries')
            ->call('save')
            ->assertHasErrors('parentId');
    }

    public function test_save_rejects_subcategory_of_a_subcategory(): void
    {
        $user = User::factory()->create();
        $parent = Category::factory()->for($user)->expense()->create(['name' => 'Food']);
        $sub = Category::factory()->subcategoryOf($parent)->create(['name' => 'Groceries']);

        // Attempt to set $sub as the parent (third level — not allowed).
        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('type', 'expense')
            ->set('parentId', $sub->id)
            ->set('name', 'Organic')
            ->call('save')
            ->assertHasErrors('parentId');
    }

    public function test_save_rejects_duplicate_parent_name_for_same_type(): void
    {
        $user = User::factory()->create();
        Category::factory()->for($user)->expense()->create(['name' => 'Housing']);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('type', 'expense')
            ->set('name', 'Housing')
            ->call('save')
            ->assertHasErrors('name');
    }

    public function test_save_allows_same_name_for_different_types(): void
    {
        $user = User::factory()->create();
        Category::factory()->for($user)->income()->create(['name' => 'Consulting']);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('type', 'expense')
            ->set('name', 'Consulting')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_save_rejects_duplicate_subcategory_name_under_same_parent(): void
    {
        $user = User::factory()->create();
        $parent = Category::factory()->for($user)->expense()->create(['name' => 'Food']);
        Category::factory()->subcategoryOf($parent)->create(['name' => 'Groceries']);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('type', 'expense')
            ->set('parentId', $parent->id)
            ->set('name', 'Groceries')
            ->call('save')
            ->assertHasErrors('name');
    }

    public function test_edit_loads_all_fields_including_type_and_parent(): void
    {
        $user = User::factory()->create();
        $parent = Category::factory()->for($user)->expense()->create(['name' => 'Food']);
        $sub = Category::factory()->subcategoryOf($parent)->create(['name' => 'Groceries']);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->call('edit', $sub->id)
            ->assertSet('categoryId', $sub->id)
            ->assertSet('name', 'Groceries')
            ->assertSet('type', 'expense')
            ->assertSet('parentId', $parent->id);
    }

    public function test_edit_throws_404_for_another_users_category(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherCategory = Category::factory()->for($otherUser)->expense()->create();

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->call('edit', $otherCategory->id);
    }

    public function test_save_updates_existing_category(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Old Name']);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->call('edit', $category->id)
            ->set('name', 'New Name')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Category saved.');

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'New Name']);
    }

    public function test_delete_succeeds_when_category_has_no_transactions_or_budgets(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->call('confirmDelete', $category->id)
            ->call('delete')
            ->assertSee('Category removed.');

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_delete_blocked_when_category_has_transactions(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();
        Transaction::factory()->for($user)->for($category)->create(['category_id' => $category->id]);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->call('confirmDelete', $category->id)
            ->assertSee('Cannot delete');

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_delete_blocked_when_parent_child_has_transactions(): void
    {
        $user = User::factory()->create();
        $parent = Category::factory()->for($user)->expense()->create(['name' => 'Food']);
        $sub = Category::factory()->subcategoryOf($parent)->create(['name' => 'Groceries']);
        Transaction::factory()->for($user)->for($sub)->create(['type' => 'expense']);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->call('confirmDelete', $parent->id)
            ->assertSee('Cannot delete');

        $this->assertDatabaseHas('categories', ['id' => $parent->id]);
        $this->assertDatabaseHas('categories', ['id' => $sub->id]);
    }

    public function test_delete_blocked_when_category_has_budgets(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();
        Budget::factory()->for($user)->for($category)->create();

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->call('confirmDelete', $category->id)
            ->assertSee('Cannot delete');

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_delete_parent_also_deletes_empty_children(): void
    {
        $user = User::factory()->create();
        $parent = Category::factory()->for($user)->expense()->create(['name' => 'Food']);
        $sub = Category::factory()->subcategoryOf($parent)->create(['name' => 'Groceries']);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->call('confirmDelete', $parent->id)
            ->call('delete')
            ->assertSee('Category removed.');

        $this->assertDatabaseMissing('categories', ['id' => $parent->id]);
        $this->assertDatabaseMissing('categories', ['id' => $sub->id]);
    }

    public function test_delete_silently_ignores_another_users_category(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherCategory = Category::factory()->for($otherUser)->expense()->create();

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->call('confirmDelete', $otherCategory->id);

        $this->assertDatabaseHas('categories', ['id' => $otherCategory->id]);
    }

    public function test_updated_type_clears_parent_id(): void
    {
        $user = User::factory()->create();
        $parent = Category::factory()->for($user)->expense()->create(['name' => 'Food']);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('type', 'expense')
            ->set('parentId', $parent->id)
            ->set('type', 'income')
            ->assertSet('parentId', null);
    }

    public function test_reset_form_clears_all_fields_to_defaults(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->income()->create(['name' => 'Salary']);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->call('edit', $category->id)
            ->assertSet('name', 'Salary')
            ->call('resetForm')
            ->assertSet('categoryId', null)
            ->assertSet('name', '')
            ->assertSet('type', Category::TYPE_EXPENSE)
            ->assertSet('parentId', null);
    }

    public function test_open_modal_dispatches_event_and_resets_form(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->income()->create(['name' => 'Salary']);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->call('edit', $category->id)
            ->assertSet('categoryId', $category->id)
            ->call('openModal')
            ->assertDispatched('open-category-modal')
            ->assertSet('categoryId', null)
            ->assertSet('name', '');
    }

    public function test_edit_dispatches_open_category_modal_event(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Housing']);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->call('edit', $category->id)
            ->assertDispatched('open-category-modal');
    }

    public function test_save_dispatches_close_category_modal_on_success(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('type', 'expense')
            ->set('name', 'Housing')
            ->call('save')
            ->assertDispatched('close-category-modal');
    }

    public function test_save_does_not_dispatch_close_category_modal_on_validation_failure(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('name', '')
            ->call('save')
            ->assertNotDispatched('close-category-modal');
    }

    public function test_save_flashes_status_after_creating_category(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('type', 'income')
            ->set('name', 'Employment')
            ->call('save')
            ->assertSee('Category saved.');
    }

    public function test_save_fails_validation_when_name_is_empty(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name']);
    }

    public function test_save_fails_validation_when_name_exceeds_255_characters(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('name', str_repeat('a', 256))
            ->call('save')
            ->assertHasErrors(['name']);
    }

    public function test_save_cannot_update_another_users_category(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherCategory = Category::factory()->for($otherUser)->expense()->create(['name' => 'Original']);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('categoryId', $otherCategory->id)
            ->set('type', 'expense')
            ->set('name', 'Hijacked')
            ->call('save')
            ->assertHasErrors('save');

        $this->assertDatabaseHas('categories', ['id' => $otherCategory->id, 'name' => 'Original']);
    }

    public function test_delete_returns_early_when_deleting_id_is_null(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->assertSet('deletingId', null)
            ->call('delete')
            ->assertNotDispatched('close-delete-category-modal');
    }

    public function test_delete_returns_when_no_category_found_with_deleting_id(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('deletingId', 999) // no related children categories
            ->call('delete')
            ->assertDispatched('close-delete-category-modal')
            ->assertDontSee('Category removed.');
    }

    public function test_delete_returns_when_category_has_transactions(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();
        $transaction = Transaction::factory()->for($user)->for($category)->create();

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('deletingId', $category->id)
            ->call('delete')
            ->assertDispatched('close-delete-category-modal')
            ->assertSee('Cannot delete — category has transactions. Rename it instead.')
            ->assertDontSee('Category removed.');

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
    }

    public function test_delete_returns_when_category_has_budgets(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();
        $budget = Budget::factory()->for($user)->for($category)->create();

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('deletingId', $category->id)
            ->call('delete')
            ->assertDispatched('close-delete-category-modal')
            ->assertSee('Cannot delete — category has budgets. Remove the budgets first.')
            ->assertDontSee('Category removed.');

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('budgets', ['id' => $budget->id]);
    }
}
