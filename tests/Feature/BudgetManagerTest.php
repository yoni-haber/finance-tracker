<?php

namespace Tests\Feature;

use App\Livewire\Budgets\BudgetManager;
use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BudgetManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_mount_sets_month_and_year_to_current_date(): void
    {
        $user = User::factory()->create();
        $now = now();

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->assertSet('month', (int) $now->month)
            ->assertSet('year', (int) $now->year)
            ->assertSet('filterMonth', (int) $now->month)
            ->assertSet('filterYear', (int) $now->year);
    }

    public function test_save_creates_budget_with_valid_data(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('category_id', $category->id)
            ->set('month', 6)
            ->set('year', 2025)
            ->set('amount', '500.00')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Budget saved.');

        $this->assertDatabaseHas('budgets', [
            'user_id' => $user->id,
            'category_id' => $category->id,
            'month' => 6,
            'year' => 2025,
            'amount' => '500.00',
        ]);
    }

    public function test_save_create_detects_duplicate_budget(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();

        Budget::factory()->for($user)->for($category, 'category')->create([
            'month' => 3,
            'year' => 2025,
        ]);

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('category_id', $category->id)
            ->set('month', 3)
            ->set('year', 2025)
            ->set('amount', '100.00')
            ->call('save')
            ->assertHasErrors('save');
    }

    public function test_save_create_rejects_category_belonging_to_other_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherCategory = Category::factory()->for($otherUser)->create();

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('category_id', $otherCategory->id)
            ->set('month', 5)
            ->set('year', 2025)
            ->set('amount', '200.00')
            ->call('save')
            ->assertHasErrors('category_id');
    }

    public function test_save_create_validates_missing_category_id(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('category_id', null)
            ->set('month', 5)
            ->set('year', 2025)
            ->set('amount', '100.00')
            ->call('save')
            ->assertHasErrors('category_id');
    }

    public function test_save_create_validates_month_out_of_range(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('category_id', $category->id)
            ->set('month', 0)
            ->set('year', 2025)
            ->set('amount', '100.00')
            ->call('save')
            ->assertHasErrors('month');

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('category_id', $category->id)
            ->set('month', 13)
            ->set('year', 2025)
            ->set('amount', '100.00')
            ->call('save')
            ->assertHasErrors('month');
    }

    public function test_save_create_validates_year_out_of_range(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('category_id', $category->id)
            ->set('month', 5)
            ->set('year', 1999)
            ->set('amount', '100.00')
            ->call('save')
            ->assertHasErrors('year');

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('category_id', $category->id)
            ->set('month', 5)
            ->set('year', 2101)
            ->set('amount', '100.00')
            ->call('save')
            ->assertHasErrors('year');
    }

    public function test_save_updates_existing_budget(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();
        $budget = Budget::factory()->for($user)->for($category, 'category')->create([
            'month' => 4,
            'year' => 2025,
            'amount' => '300.00',
        ]);

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->call('edit', $budget->id)
            ->set('amount', '450.00')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Budget saved.');

        $this->assertDatabaseHas('budgets', [
            'id' => $budget->id,
            'amount' => '450.00',
        ]);
    }

    public function test_save_update_duplicate_check_excludes_self(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();
        $budget = Budget::factory()->for($user)->for($category, 'category')->create([
            'month' => 7,
            'year' => 2025,
            'amount' => '200.00',
        ]);

        // Re-saving the same budget should not trigger the duplicate error
        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->call('edit', $budget->id)
            ->set('amount', '250.00')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Budget saved.');
    }

    public function test_save_update_returns_error_when_budget_not_found(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('budgetId', 99999)
            ->set('category_id', $category->id)
            ->set('month', 5)
            ->set('year', 2025)
            ->set('amount', '100.00')
            ->call('save')
            ->assertHasErrors('save');
    }

    public function test_save_update_returns_error_for_another_users_budget(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $category = Category::factory()->for($user)->create();
        $otherCategory = Category::factory()->for($otherUser)->create();
        $otherBudget = Budget::factory()->for($otherUser)->for($otherCategory, 'category')->create([
            'month' => 5,
            'year' => 2025,
        ]);

        // budgetId points to a budget that exists in DB but belongs to another user
        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('budgetId', $otherBudget->id)
            ->set('category_id', $category->id)
            ->set('month', 5)
            ->set('year', 2025)
            ->set('amount', '100.00')
            ->call('save')
            ->assertHasErrors('save');

        // Other user's budget must remain unchanged
        $this->assertDatabaseHas('budgets', ['id' => $otherBudget->id]);
    }

    public function test_edit_loads_all_fields_correctly(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();
        $budget = Budget::factory()->for($user)->for($category, 'category')->create([
            'month' => 8,
            'year' => 2024,
            'amount' => '750.50',
        ]);

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->call('edit', $budget->id)
            ->assertSet('budgetId', $budget->id)
            ->assertSet('category_id', $category->id)
            ->assertSet('month', 8)
            ->assertSet('year', 2024)
            ->assertSet('amount', '750.50');
    }

    public function test_edit_throws_404_for_another_users_budget(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherBudget = Budget::factory()
            ->for($otherUser)
            ->for(Category::factory()->for($otherUser), 'category')
            ->create();

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->call('edit', $otherBudget->id);
    }

    public function test_delete_removes_own_budget_and_flashes_message(): void
    {
        $user = User::factory()->create();
        $budget = Budget::factory()
            ->for($user)
            ->for(Category::factory()->for($user), 'category')
            ->create();

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->call('delete', $budget->id)
            ->assertSee('Budget removed.');

        $this->assertDatabaseMissing('budgets', ['id' => $budget->id]);
    }

    public function test_delete_silently_ignores_another_users_budget(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherBudget = Budget::factory()
            ->for($otherUser)
            ->for(Category::factory()->for($otherUser), 'category')
            ->create();

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->call('delete', $otherBudget->id);

        $this->assertDatabaseHas('budgets', ['id' => $otherBudget->id]);
    }

    public function test_render_filters_by_category(): void
    {
        $user = User::factory()->create();
        $catA = Category::factory()->for($user)->create(['name' => 'Groceries']);
        $catB = Category::factory()->for($user)->create(['name' => 'Rent']);

        Budget::factory()->for($user)->for($catA, 'category')->create(['month' => 1, 'year' => 2025]);
        Budget::factory()->for($user)->for($catB, 'category')->create(['month' => 1, 'year' => 2025]);

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('filterMonth', 1)
            ->set('filterYear', 2025)
            ->set('filterCategory', $catA->id)
            ->assertViewHas('budgets', function ($budgets) use ($catA, $catB) {
                return $budgets->count() === 1
                    && $budgets->first()->category_id === $catA->id
                    && $budgets->doesntContain('category_id', $catB->id);
            });
    }

    public function test_render_filters_by_month_and_year(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create(['name' => 'Travel']);

        $budgetFeb = Budget::factory()->for($user)->for($category, 'category')->create([
            'month' => 2,
            'year' => 2025,
            'amount' => '100.00',
        ]);
        $budgetMar = Budget::factory()->for($user)->for($category, 'category')->create([
            'month' => 3,
            'year' => 2025,
            'amount' => '200.00',
        ]);

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('filterMonth', 2)
            ->set('filterYear', 2025)
            ->set('filterCategory', null)
            ->assertViewHas('budgets', function ($budgets) use ($budgetFeb, $budgetMar) {
                return $budgets->count() === 1
                    && $budgets->first()->id === $budgetFeb->id
                    && $budgets->doesntContain('id', $budgetMar->id);
            });
    }

    public function test_copy_from_previous_month_creates_missing_budgets(): void
    {
        $user = User::factory()->create();
        $catA = Category::factory()->for($user)->create();
        $catB = Category::factory()->for($user)->create();

        Budget::factory()->for($user)->for($catA, 'category')->create(['month' => 3, 'year' => 2025, 'amount' => '200.00']);
        Budget::factory()->for($user)->for($catB, 'category')->create(['month' => 3, 'year' => 2025, 'amount' => '400.00']);

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('filterMonth', 4)
            ->set('filterYear', 2025)
            ->call('copyFromPreviousMonth')
            ->assertSee('Copied 2 budget(s) from March 2025 to April 2025.');

        $this->assertDatabaseHas('budgets', ['user_id' => $user->id, 'category_id' => $catA->id, 'month' => 4, 'year' => 2025, 'amount' => '200.00']);
        $this->assertDatabaseHas('budgets', ['user_id' => $user->id, 'category_id' => $catB->id, 'month' => 4, 'year' => 2025, 'amount' => '400.00']);
    }

    public function test_copy_from_previous_month_skips_existing_budgets(): void
    {
        $user = User::factory()->create();
        $catA = Category::factory()->for($user)->create();
        $catB = Category::factory()->for($user)->create();

        // Previous month
        Budget::factory()->for($user)->for($catA, 'category')->create(['month' => 3, 'year' => 2025, 'amount' => '200.00']);
        Budget::factory()->for($user)->for($catB, 'category')->create(['month' => 3, 'year' => 2025, 'amount' => '400.00']);

        // catA already has a budget in the target month
        Budget::factory()->for($user)->for($catA, 'category')->create(['month' => 4, 'year' => 2025, 'amount' => '999.00']);

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('filterMonth', 4)
            ->set('filterYear', 2025)
            ->call('copyFromPreviousMonth')
            ->assertSee('Copied 1 budget(s) from March 2025 to April 2025.')
            ->assertSee('1 skipped (already existed).');

        // Existing budget must remain untouched
        $this->assertDatabaseHas('budgets', ['user_id' => $user->id, 'category_id' => $catA->id, 'month' => 4, 'year' => 2025, 'amount' => '999.00']);
        // catB must have been copied
        $this->assertDatabaseHas('budgets', ['user_id' => $user->id, 'category_id' => $catB->id, 'month' => 4, 'year' => 2025, 'amount' => '400.00']);
    }

    public function test_copy_from_previous_month_flashes_message_when_source_is_empty(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('filterMonth', 4)
            ->set('filterYear', 2025)
            ->call('copyFromPreviousMonth')
            ->assertSee('No budgets found for March 2025 to copy.');

        $this->assertDatabaseCount('budgets', 0);
    }

    public function test_copy_from_previous_month_handles_january_rollover(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();

        Budget::factory()->for($user)->for($category, 'category')->create(['month' => 12, 'year' => 2024, 'amount' => '150.00']);

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('filterMonth', 1)
            ->set('filterYear', 2025)
            ->call('copyFromPreviousMonth')
            ->assertSee('Copied 1 budget(s) from December 2024 to January 2025.');

        $this->assertDatabaseHas('budgets', ['user_id' => $user->id, 'category_id' => $category->id, 'month' => 1, 'year' => 2025, 'amount' => '150.00']);
    }

    public function test_copy_from_previous_month_does_not_copy_other_users_budgets(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherCategory = Category::factory()->for($otherUser)->create();

        Budget::factory()->for($otherUser)->for($otherCategory, 'category')->create(['month' => 3, 'year' => 2025, 'amount' => '500.00']);

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('filterMonth', 4)
            ->set('filterYear', 2025)
            ->call('copyFromPreviousMonth')
            ->assertSee('No budgets found for March 2025 to copy.');

        $this->assertDatabaseCount('budgets', 1); // Only the other user's original budget
    }

    public function test_reset_form_clears_state_to_defaults(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();
        $budget = Budget::factory()->for($user)->for($category, 'category')->create([
            'amount' => '999.00',
        ]);

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->call('edit', $budget->id)
            ->assertSet('budgetId', $budget->id)
            ->call('resetForm')
            ->assertSet('budgetId', null)
            ->assertSet('category_id', null)
            ->assertSet('amount', '0.00');
    }

    public function test_open_modal_dispatches_open_budget_modal_event(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->call('openModal')
            ->assertDispatched('open-budget-modal');
    }

    public function test_open_modal_resets_form_state(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();
        $budget = Budget::factory()->for($user)->for($category, 'category')->create();

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->call('edit', $budget->id)
            ->assertSet('budgetId', $budget->id)
            ->call('openModal')
            ->assertSet('budgetId', null)
            ->assertSet('category_id', null)
            ->assertSet('amount', '0.00');
    }

    public function test_open_modal_copies_filter_month_and_year_to_form_fields(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('filterMonth', 8)
            ->set('filterYear', 2026)
            ->call('openModal')
            ->assertSet('month', 8)
            ->assertSet('year', 2026);
    }

    public function test_edit_dispatches_open_budget_modal_event(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();
        $budget = Budget::factory()->for($user)->for($category, 'category')->create();

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->call('edit', $budget->id)
            ->assertDispatched('open-budget-modal');
    }

    public function test_save_dispatches_close_budget_modal_event_on_success(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('category_id', $category->id)
            ->set('month', 5)
            ->set('year', 2025)
            ->set('amount', '100.00')
            ->call('save')
            ->assertDispatched('close-budget-modal');
    }

    public function test_save_does_not_dispatch_close_budget_modal_event_when_validation_fails(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('category_id', null)
            ->set('month', 5)
            ->set('year', 2025)
            ->set('amount', '100.00')
            ->call('save')
            ->assertNotDispatched('close-budget-modal');
    }
}
