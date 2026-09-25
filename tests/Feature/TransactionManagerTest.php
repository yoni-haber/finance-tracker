<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Transactions\TransactionManager;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class TransactionManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_mount_sets_date_month_and_year_to_current(): void
    {
        Carbon::setTestNow('2024-06-15');

        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->assertSet('date', '2024-06-15')
            ->assertSet('periodMonth', 6)
            ->assertSet('periodYear', 2024);
    }

    public function test_mount_uses_the_users_persisted_period(): void
    {
        Carbon::setTestNow('2024-06-15');

        $user = User::factory()->create(['selected_month' => 3, 'selected_year' => 2023]);

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->assertSet('periodMonth', 3)
            ->assertSet('periodYear', 2023)
            ->assertSet('date', '2023-03-01');
    }

    public function test_new_transaction_date_defaults_to_today_for_the_current_month(): void
    {
        Carbon::setTestNow('2024-06-15');

        $user = User::factory()->create(['selected_month' => 6, 'selected_year' => 2024]);

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->call('openModal')
            ->assertSet('date', '2024-06-15');
    }

    public function test_new_transaction_date_defaults_to_first_of_a_past_month(): void
    {
        Carbon::setTestNow('2024-06-15');

        $user = User::factory()->create(['selected_month' => 2, 'selected_year' => 2024]);

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->call('openModal')
            ->assertSet('date', '2024-02-01');
    }

    public function test_period_changed_event_updates_the_transaction_period(): void
    {
        $user = User::factory()->create(['selected_month' => 5, 'selected_year' => 2024]);

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->dispatch('period-changed', month: 1, year: 2020)
            ->assertSet('periodMonth', 1)
            ->assertSet('periodYear', 2020);
    }

    public function test_save_creates_non_recurring_transaction_with_null_frequency(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('type', Transaction::TYPE_EXPENSE)
            ->set('amount', '50.00')
            ->set('date', '2024-06-15')
            ->set('description', 'Coffee')
            ->set('is_recurring', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => Transaction::TYPE_EXPENSE,
            'description' => 'Coffee',
            'is_recurring' => false,
            'frequency' => null,
            'recurring_until' => null,
        ]);
    }

    public function test_save_creates_recurring_monthly_transaction(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('type', Transaction::TYPE_INCOME)
            ->set('amount', '1500.00')
            ->set('date', '2024-06-01')
            ->set('is_recurring', true)
            ->set('frequency', 'monthly')
            ->set('recurring_until', '2025-06-01')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => Transaction::TYPE_INCOME,
            'is_recurring' => true,
            'frequency' => 'monthly',
        ]);
    }

    public function test_save_updates_existing_transaction(): void
    {
        $user = User::factory()->create();
        $transaction = Transaction::factory()->for($user)->create([
            'category_id' => null,
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => '100.00',
            'date' => '2024-06-10',
            'is_recurring' => false,
            'frequency' => null,
        ]);

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('transactionId', $transaction->id)
            ->set('type', Transaction::TYPE_EXPENSE)
            ->set('amount', '200.00')
            ->set('date', '2024-06-10')
            ->set('is_recurring', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'amount' => '200.00',
        ]);
    }

    public function test_save_adds_error_when_transaction_id_not_found(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('transactionId', 99999)
            ->set('type', Transaction::TYPE_EXPENSE)
            ->set('amount', '50.00')
            ->set('date', '2024-06-15')
            ->set('is_recurring', false)
            ->call('save')
            ->assertHasErrors('save');
    }

    public function test_save_cannot_update_another_users_transaction(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherTransaction = Transaction::factory()->for($otherUser)->create([
            'category_id' => null,
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => '100.00',
            'date' => '2024-06-10',
            'is_recurring' => false,
            'frequency' => null,
        ]);

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('transactionId', $otherTransaction->id)
            ->set('type', Transaction::TYPE_EXPENSE)
            ->set('amount', '50.00')
            ->set('date', '2024-06-15')
            ->set('is_recurring', false)
            ->call('save')
            ->assertHasErrors('save');

        // Original amount must remain unchanged
        $this->assertDatabaseHas('transactions', [
            'id' => $otherTransaction->id,
            'amount' => '100.00',
        ]);
    }

    public function test_save_fails_validation_with_invalid_type(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('type', 'invalid')
            ->set('amount', '50.00')
            ->set('date', '2024-06-15')
            ->set('is_recurring', false)
            ->call('save')
            ->assertHasErrors(['type']);
    }

    public function test_save_fails_validation_with_amount_below_minimum(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('type', Transaction::TYPE_EXPENSE)
            ->set('amount', '0.00')
            ->set('date', '2024-06-15')
            ->set('is_recurring', false)
            ->call('save')
            ->assertHasErrors(['amount']);
    }

    public function test_save_fails_validation_when_frequency_missing_and_is_recurring_true(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('type', Transaction::TYPE_EXPENSE)
            ->set('amount', '50.00')
            ->set('date', '2024-06-15')
            ->set('is_recurring', true)
            ->set('frequency')
            ->call('save')
            ->assertHasErrors(['frequency']);
    }

    public function test_save_fails_validation_when_category_belongs_to_different_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherCategory = Category::factory()->for($otherUser)->create();

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('type', Transaction::TYPE_EXPENSE)
            ->set('amount', '50.00')
            ->set('date', '2024-06-15')
            ->set('is_recurring', false)
            ->set('category_id', $otherCategory->id)
            ->call('save')
            ->assertHasErrors(['category_id']);
    }

    public function test_edit_loads_all_fields_from_existing_transaction(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->income()->create();
        $transaction = Transaction::factory()->for($user)->create([
            'category_id' => $category->id,
            'type' => Transaction::TYPE_INCOME,
            'amount' => '250.00',
            'date' => '2024-06-15',
            'description' => 'Freelance payment',
            'is_recurring' => true,
            'frequency' => 'monthly',
            'recurring_until' => '2024-12-31',
        ]);

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->call('edit', $transaction->id)
            ->assertSet('transactionId', $transaction->id)
            ->assertSet('type', Transaction::TYPE_INCOME)
            ->assertSet('amount', '250.00')
            ->assertSet('date', '2024-06-15')
            ->assertSet('description', 'Freelance payment')
            ->assertSet('category_id', $category->id)
            ->assertSet('is_recurring', true)
            ->assertSet('frequency', 'monthly')
            ->assertSet('recurring_until', '2024-12-31');
    }

    public function test_edit_returns_404_for_another_users_transaction(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherTransaction = Transaction::factory()->for($otherUser)->create([
            'category_id' => null,
            'is_recurring' => false,
            'frequency' => null,
        ]);

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->call('edit', $otherTransaction->id)
            ->assertStatus(404);
    }

    public function test_delete_removes_non_recurring_transaction(): void
    {
        $user = User::factory()->create();
        $transaction = Transaction::factory()->for($user)->create([
            'category_id' => null,
            'is_recurring' => false,
            'frequency' => null,
        ]);

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->call('confirmDelete', $transaction->id, '2024-06-01')
            ->assertDispatched('open-delete-transaction-modal')
            ->call('delete')
            ->assertDispatched('close-delete-transaction-modal')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('transactions', ['id' => $transaction->id]);
    }

    public function test_delete_confirmation_uses_description_and_fallback_label(): void
    {
        $user = User::factory()->create();
        $described = Transaction::factory()->for($user)->create([
            'category_id' => null,
            'description' => 'Groceries',
            'is_recurring' => false,
            'frequency' => null,
        ]);
        $unnamed = Transaction::factory()->for($user)->create([
            'category_id' => null,
            'description' => '',
            'is_recurring' => false,
            'frequency' => null,
        ]);

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->call('confirmDelete', $described->id)
            ->assertSet('deletingDescription', 'Groceries')
            ->call('confirmDelete', $unnamed->id)
            ->assertSet('deletingDescription', 'this transaction');
    }

    public function test_delete_removes_entire_recurring_series_when_no_occurrence_date(): void
    {
        $user = User::factory()->create();
        $transaction = Transaction::factory()->for($user)->create([
            'category_id' => null,
            'is_recurring' => true,
            'frequency' => 'monthly',
            'date' => '2024-06-01',
        ]);

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->call('confirmDelete', $transaction->id, '2024-06-01')
            ->call('delete', true)
            ->assertHasNoErrors()
            ->assertSee('Recurring transaction series removed.')
            ->assertSet('deletingTransactionId', null)
            ->assertSet('deletingOccurrenceDate', null)
            ->assertSet('deletingDescription', '')
            ->assertSet('deletingIsRecurring', false)
            ->assertDispatched('close-delete-transaction-modal');

        $this->assertDatabaseMissing('transactions', ['id' => $transaction->id]);
    }

    public function test_delete_creates_occurrence_exception_for_recurring_transaction(): void
    {
        $user = User::factory()->create();
        $transaction = Transaction::factory()->for($user)->create([
            'category_id' => null,
            'is_recurring' => true,
            'frequency' => 'monthly',
            'date' => '2024-05-01',
        ]);

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->call('confirmDelete', $transaction->id, '2024-06-01')
            ->call('delete')
            ->assertHasNoErrors()
            ->assertSee('Transaction occurrence removed.')
            ->assertSet('deletingTransactionId', null)
            ->assertSet('deletingOccurrenceDate', null)
            ->assertSet('deletingDescription', '')
            ->assertSet('deletingIsRecurring', false)
            ->assertDispatched('close-delete-transaction-modal');

        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
        $this->assertDatabaseHas('transaction_exceptions', [
            'transaction_id' => $transaction->id,
            'date' => Carbon::parse('2024-06-01')->toDateTimeString(),
        ]);
    }

    public function test_delete_returns_error_for_invalid_occurrence_date_format(): void
    {
        $user = User::factory()->create();
        $transaction = Transaction::factory()->for($user)->create([
            'category_id' => null,
            'is_recurring' => true,
            'frequency' => 'monthly',
            'date' => '2024-05-01',
        ]);

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->call('confirmDelete', $transaction->id, 'not-a-date')
            ->call('delete', false)
            ->assertHasErrors('delete');

        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
        $this->assertDatabaseCount('transaction_exceptions', 0);
    }

    public function test_delete_returns_404_for_another_users_transaction(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherTransaction = Transaction::factory()->for($otherUser)->create([
            'category_id' => null,
            'is_recurring' => false,
            'frequency' => null,
        ]);

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->call('confirmDelete', $otherTransaction->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('transactions', ['id' => $otherTransaction->id]);
    }

    public function test_updated_is_recurring_false_clears_frequency_and_recurring_until(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            // Bootstrap a recurring state first
            ->set('is_recurring', true)
            ->set('frequency', 'monthly')
            ->set('recurring_until', '2025-01-01')
            // Toggle off
            ->set('is_recurring', false)
            ->assertSet('frequency', null)
            ->assertSet('recurring_until', null);
    }

    public function test_updated_is_recurring_true_defaults_frequency_to_monthly_when_not_set(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('frequency')
            ->set('is_recurring', true)
            ->assertSet('frequency', 'monthly');
    }

    public function test_updated_is_recurring_true_does_not_override_existing_frequency(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('frequency', 'weekly')
            ->set('is_recurring', true)
            ->assertSet('frequency', 'weekly');
    }

    public function test_render_filter_type_shows_only_income_transactions(): void
    {
        Carbon::setTestNow('2024-06-15');

        $user = User::factory()->create();

        Transaction::factory()->for($user)->create([
            'category_id' => null,
            'type' => Transaction::TYPE_INCOME,
            'amount' => '1000.00',
            'date' => '2024-06-10',
            'is_recurring' => false,
            'frequency' => null,
        ]);
        Transaction::factory()->for($user)->create([
            'category_id' => null,
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => '50.00',
            'date' => '2024-06-10',
            'is_recurring' => false,
            'frequency' => null,
        ]);

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('filterType', Transaction::TYPE_INCOME)
            ->assertViewHas('transactions', fn ($transactions): bool => $transactions->count() === 1
                && $transactions->every(fn ($t): bool => $t->type === Transaction::TYPE_INCOME));
    }

    public function test_render_filter_category_shows_only_matching_category_transactions(): void
    {
        Carbon::setTestNow('2024-06-15');

        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();

        Transaction::factory()->for($user)->create([
            'category_id' => $category->id,
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => '30.00',
            'date' => '2024-06-10',
            'is_recurring' => false,
            'frequency' => null,
        ]);
        Transaction::factory()->for($user)->create([
            'category_id' => null,
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => '20.00',
            'date' => '2024-06-10',
            'is_recurring' => false,
            'frequency' => null,
        ]);

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('filterParentCategory', $category->id)
            ->assertViewHas('transactions', fn ($transactions): bool => $transactions->count() === 1
                && $transactions->every(fn ($t): bool => $t->category_id === $category->id));
    }

    public function test_query_parameters_initialise_transaction_filters(): void
    {
        $user = User::factory()->create();
        $parent = Category::factory()->for($user)->expense()->create(['name' => 'Food']);
        $sub = Category::factory()->subcategoryOf($parent)->create(['name' => 'Groceries']);

        Livewire::withQueryParams([
            'category' => $parent->id,
            'subcategory' => $sub->id,
            'type' => Transaction::TYPE_EXPENSE,
        ])
            ->actingAs($user)
            ->test(TransactionManager::class)
            ->assertSet('filterParentCategory', $parent->id)
            ->assertSet('filterSubCategory', $sub->id)
            ->assertSet('filterType', Transaction::TYPE_EXPENSE);
    }

    public function test_category_and_type_query_parameters_filter_transactions(): void
    {
        Carbon::setTestNow('2024-06-15');

        $user = User::factory()->create(['selected_month' => 6, 'selected_year' => 2024]);
        $parent = Category::factory()->for($user)->expense()->create(['name' => 'Food']);
        $sub = Category::factory()->subcategoryOf($parent)->create(['name' => 'Groceries']);
        $other = Category::factory()->for($user)->expense()->create(['name' => 'Housing']);
        $income = Category::factory()->for($user)->income()->create(['name' => 'Salary']);

        Transaction::factory()->for($user)->create([
            'category_id' => $parent->id,
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => '25.00',
            'date' => '2024-06-08',
            'is_recurring' => false,
        ]);
        Transaction::factory()->for($user)->create([
            'category_id' => $sub->id,
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => '50.00',
            'date' => '2024-06-10',
            'is_recurring' => false,
        ]);
        Transaction::factory()->for($user)->create([
            'category_id' => $other->id,
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => '100.00',
            'date' => '2024-06-10',
            'is_recurring' => false,
        ]);
        Transaction::factory()->for($user)->create([
            'category_id' => $income->id,
            'type' => Transaction::TYPE_INCOME,
            'amount' => '200.00',
            'date' => '2024-06-10',
            'is_recurring' => false,
        ]);

        Livewire::withQueryParams([
            'category' => $parent->id,
            'type' => Transaction::TYPE_EXPENSE,
        ])
            ->actingAs($user)
            ->test(TransactionManager::class)
            ->assertViewHas('transactions', fn ($transactions): bool => $transactions->count() === 2
                && $transactions->every(fn ($transaction): bool => $transaction->type === Transaction::TYPE_EXPENSE)
                && $transactions->pluck('category_id')->sort()->values()->all() === [$parent->id, $sub->id]);
    }

    public function test_render_filter_by_parent_category_includes_subcategory_transactions(): void
    {
        Carbon::setTestNow('2024-06-15');

        $user = User::factory()->create();
        $parent = Category::factory()->for($user)->expense()->create(['name' => 'Food']);
        $sub = Category::factory()->subcategoryOf($parent)->create(['name' => 'Groceries']);
        $other = Category::factory()->for($user)->expense()->create(['name' => 'Housing']);

        Transaction::factory()->for($user)->create([
            'category_id' => $sub->id,
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => '50.00',
            'date' => '2024-06-10',
            'is_recurring' => false,
        ]);
        Transaction::factory()->for($user)->create([
            'category_id' => $other->id,
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => '100.00',
            'date' => '2024-06-10',
            'is_recurring' => false,
        ]);

        // Selecting the parent shows transactions from all its subcategories.
        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('filterParentCategory', $parent->id)
            ->assertViewHas('transactions', fn ($transactions): bool => $transactions->count() === 1
                && $transactions->every(fn ($t): bool => $t->category_id === $sub->id));
    }

    public function test_render_filter_sub_category_drills_down_within_parent(): void
    {
        Carbon::setTestNow('2024-06-15');

        $user = User::factory()->create();
        $parent = Category::factory()->for($user)->expense()->create(['name' => 'Food']);
        $sub1 = Category::factory()->subcategoryOf($parent)->create(['name' => 'Groceries']);
        $sub2 = Category::factory()->subcategoryOf($parent)->create(['name' => 'Restaurants']);

        Transaction::factory()->for($user)->create([
            'category_id' => $sub1->id,
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => '50.00',
            'date' => '2024-06-10',
            'is_recurring' => false,
        ]);
        Transaction::factory()->for($user)->create([
            'category_id' => $sub2->id,
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => '30.00',
            'date' => '2024-06-10',
            'is_recurring' => false,
        ]);

        // Selecting parent shows both subcategory transactions.
        $testable = Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('filterParentCategory', $parent->id);

        $testable->assertViewHas('transactions', fn ($t): bool => $t->count() === 2);

        // Drilling into sub1 narrows to just that subcategory.
        $testable
            ->set('filterSubCategory', $sub1->id)
            ->assertViewHas('transactions', fn ($transactions): bool => $transactions->count() === 1
                && $transactions->every(fn ($t): bool => $t->category_id === $sub1->id));
    }

    public function test_updated_filter_parent_category_resets_sub_category(): void
    {
        $user = User::factory()->create();
        $parent = Category::factory()->for($user)->expense()->create(['name' => 'Food']);
        $sub = Category::factory()->subcategoryOf($parent)->create(['name' => 'Groceries']);

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('filterParentCategory', $parent->id)
            ->set('filterSubCategory', $sub->id)
            ->assertSet('filterSubCategory', $sub->id)
            ->set('filterParentCategory')
            ->assertSet('filterSubCategory', null);
    }

    public function test_reset_form_restores_all_fields_to_defaults(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('transactionId', 999)
            ->set('type', Transaction::TYPE_INCOME)
            ->set('amount', '500.00')
            ->set('description', 'Some description')
            ->set('is_recurring', true)
            ->set('frequency', 'weekly')
            ->set('recurring_until', '2025-12-31')
            ->call('resetForm')
            ->assertSet('transactionId', null)
            ->assertSet('type', Transaction::TYPE_EXPENSE)
            ->assertSet('amount', '0.00')
            ->assertSet('description', null)
            ->assertSet('category_id', null)
            ->assertSet('is_recurring', false)
            ->assertSet('frequency', null)
            ->assertSet('recurring_until', null);
    }

    public function test_open_modal_dispatches_open_transaction_modal_event(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->call('openModal')
            ->assertDispatched('open-transaction-modal');
    }

    public function test_open_modal_resets_form_state(): void
    {
        $user = User::factory()->create();
        $transaction = Transaction::factory()->for($user)->create([
            'category_id' => null,
            'type' => Transaction::TYPE_INCOME,
            'amount' => '300.00',
            'date' => '2024-06-15',
            'is_recurring' => false,
            'frequency' => null,
        ]);

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->call('edit', $transaction->id)
            ->assertSet('transactionId', $transaction->id)
            ->call('openModal')
            ->assertSet('transactionId', null)
            ->assertSet('type', Transaction::TYPE_EXPENSE)
            ->assertSet('amount', '0.00');
    }

    public function test_edit_dispatches_open_transaction_modal_event(): void
    {
        $user = User::factory()->create();
        $transaction = Transaction::factory()->for($user)->create([
            'category_id' => null,
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => '50.00',
            'date' => '2024-06-10',
            'is_recurring' => false,
            'frequency' => null,
        ]);

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->call('edit', $transaction->id)
            ->assertDispatched('open-transaction-modal');
    }

    public function test_save_dispatches_close_transaction_modal_event_on_success(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('type', Transaction::TYPE_EXPENSE)
            ->set('amount', '25.00')
            ->set('date', '2024-06-15')
            ->set('is_recurring', false)
            ->call('save')
            ->assertDispatched('close-transaction-modal');
    }

    public function test_save_does_not_dispatch_close_transaction_modal_event_when_validation_fails(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('type', 'invalid')
            ->set('amount', '25.00')
            ->set('date', '2024-06-15')
            ->set('is_recurring', false)
            ->call('save')
            ->assertNotDispatched('close-transaction-modal');
    }

    public function test_form_categories_are_filtered_by_transaction_type(): void
    {
        $user = User::factory()->create();

        $incomeParent = Category::factory()->for($user)->income()->create(['name' => 'Employment']);
        $expenseParent = Category::factory()->for($user)->expense()->create(['name' => 'Food']);

        // Default type is expense — only expense categories in formCategories.
        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->assertViewHas('formCategories', fn ($cats) => $cats->contains('id', $expenseParent->id))
            ->assertViewHas('formCategories', fn ($cats) => $cats->doesntContain('id', $incomeParent->id));

        // Switch to income — only income categories.
        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('type', Transaction::TYPE_INCOME)
            ->assertViewHas('formCategories', fn ($cats) => $cats->contains('id', $incomeParent->id))
            ->assertViewHas('formCategories', fn ($cats) => $cats->doesntContain('id', $expenseParent->id));
    }

    public function test_save_fails_when_category_type_does_not_match_transaction_type(): void
    {
        $user = User::factory()->create();
        $incomeCategory = Category::factory()->for($user)->income()->create();

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('type', Transaction::TYPE_EXPENSE)
            ->set('amount', '50.00')
            ->set('date', '2024-06-15')
            ->set('category_id', $incomeCategory->id)
            ->call('save')
            ->assertHasErrors(['category_id']);
    }

    public function test_updated_type_clears_category_id(): void
    {
        $user = User::factory()->create();
        $expenseCategory = Category::factory()->for($user)->expense()->create();

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('type', Transaction::TYPE_EXPENSE)
            ->set('category_id', $expenseCategory->id)
            ->set('type', Transaction::TYPE_INCOME)
            ->assertSet('category_id', null);
    }

    public function test_render_falls_back_to_scalar_filter_when_parent_not_found_in_collection(): void
    {
        Carbon::setTestNow('2024-06-15');

        $user = User::factory()->create();

        // Set filterParentCategory to a non-existent ID so firstWhere returns null.
        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('filterParentCategory', 99999)
            ->assertViewHas('transactions');
    }

    public function test_mount_normalises_an_invalid_filter_type_from_the_query_string(): void
    {
        $user = User::factory()->create();

        Livewire::withQueryParams(['type' => 'invalid'])
            ->actingAs($user)
            ->test(TransactionManager::class)
            ->assertSet('filterType', null);
    }

    public function test_mount_keeps_a_valid_filter_type_from_the_query_string(): void
    {
        $user = User::factory()->create();

        Livewire::withQueryParams(['type' => Transaction::TYPE_INCOME])
            ->actingAs($user)
            ->test(TransactionManager::class)
            ->assertSet('filterType', Transaction::TYPE_INCOME);
    }

    public function test_updated_filter_type_normalises_an_invalid_value_to_null(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('filterType', 'invalid')
            ->assertSet('filterType', null);
    }

    public function test_updated_filter_type_keeps_a_valid_expense_value(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('filterType', Transaction::TYPE_EXPENSE)
            ->assertSet('filterType', Transaction::TYPE_EXPENSE);
    }

    public function test_search_finds_older_transactions_and_clear_restores_the_selected_month(): void
    {
        Carbon::setTestNow('2024-06-15');
        $user = User::factory()->create(['selected_month' => 6, 'selected_year' => 2024]);
        $older = Transaction::factory()->for($user)->create([
            'category_id' => null, 'description' => 'Vintage camera', 'date' => '2022-04-10',
        ]);
        $current = Transaction::factory()->for($user)->create([
            'category_id' => null, 'description' => 'June groceries', 'date' => '2024-06-10',
        ]);

        $testable = Livewire::actingAs($user)->test(TransactionManager::class);
        $testable->assertViewHas('transactions', fn ($items): bool => $items->pluck('id')->all() === [$current->id]);
        $testable->set('search', 'Vintage')
            ->assertSee('Searching all dates')
            ->assertSee('1 result')
            ->assertViewHas('transactions', fn ($items): bool => $items->pluck('id')->all() === [$older->id]);
        $testable->call('clearSearch')
            ->assertSet('search', '')
            ->assertDontSee('Searching all dates')
            ->assertViewHas('transactions', fn ($items): bool => $items->pluck('id')->all() === [$current->id]);
    }

    public function test_search_trims_whitespace_and_matches_within_descriptions(): void
    {
        $user = User::factory()->create(['selected_month' => 6, 'selected_year' => 2024]);
        $current = Transaction::factory()->for($user)->create([
            'category_id' => null, 'description' => 'June groceries', 'date' => '2024-06-10',
        ]);
        $older = Transaction::factory()->for($user)->create([
            'category_id' => null, 'description' => 'Morning coffee shop', 'date' => '2022-04-10',
        ]);

        $testable = Livewire::actingAs($user)->test(TransactionManager::class);
        $testable->set('search', '   ')
            ->assertDontSee('Searching all dates')
            ->assertViewHas('transactions', fn ($items): bool => $items->pluck('id')->all() === [$current->id]);
        $testable->set('search', '  coffee  ')
            ->assertSee('Searching all dates')
            ->assertViewHas('transactions', fn ($items): bool => $items->pluck('id')->all() === [$older->id]);
    }

    public function test_search_excludes_unmatched_categories(): void
    {
        $user = User::factory()->create();
        $food = Category::factory()->for($user)->expense()->create(['name' => 'Food']);
        $travel = Category::factory()->for($user)->expense()->create(['name' => 'Travel']);
        $match = Transaction::factory()->for($user)->create([
            'category_id' => $travel->id, 'type' => Transaction::TYPE_EXPENSE,
            'description' => 'Taxi', 'date' => '2022-01-01',
        ]);
        Transaction::factory()->for($user)->create([
            'category_id' => $food->id, 'type' => Transaction::TYPE_EXPENSE,
            'description' => 'Lunch', 'date' => '2022-01-02',
        ]);

        Livewire::actingAs($user)->test(TransactionManager::class)
            ->set('search', 'Travel')
            ->assertViewHas('transactions', fn ($items): bool => $items->total() === 1 && $items->first()->id === $match->id);
    }

    public function test_amount_search_accepts_whole_and_single_decimal_values_but_rejects_embedded_currency(): void
    {
        $user = User::factory()->create();
        $whole = Transaction::factory()->for($user)->create([
            'category_id' => null, 'amount' => '123.00', 'description' => 'Alpha', 'date' => '2022-01-01',
        ]);
        $decimal = Transaction::factory()->for($user)->create([
            'category_id' => null, 'amount' => '123.10', 'description' => 'Beta', 'date' => '2022-01-02',
        ]);

        $testable = Livewire::actingAs($user)->test(TransactionManager::class);
        $testable->set('search', '123')
            ->assertViewHas('transactions', fn ($items): bool => $items->total() === 1 && $items->first()->id === $whole->id);
        $testable->set('search', '123.1')
            ->assertViewHas('transactions', fn ($items): bool => $items->total() === 1 && $items->first()->id === $decimal->id);
        $testable->set('search', '££123.00')
            ->assertViewHas('transactions', fn ($items): bool => $items->total() === 0);
        $testable->set('search', '123.00£')
            ->assertViewHas('transactions', fn ($items): bool => $items->total() === 0);
    }

    public function test_search_resets_page_after_save_delete_and_clear(): void
    {
        $user = User::factory()->create(['selected_month' => 6, 'selected_year' => 2024]);
        $oldest = null;
        foreach (range(1, 21) as $day) {
            $transaction = Transaction::factory()->for($user)->create([
                'category_id' => null, 'type' => Transaction::TYPE_EXPENSE,
                'description' => 'Marker item', 'date' => sprintf('2024-01-%02d', $day),
            ]);
            $oldest ??= $transaction;
        }

        $testable = Livewire::actingAs($user)->test(TransactionManager::class);
        $testable->set('search', 'Marker')->call('nextPage');
        $testable->assertSet('paginators.page', 2);

        $testable->set('type', Transaction::TYPE_EXPENSE)
            ->set('amount', '1.00')
            ->set('date', '2024-06-15')
            ->set('description', 'Marker newest')
            ->call('save');
        $testable->assertHasNoErrors()->assertSet('paginators.page', 1);

        $testable->call('nextPage');
        $testable->assertSet('paginators.page', 2);
        $testable->call('confirmDelete', $oldest->id)->call('delete');
        $testable->assertSet('paginators.page', 1);

        $testable->call('nextPage');
        $testable->assertSet('paginators.page', 2);
        $testable->call('clearSearch');
        $testable->assertSet('paginators.page', 1)->assertSet('search', '');
    }

    public function test_search_is_scoped_to_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $own = Transaction::factory()->for($user)->create([
            'category_id' => null, 'description' => 'Private ledger entry', 'date' => '2021-01-01',
        ]);
        Transaction::factory()->for($other)->create([
            'category_id' => null, 'description' => 'Private ledger entry', 'date' => '2024-01-01',
        ]);

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('search', 'Private ledger entry')
            ->assertViewHas('transactions', fn ($items): bool => $items->total() === 1 && $items->first()->id === $own->id);
    }

    public function test_search_matches_parent_and_child_categories_and_combines_filters(): void
    {
        $user = User::factory()->create();
        $parent = Category::factory()->for($user)->expense()->create(['name' => 'Travel']);
        $child = Category::factory()->subcategoryOf($parent)->create(['name' => 'Rail']);
        $otherChild = Category::factory()->subcategoryOf($parent)->create(['name' => 'Flights']);
        $incomeCategory = Category::factory()->for($user)->income()->create(['name' => 'Travel refund']);
        $rail = Transaction::factory()->for($user)->create([
            'category_id' => $child->id, 'type' => Transaction::TYPE_EXPENSE,
            'description' => 'Ticket', 'date' => '2022-01-01',
        ]);
        $flight = Transaction::factory()->for($user)->create([
            'category_id' => $otherChild->id, 'type' => Transaction::TYPE_EXPENSE,
            'description' => 'Ticket', 'date' => '2022-02-01',
        ]);
        Transaction::factory()->for($user)->create([
            'category_id' => $incomeCategory->id, 'type' => Transaction::TYPE_INCOME,
            'description' => 'Ticket', 'date' => '2022-03-01',
        ]);

        $testable = Livewire::actingAs($user)->test(TransactionManager::class);
        $testable
            ->set('search', 'Travel')
            ->set('filterParentCategory', $parent->id)
            ->set('filterType', Transaction::TYPE_EXPENSE)
            ->assertViewHas('transactions', fn ($items): bool => $items->pluck('id')->all() === [$flight->id, $rail->id]);
        $testable->set('filterSubCategory', $child->id)
            ->assertViewHas('transactions', fn ($items): bool => $items->pluck('id')->all() === [$rail->id]);
        $testable->set('search', 'Rail')
            ->assertViewHas('transactions', fn ($items): bool => $items->pluck('id')->all() === [$rail->id]);
    }

    public function test_search_matches_an_exact_currency_amount(): void
    {
        $user = User::factory()->create();
        $match = Transaction::factory()->for($user)->create([
            'category_id' => null, 'amount' => '1234.50', 'description' => 'First', 'date' => '2022-01-01',
        ]);
        Transaction::factory()->for($user)->create([
            'category_id' => null, 'amount' => '1234.51', 'description' => 'Second', 'date' => '2022-01-02',
        ]);

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('search', '£1,234.50')
            ->assertViewHas('transactions', fn ($items): bool => $items->total() === 1 && $items->first()->id === $match->id);
    }

    public function test_search_and_category_filters_load_from_the_url_together(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Food']);
        $match = Transaction::factory()->for($user)->create([
            'category_id' => $category->id, 'type' => Transaction::TYPE_EXPENSE,
            'description' => 'Pasta', 'date' => '2020-01-01',
        ]);

        Livewire::withQueryParams(['search' => 'Pasta', 'category' => $category->id, 'type' => 'expense'])
            ->actingAs($user)
            ->test(TransactionManager::class)
            ->assertSet('search', 'Pasta')
            ->assertSet('filterParentCategory', $category->id)
            ->assertSee('Searching all dates')
            ->assertViewHas('transactions', fn ($items): bool => $items->total() === 1 && $items->first()->id === $match->id);
    }

    public function test_search_paginates_newest_first_and_resets_page_when_query_or_filters_change(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Food']);
        $otherCategory = Category::factory()->for($user)->expense()->create(['name' => 'Travel']);

        foreach (range(1, 21) as $day) {
            Transaction::factory()->for($user)->create([
                'category_id' => $category->id, 'type' => Transaction::TYPE_EXPENSE,
                'description' => 'Searchable item', 'date' => sprintf('2024-01-%02d', $day),
            ]);
        }

        $testable = Livewire::actingAs($user)->test(TransactionManager::class);
        $testable->set('search', 'Searchable')
            ->assertViewHas('transactions', fn ($items): bool => $items->total() === 21 && $items->count() === 20
                && $items->first()->date->toDateString() === '2024-01-21');
        $testable->call('nextPage')
            ->assertSet('paginators.page', 2)
            ->assertViewHas('transactions', fn ($items): bool => $items->count() === 1);

        $testable->set('search', 'item')
            ->assertSet('paginators.page', 1);
        $testable->call('nextPage')->set('filterParentCategory', $category->id)
            ->assertSet('paginators.page', 1);
        $testable->call('nextPage')->set('filterSubCategory', $otherCategory->id)
            ->assertSet('paginators.page', 1);
        $testable->call('nextPage')->set('filterType', Transaction::TYPE_INCOME)
            ->assertSet('paginators.page', 1);
        $testable->call('clearSearch')->assertSet('paginators.page', 1);
    }

    public function test_search_displays_recurring_series_once_and_delete_targets_the_series(): void
    {
        $user = User::factory()->create();
        $series = Transaction::factory()->for($user)->recurring()->create([
            'category_id' => null, 'description' => 'Recurring rent', 'date' => '2020-01-01',
        ]);

        $testable = Livewire::actingAs($user)->test(TransactionManager::class);
        $testable
            ->set('search', 'Recurring rent')
            ->assertViewHas('transactions', fn ($items): bool => $items->total() === 1 && $items->first()->id === $series->id)
            ->assertSee('Recurring series · Started 1 Jan 2020')
            ->assertSee('1 Jan 2020')
            ->assertSee('Edit series')->assertSee('Delete series')->assertSeeHtml('wire:click="confirmDelete(' . $series->id . ')"');
        $testable->call('confirmDelete', $series->id)
            ->assertSee('This permanently deletes the entire recurring series')
            ->assertDontSee('Delete this occurrence');
        $testable->call('delete', true);

        $this->assertDatabaseMissing('transactions', ['id' => $series->id]);
    }

    public function test_search_empty_state_mentions_active_filters(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('search', 'No matching entry')
            ->assertSee('No transactions match this search.')
            ->set('filterType', Transaction::TYPE_EXPENSE)
            ->assertSee('No transactions match this search and the active filters.')
            ->call('clearSearch')
            ->assertSee('No transactions found for this period with the active filters.');
    }
}
