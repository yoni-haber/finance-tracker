<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Budgets\BudgetManager;
use App\Livewire\Dashboard;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionException;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class BudgetManagerTest extends TestCase
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
            ->assertSet('periodMonth', (int) $now->month)
            ->assertSet('periodYear', (int) $now->year);
    }

    public function test_mount_uses_the_users_persisted_period_for_the_form(): void
    {
        $user = User::factory()->create(['selected_month' => 4, 'selected_year' => 2023]);

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->assertSet('month', 4)
            ->assertSet('year', 2023)
            ->assertSet('periodMonth', 4)
            ->assertSet('periodYear', 2023);
    }

    public function test_open_modal_defaults_form_to_the_persisted_period(): void
    {
        $user = User::factory()->create(['selected_month' => 7, 'selected_year' => 2022]);

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->call('openModal')
            ->assertSet('month', 7)
            ->assertSet('year', 2022);
    }

    public function test_period_changed_event_updates_the_budget_filter(): void
    {
        $user = User::factory()->create(['selected_month' => 5, 'selected_year' => 2024]);

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->dispatch('period-changed', month: 11, year: 2021)
            ->assertSet('periodMonth', 11)
            ->assertSet('periodYear', 2021);
    }

    public function test_save_creates_budget_with_valid_data(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();

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
        $category = Category::factory()->for($user)->expense()->create();

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
        $otherCategory = Category::factory()->for($otherUser)->expense()->create();

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
            ->set('category_id')
            ->set('month', 5)
            ->set('year', 2025)
            ->set('amount', '100.00')
            ->call('save')
            ->assertHasErrors(['category_id' => 'required']);
    }

    public function test_save_create_validates_month_out_of_range(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();

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
        $category = Category::factory()->for($user)->expense()->create();

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
        $category = Category::factory()->for($user)->expense()->create();
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
        $category = Category::factory()->for($user)->expense()->create();
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
        $category = Category::factory()->for($user)->expense()->create();

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
        $category = Category::factory()->for($user)->expense()->create();
        $otherCategory = Category::factory()->for($otherUser)->expense()->create();
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
        $category = Category::factory()->for($user)->expense()->create();
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

    public function test_edit_returns_404_for_another_users_budget(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherBudget = Budget::factory()
            ->for($otherUser)
            ->for(Category::factory()->for($otherUser)->expense(), 'category')
            ->create();

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->call('edit', $otherBudget->id)
            ->assertStatus(404);
    }

    public function test_delete_removes_own_budget_and_flashes_message(): void
    {
        $user = User::factory()->create();
        $budget = Budget::factory()
            ->for($user)
            ->for(Category::factory()->for($user)->expense(), 'category')
            ->create();

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->call('confirmDelete', $budget->id)
            ->assertSet('deletingBudgetId', $budget->id)
            ->assertDispatched('open-delete-budget-modal')
            ->call('delete')
            ->assertDispatched('close-delete-budget-modal')
            ->assertSee('Budget removed.');

        $this->assertDatabaseMissing('budgets', ['id' => $budget->id]);
    }

    public function test_delete_confirmation_rejects_another_users_budget(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherBudget = Budget::factory()
            ->for($otherUser)
            ->for(Category::factory()->for($otherUser)->expense(), 'category')
            ->create();

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->call('confirmDelete', $otherBudget->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('budgets', ['id' => $otherBudget->id]);
    }

    public function test_render_filters_by_category(): void
    {
        $user = User::factory()->create();
        $catA = Category::factory()->for($user)->expense()->create(['name' => 'Groceries']);
        $catB = Category::factory()->for($user)->expense()->create(['name' => 'Rent']);

        Budget::factory()->for($user)->for($catA, 'category')->create(['month' => 1, 'year' => 2025]);
        Budget::factory()->for($user)->for($catB, 'category')->create(['month' => 1, 'year' => 2025]);

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('periodMonth', 1)
            ->set('periodYear', 2025)
            ->set('filterCategory', $catA->id)
            ->assertViewHas('budgets', fn ($budgets): bool => $budgets->count() === 1
                && $budgets->first()->category_id === $catA->id
                && $budgets->doesntContain('category_id', $catB->id));
    }

    public function test_progress_matches_dashboard_for_parent_child_and_recurring_occurrences(): void
    {
        Carbon::setTestNow('2024-05-15');

        $user = User::factory()->create(['selected_month' => 5, 'selected_year' => 2024]);
        $otherUser = User::factory()->create();
        $parent = Category::factory()->for($user)->expense()->create(['name' => 'Food']);
        $child = Category::factory()->subcategoryOf($parent)->create(['name' => 'Groceries']);
        $otherCategory = Category::factory()->for($otherUser)->expense()->create(['name' => 'Private']);
        Budget::factory()->for($user)->for($parent, 'category')->create([
            'month' => 5, 'year' => 2024, 'amount' => '100.00',
        ]);
        Budget::factory()->for($otherUser)->for($otherCategory, 'category')->create([
            'month' => 5, 'year' => 2024, 'amount' => '500.00',
        ]);

        $user->transactions()->createMany([
            ['category_id' => $parent->id, 'type' => Transaction::TYPE_EXPENSE, 'amount' => '20.10', 'date' => '2024-05-02'],
            ['category_id' => $child->id, 'type' => Transaction::TYPE_EXPENSE, 'amount' => '30.20', 'date' => '2024-05-03'],
            ['category_id' => $child->id, 'type' => Transaction::TYPE_EXPENSE, 'amount' => '99.00', 'date' => '2024-05-20'],
        ]);
        $recurring = $user->transactions()->create([
            'category_id' => $child->id,
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => '10.00',
            'date' => '2024-05-01',
            'is_recurring' => true,
            'frequency' => 'weekly',
        ]);
        TransactionException::create(['transaction_id' => $recurring->id, 'date' => '2024-05-08']);

        $otherUser->transactions()->create([
            'category_id' => $otherCategory->id, 'type' => Transaction::TYPE_EXPENSE,
            'amount' => '500.00', 'date' => '2024-05-04',
        ]);

        $expected = [
            'category' => 'Food', 'budget' => '100.00', 'actual' => '70.30',
            'remaining' => '29.70', 'over' => '0.00', 'overspent' => false,
            'percent' => 70, 'barPercent' => 70,
        ];

        Livewire::actingAs($user)->test(BudgetManager::class)
            ->assertViewHas('budgetSummaries', fn ($summaries): bool => $summaries->sole() === $expected)
            ->assertSee('Budgets for May 2024')
            ->assertSee('£70.30')
            ->assertSee('£29.70 remaining')
            ->assertSee('70% used')
            ->assertSeeHtml('aria-label="Food budget used"')
            ->assertSeeHtml('width: 70%');

        Livewire::actingAs($user)->test(Dashboard::class)
            ->assertViewHas('budgetSummaries', fn ($summaries): bool => $summaries->sole() === $expected);
    }

    public function test_progress_shows_overrun_and_zero_limit_without_negative_remaining(): void
    {
        Carbon::setTestNow('2024-05-15');

        $user = User::factory()->create(['selected_month' => 5, 'selected_year' => 2024]);
        $food = Category::factory()->for($user)->expense()->create(['name' => 'Food']);
        $travel = Category::factory()->for($user)->expense()->create(['name' => 'Travel']);
        Budget::factory()->for($user)->for($food, 'category')->create(['month' => 5, 'year' => 2024, 'amount' => '60.00']);
        Budget::factory()->for($user)->for($travel, 'category')->create(['month' => 5, 'year' => 2024, 'amount' => '0.00']);
        $user->transactions()->createMany([
            ['category_id' => $food->id, 'type' => Transaction::TYPE_EXPENSE, 'amount' => '75.00', 'date' => '2024-05-02'],
            ['category_id' => $travel->id, 'type' => Transaction::TYPE_EXPENSE, 'amount' => '10.01', 'date' => '2024-05-02'],
        ]);

        Livewire::actingAs($user)->test(BudgetManager::class)
            ->assertViewHas('budgetSummaries', function ($summaries): bool {
                $food = $summaries->firstWhere('category', 'Food');
                $travel = $summaries->firstWhere('category', 'Travel');

                return $food['actual'] === '75.00' && $food['remaining'] === '0.00'
                    && $food['over'] === '15.00' && $food['percent'] === 125
                    && $food['barPercent'] === 100 && $travel['actual'] === '10.01'
                    && $travel['remaining'] === '0.00' && $travel['over'] === '10.01'
                    && $travel['percent'] === null && $travel['barPercent'] === 100;
            })
            ->assertSee('£15.00 over')
            ->assertSee('£10.01 over')
            ->assertSee('125% used')
            ->assertSee('Over budget')
            ->assertSeeHtml('aria-valuenow="100"')
            ->assertDontSee('-£15.00');

        Livewire::actingAs($user)->test(Dashboard::class)
            ->assertViewHas('budgetSummaries', function ($summaries): bool {
                $food = $summaries->firstWhere('category', 'Food');
                $travel = $summaries->firstWhere('category', 'Travel');

                return $food['over'] === '15.00' && $food['percent'] === 125
                    && $travel['over'] === '10.01' && $travel['percent'] === null;
            })
            ->assertSee('£15.00 over')
            ->assertSee('Over budget');
    }

    public function test_progress_handles_zero_spending_exact_limit_rounding_and_refunds(): void
    {
        Carbon::setTestNow('2024-05-15');

        $user = User::factory()->create(['selected_month' => 5, 'selected_year' => 2024]);
        $empty = Category::factory()->for($user)->expense()->create(['name' => 'Empty']);
        $exact = Category::factory()->for($user)->expense()->create(['name' => 'Exact']);
        $rounded = Category::factory()->for($user)->expense()->create(['name' => 'Rounded']);
        $refunded = Category::factory()->for($user)->expense()->create(['name' => 'Refunded']);

        Budget::factory()->for($user)->for($empty, 'category')->create(['month' => 5, 'year' => 2024, 'amount' => '0.00']);
        Budget::factory()->for($user)->for($exact, 'category')->create(['month' => 5, 'year' => 2024, 'amount' => '20.00']);
        Budget::factory()->for($user)->for($rounded, 'category')->create(['month' => 5, 'year' => 2024, 'amount' => '3.00']);
        Budget::factory()->for($user)->for($refunded, 'category')->create(['month' => 5, 'year' => 2024, 'amount' => '1.00']);

        $user->transactions()->createMany([
            ['category_id' => $exact->id, 'type' => Transaction::TYPE_EXPENSE, 'amount' => '20.00', 'date' => '2024-05-05'],
            ['category_id' => $rounded->id, 'type' => Transaction::TYPE_EXPENSE, 'amount' => '2.00', 'date' => '2024-05-05'],
            ['category_id' => $refunded->id, 'type' => Transaction::TYPE_EXPENSE, 'amount' => '-0.01', 'date' => '2024-05-05'],
        ]);

        Livewire::actingAs($user)->test(BudgetManager::class)
            ->assertViewHas('budgetSummaries', function ($summaries): bool {
                $empty = $summaries->firstWhere('category', 'Empty');
                $exact = $summaries->firstWhere('category', 'Exact');
                $rounded = $summaries->firstWhere('category', 'Rounded');
                $refunded = $summaries->firstWhere('category', 'Refunded');

                return $empty['actual'] === '0.00' && $empty['percent'] === 0
                    && $empty['barPercent'] === 0 && !$empty['overspent']
                    && $exact['actual'] === '20.00' && $exact['percent'] === 100
                    && $exact['barPercent'] === 100 && !$exact['overspent']
                    && $rounded['actual'] === '2.00' && $rounded['percent'] === 67
                    && $rounded['barPercent'] === 67
                    && $refunded['actual'] === '-0.01' && $refunded['percent'] === -1
                    && $refunded['barPercent'] === 0;
            });
    }

    public function test_progress_uses_full_past_month_and_zero_spent_for_future_month(): void
    {
        Carbon::setTestNow('2024-05-15');

        $user = User::factory()->create(['selected_month' => 4, 'selected_year' => 2024]);
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Food']);
        Budget::factory()->for($user)->for($category, 'category')->create(['month' => 4, 'year' => 2024, 'amount' => '100.00']);
        Budget::factory()->for($user)->for($category, 'category')->create(['month' => 6, 'year' => 2024, 'amount' => '100.00']);
        $user->transactions()->createMany([
            ['category_id' => $category->id, 'type' => Transaction::TYPE_EXPENSE, 'amount' => '40.00', 'date' => '2024-04-30'],
            ['category_id' => $category->id, 'type' => Transaction::TYPE_EXPENSE, 'amount' => '90.00', 'date' => '2024-06-01'],
        ]);

        $testable = Livewire::actingAs($user)->test(BudgetManager::class);
        $testable->assertViewHas('budgetSummaries', fn ($summaries): bool => $summaries->sole()['actual'] === '40.00');
        $testable->set('periodMonth', 6)
            ->assertViewHas('budgetSummaries', fn ($summaries): bool => $summaries->sole()['actual'] === '0.00')
            ->assertSee('Budgets for June 2024')
            ->assertSee('£100.00 remaining');
    }

    public function test_render_filters_by_month_and_year(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Travel']);

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
            ->set('periodMonth', 2)
            ->set('periodYear', 2025)
            ->set('filterCategory')
            ->assertViewHas('budgets', fn ($budgets): bool => $budgets->count() === 1
                && $budgets->first()->id === $budgetFeb->id
                && $budgets->doesntContain('id', $budgetMar->id));
    }

    public function test_copy_from_previous_month_creates_missing_budgets(): void
    {
        $user = User::factory()->create();
        $catA = Category::factory()->for($user)->expense()->create();
        $catB = Category::factory()->for($user)->expense()->create();

        Budget::factory()->for($user)->for($catA, 'category')->create(['month' => 3, 'year' => 2025, 'amount' => '200.00']);
        Budget::factory()->for($user)->for($catB, 'category')->create(['month' => 3, 'year' => 2025, 'amount' => '400.00']);

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('periodMonth', 4)
            ->set('periodYear', 2025)
            ->call('copyFromPreviousMonth')
            ->assertSee('Copied 2 budget(s) from March 2025 to April 2025.');

        $this->assertDatabaseHas('budgets', ['user_id' => $user->id, 'category_id' => $catA->id, 'month' => 4, 'year' => 2025, 'amount' => '200.00']);
        $this->assertDatabaseHas('budgets', ['user_id' => $user->id, 'category_id' => $catB->id, 'month' => 4, 'year' => 2025, 'amount' => '400.00']);
    }

    public function test_copy_from_previous_month_skips_existing_budgets(): void
    {
        $user = User::factory()->create();
        $catA = Category::factory()->for($user)->expense()->create();
        $catB = Category::factory()->for($user)->expense()->create();

        // Previous month
        Budget::factory()->for($user)->for($catA, 'category')->create(['month' => 3, 'year' => 2025, 'amount' => '200.00']);
        Budget::factory()->for($user)->for($catB, 'category')->create(['month' => 3, 'year' => 2025, 'amount' => '400.00']);

        // catA already has a budget in the target month
        Budget::factory()->for($user)->for($catA, 'category')->create(['month' => 4, 'year' => 2025, 'amount' => '999.00']);

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('periodMonth', 4)
            ->set('periodYear', 2025)
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
            ->set('periodMonth', 4)
            ->set('periodYear', 2025)
            ->call('copyFromPreviousMonth')
            ->assertSee('No budgets found for March 2025 to copy.');

        $this->assertDatabaseCount('budgets', 0);
    }

    public function test_copy_from_previous_month_handles_january_rollover(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();

        Budget::factory()->for($user)->for($category, 'category')->create(['month' => 12, 'year' => 2024, 'amount' => '150.00']);

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('periodMonth', 1)
            ->set('periodYear', 2025)
            ->call('copyFromPreviousMonth')
            ->assertSee('Copied 1 budget(s) from December 2024 to January 2025.');

        $this->assertDatabaseHas('budgets', ['user_id' => $user->id, 'category_id' => $category->id, 'month' => 1, 'year' => 2025, 'amount' => '150.00']);
    }

    public function test_copy_from_previous_month_does_not_copy_other_users_budgets(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherCategory = Category::factory()->for($otherUser)->expense()->create();

        Budget::factory()->for($otherUser)->for($otherCategory, 'category')->create(['month' => 3, 'year' => 2025, 'amount' => '500.00']);

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('periodMonth', 4)
            ->set('periodYear', 2025)
            ->call('copyFromPreviousMonth')
            ->assertSee('No budgets found for March 2025 to copy.');

        $this->assertDatabaseCount('budgets', 1); // Only the other user's original budget
    }

    public function test_reset_form_clears_state_to_defaults(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();
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
        $category = Category::factory()->for($user)->expense()->create();
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
            ->set('periodMonth', 8)
            ->set('periodYear', 2026)
            ->call('openModal')
            ->assertSet('month', 8)
            ->assertSet('year', 2026);
    }

    public function test_edit_dispatches_open_budget_modal_event(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();
        $budget = Budget::factory()->for($user)->for($category, 'category')->create();

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->call('edit', $budget->id)
            ->assertDispatched('open-budget-modal');
    }

    public function test_save_dispatches_close_budget_modal_event_on_success(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();

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
            ->set('category_id')
            ->set('month', 5)
            ->set('year', 2025)
            ->set('amount', '100.00')
            ->call('save')
            ->assertNotDispatched('close-budget-modal');
    }

    public function test_save_rejects_income_category(): void
    {
        $user = User::factory()->create();
        $incomeCategory = Category::factory()->for($user)->income()->create();

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('category_id', $incomeCategory->id)
            ->set('month', 5)
            ->set('year', 2025)
            ->set('amount', '200.00')
            ->call('save')
            ->assertHasErrors('category_id');
    }

    public function test_save_rejects_subcategory(): void
    {
        $user = User::factory()->create();
        $parent = Category::factory()->for($user)->expense()->create(['name' => 'Food']);
        $sub = Category::factory()->subcategoryOf($parent)->create(['name' => 'Groceries']);

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('category_id', $sub->id)
            ->set('month', 5)
            ->set('year', 2025)
            ->set('amount', '200.00')
            ->call('save')
            ->assertHasErrors('category_id');
    }

    public function test_render_categories_shows_only_spending_expense_parents(): void
    {
        $user = User::factory()->create();
        $expenseParent = Category::factory()->for($user)->expense()->create(['name' => 'Food']);
        $savingParent = Category::factory()->for($user)->expense()->create([
            'name' => 'Savings',
            'expense_treatment' => Category::TREATMENT_SAVING,
        ]);
        $incomeParent = Category::factory()->for($user)->income()->create(['name' => 'Employment']);
        $sub = Category::factory()->subcategoryOf($expenseParent)->create(['name' => 'Groceries']);

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->assertViewHas('categories', fn ($cats) => $cats->contains('id', $expenseParent->id))
            ->assertViewHas('categories', fn ($cats) => $cats->doesntContain('id', $savingParent->id))
            ->assertViewHas('categories', fn ($cats) => $cats->doesntContain('id', $incomeParent->id))
            ->assertViewHas('categories', fn ($cats) => $cats->doesntContain('id', $sub->id));
    }

    public function test_save_rejects_saving_and_investment_categories(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create([
            'expense_treatment' => Category::TREATMENT_SAVING,
        ]);

        Livewire::actingAs($user)
            ->test(BudgetManager::class)
            ->set('category_id', $category->id)
            ->set('month', 5)
            ->set('year', 2025)
            ->set('amount', '200.00')
            ->call('save')
            ->assertHasErrors('category_id');
    }
}
