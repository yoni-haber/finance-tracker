<?php

namespace Tests\Feature;

use App\Livewire\Transactions\TransactionManager;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TransactionManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_mount_sets_date_month_and_year_to_current(): void
    {
        Carbon::setTestNow('2024-06-15');

        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->assertSet('date', '2024-06-15')
            ->assertSet('month', 6)
            ->assertSet('year', 2024);
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
            ->set('frequency', null)
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
        $category = Category::factory()->for($user)->create();
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

    public function test_edit_throws_404_for_another_users_transaction(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherTransaction = Transaction::factory()->for($otherUser)->create([
            'category_id' => null,
            'is_recurring' => false,
            'frequency' => null,
        ]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->call('edit', $otherTransaction->id);
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
            ->call('delete', $transaction->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('transactions', ['id' => $transaction->id]);
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
            ->call('delete', $transaction->id)
            ->assertHasNoErrors();

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
            ->call('delete', $transaction->id, '2024-06-01')
            ->assertHasNoErrors();

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
            ->call('delete', $transaction->id, 'not-a-date')
            ->assertHasErrors('delete');

        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
        $this->assertDatabaseCount('transaction_exceptions', 0);
    }

    public function test_delete_throws_404_for_another_users_transaction(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherTransaction = Transaction::factory()->for($otherUser)->create([
            'category_id' => null,
            'is_recurring' => false,
            'frequency' => null,
        ]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->call('delete', $otherTransaction->id);
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
            ->set('frequency', null)
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
            ->assertViewHas('transactions', function ($transactions) {
                return $transactions->count() === 1
                    && $transactions->every(fn ($t) => $t->type === Transaction::TYPE_INCOME);
            });
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
            ->assertViewHas('transactions', function ($transactions) use ($category) {
                return $transactions->count() === 1
                    && $transactions->every(fn ($t) => $t->category_id === $category->id);
            });
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
            ->assertViewHas('transactions', function ($transactions) use ($sub) {
                return $transactions->count() === 1
                    && $transactions->every(fn ($t) => $t->category_id === $sub->id);
            });
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
        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->set('filterParentCategory', $parent->id)
            ->assertViewHas('transactions', fn ($t) => $t->count() === 2)
            // Drilling into sub1 narrows to just that subcategory.
            ->set('filterSubCategory', $sub1->id)
            ->assertViewHas('transactions', function ($transactions) use ($sub1) {
                return $transactions->count() === 1
                    && $transactions->every(fn ($t) => $t->category_id === $sub1->id);
            });
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
            ->set('filterParentCategory', null)
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
}
