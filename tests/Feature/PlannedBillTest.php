<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Bills\BillManager;
use App\Livewire\Dashboard;
use App\Livewire\Transactions\TransactionManager;
use App\Models\Category;
use App\Models\PlannedBill;
use App\Models\PlannedBillPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Support\PlannedBillReport;
use App\Support\TransactionReport;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class PlannedBillTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_render_does_not_expose_bills_or_categories(): void
    {
        $owner = User::factory()->create();
        $this->bill($owner, '2026-10-12', name: 'Private bill');
        Category::factory()->for($owner)->expense()->create();

        $component = Livewire::test(BillManager::class)->assertDontSee('Private bill');
        $component->assertViewHas('bills', fn ($bills): bool => $bills->isEmpty());
        $component->assertViewHas('categories', fn ($categories): bool => $categories->isEmpty());
    }

    public function test_bill_list_eager_loads_categories_without_lazy_queries(): void
    {
        $owner = User::factory()->create();
        $category = Category::factory()->for($owner)->expense()->create(['name' => 'Transport']);
        foreach (['Insurance', 'Car tax'] as $name) {
            $this->bill($owner, '2026-10-12', name: $name)->update(['category_id' => $category->id]);
        }

        $preventedLazyLoading = Model::preventsLazyLoading();
        Model::preventLazyLoading();
        try {
            Livewire::actingAs($owner)->test(BillManager::class)
                ->assertSee('Insurance')->assertSee('Car tax')->assertSee('(Transport)');
        } finally {
            Model::preventLazyLoading($preventedLazyLoading);
        }
    }

    public function test_opening_and_editing_a_bill_reset_previous_source_and_validation_state(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();
        $bill = $this->bill($user, '2026-10-12', 'quarterly', name: 'Water rates');
        $bill->update(['category_id' => $category->id, 'note' => 'Account reference']);
        $expense = $this->expense($user, '2025-10-12', 'Insurance renewal', '500.00');

        $component = Livewire::actingAs($user)->test(BillManager::class)
            ->set('sourceSearch', 'Insurance')
            ->call('useTransaction', $expense->id)
            ->set('estimatedAmount', '')->call('save')->assertHasErrors(['estimatedAmount'])
            ->call('edit', $bill->id)->assertHasNoErrors()
            ->assertDispatched('open-bill-modal')
            ->assertSet('billId', $bill->id)->assertSet('name', 'Water rates')
            ->assertSet('note', 'Account reference')->assertSet('categoryId', $category->id)
            ->assertSet('estimatedAmount', '600.00')->assertSet('nextDueDate', '2026-10-12')
            ->assertSet('frequency', 'quarterly')->assertSet('sourceSearch', '')
            ->assertSet('sourceTransactionId', null);

        $component->set('name', '')->call('save')->assertHasErrors(['name'])
            ->call('openModal')->assertHasNoErrors()->assertDispatched('open-bill-modal')
            ->assertSet('billId', null)->assertSet('name', '')->assertSet('note', '')
            ->assertSet('categoryId', null)->assertSet('estimatedAmount', '')
            ->assertSet('nextDueDate', now()->toDateString())->assertSet('frequency', 'yearly');
    }

    public function test_saving_trims_before_length_validation_and_resets_the_form_with_feedback(): void
    {
        $user = User::factory()->create();
        $expense = $this->expense($user, '2025-10-12', 'Insurance renewal', '500.00');
        $name = str_repeat('x', 160);

        Livewire::actingAs($user)->test(BillManager::class)
            ->set('sourceSearch', 'Insurance')->call('useTransaction', $expense->id)
            ->set('name', '  ' . $name . '  ')->set('note', '  Reference 123  ')
            ->set('frequency', 'quarterly')
            ->call('save')->assertHasNoErrors()
            ->assertSee('Bill saved.')->assertDispatched('close-bill-modal')
            ->assertSet('billId', null)->assertSet('name', '')->assertSet('note', '')
            ->assertSet('categoryId', null)->assertSet('estimatedAmount', '')
            ->assertSet('nextDueDate', now()->toDateString())->assertSet('frequency', 'yearly')
            ->assertSet('sourceSearch', '')->assertSet('sourceTransactionId', null);

        $bill = PlannedBill::forUser($user->id)->sole();
        $this->assertSame($name, $bill->name);
        $this->assertSame('Reference 123', $bill->note);
        $this->assertSame('quarterly', $bill->frequency);
    }

    public function test_missing_required_bill_fields_have_validation_errors_without_saving(): void
    {
        $user = User::factory()->create();
        foreach (['estimatedAmount', 'nextDueDate', 'frequency'] as $field) {
            Livewire::actingAs($user)->test(BillManager::class)
                ->set('name', 'Insurance')->set('estimatedAmount', '600.00')
                ->set('nextDueDate', '2026-10-12')->set('frequency', 'yearly')
                ->set($field, '')->call('save')->assertHasErrors([$field => 'required']);
        }
        $this->assertDatabaseCount('planned_bills', 0);
    }

    public function test_prefill_uses_category_or_bill_fallback_and_clears_a_previous_source_error(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Transport']);
        $categorised = $this->expense($user, '2025-10-12', '', '500.00');
        $categorised->update(['category_id' => $category->id]);
        $uncategorised = $this->expense($user, '2025-10-13', '', '75.00');
        $linked = $this->expense($user, '2025-10-14', 'Already paid', '100.00');
        $this->bill($user, '2026-10-14')->payments()->create([
            'user_id' => $user->id, 'transaction_id' => $linked->id, 'expected_date' => '2025-10-14',
        ]);

        Livewire::actingAs($user)->test(BillManager::class)
            ->call('useTransaction', $linked->id)->assertHasErrors(['sourceTransactionId'])
            ->call('useTransaction', $categorised->id)->assertHasNoErrors()
            ->assertSet('name', 'Transport')->assertSet('categoryId', $category->id)
            ->assertSet('estimatedAmount', '500.00')
            ->call('useTransaction', $uncategorised->id)
            ->assertSet('name', 'Bill')->assertSet('categoryId', null);
    }

    public function test_changing_a_prefilled_schedule_recalculates_quarterly_and_yearly_dates(): void
    {
        $user = User::factory()->create();
        $expense = $this->expense($user, '2024-01-31', 'Water rates', '125.00');

        Livewire::actingAs($user)->test(BillManager::class)
            ->call('useTransaction', $expense->id)->assertSet('nextDueDate', '2025-01-31')
            ->set('frequency', 'quarterly')->assertSet('nextDueDate', '2024-04-30')
            ->set('frequency', 'yearly')->assertSet('nextDueDate', '2025-01-31')
            ->set('frequency', 'monthly')->assertSet('nextDueDate', '2025-01-31');
    }

    public function test_payment_dialog_resets_errors_and_pending_selection_and_requires_an_expense(): void
    {
        $user = User::factory()->create();
        $bill = $this->bill($user, '2026-10-12');
        $expense = $this->expense($user, '2026-10-11', 'Insurance', '500.00');

        Livewire::actingAs($user)->test(BillManager::class)
            ->call('save')->assertHasErrors(['name', 'estimatedAmount', 'nextDueDate'])
            ->set('paymentSearch', 'Old search')->set('paymentTransactionId', $expense->id)
            ->call('openPaymentModal', $bill->id)->assertHasNoErrors()
            ->assertSet('paymentBillId', $bill->id)->assertSet('paymentTransactionId', null)
            ->assertSet('paymentSearch', '')->assertDispatched('open-bill-payment-modal')
            ->call('linkPayment')->assertHasErrors(['paymentTransactionId' => 'required']);

        $this->assertDatabaseCount('planned_bill_payments', 0);
        $this->assertSame('2026-10-12', $bill->refresh()->next_due_date->toDateString());
    }

    public function test_selecting_an_available_payment_clears_a_previous_selection_error(): void
    {
        $user = User::factory()->create();
        $bill = $this->bill($user, '2026-10-12');
        $linked = $this->expense($user, '2025-10-12', 'Prior payment', '500.00');
        $available = $this->expense($user, '2026-10-11', 'New payment', '600.00');
        $bill->payments()->create(['user_id' => $user->id, 'transaction_id' => $linked->id, 'expected_date' => '2025-10-12']);

        Livewire::actingAs($user)->test(BillManager::class)
            ->call('openPaymentModal', $bill->id)
            ->call('selectPayment', $linked->id)->assertHasErrors(['paymentTransactionId'])
            ->call('selectPayment', $available->id)->assertHasNoErrors()
            ->assertSet('paymentTransactionId', $available->id);
    }

    public function test_payment_and_removal_dialogs_reject_foreign_or_missing_bills(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $bill = $this->bill($other, '2026-10-12');
        foreach (['openPaymentModal', 'confirmDelete'] as $action) {
            Livewire::actingAs($owner)->test(BillManager::class)->call($action, $bill->id)->assertNotFound();
            Livewire::actingAs($other)->test(BillManager::class)->call($action, $bill->id + 1)->assertNotFound();
        }
        $this->assertDatabaseHas('planned_bills', ['id' => $bill->id]);
    }

    public function test_unlink_without_a_selected_payment_is_a_successful_no_op(): void
    {
        $user = User::factory()->create();
        $bill = $this->bill($user, '2026-10-12');

        Livewire::actingAs($user)->test(BillManager::class)
            ->call('unlinkPayment')->assertStatus(200);

        $this->assertDatabaseHas('planned_bills', ['id' => $bill->id, 'next_due_date' => '2026-10-12']);
    }

    public function test_bill_search_matches_within_descriptions_and_categories_and_trims_spaces(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Annual home insurance']);
        $byDescription = $this->expense($user, '2026-09-12', 'Car insurance renewal', '500.00');
        $byCategory = $this->expense($user, '2026-09-13', 'Policy reference', '600.00');
        $byCategory->update(['category_id' => $category->id]);
        $unmatched = $this->expense($user, '2026-09-14', 'Water rates', '100.00');
        $unmatched->update(['category_id' => Category::factory()->for($user)->expense()->create(['name' => 'Utilities'])->id]);

        $component = Livewire::actingAs($user)->test(BillManager::class);
        foreach (['sourceSearch' => 'sourceTransactions', 'paymentSearch' => 'paymentTransactions'] as $field => $viewKey) {
            $component->set($field, '  insurance  ');
            $component->assertViewHas($viewKey, fn ($rows): bool => $rows->pluck('id')->all() === [$byCategory->id, $byDescription->id]);
            $component->set($field, '   ');
            $component->assertViewHas($viewKey, fn ($rows): bool => $rows->pluck('id')->all() === [$unmatched->id, $byCategory->id, $byDescription->id]);
        }
    }

    public function test_bill_search_returns_twenty_latest_available_recorded_expenses(): void
    {
        $user = User::factory()->create();
        $expenses = Transaction::factory()->count(21)->for($user)->create([
            'category_id' => null, 'type' => Transaction::TYPE_EXPENSE,
            'is_recurring' => false, 'date' => '2026-09-12',
        ]);
        $linked = $this->expense($user, '2026-09-30', 'Linked', '100.00');
        $this->bill($user, '2027-09-30')->payments()->create([
            'user_id' => $user->id, 'transaction_id' => $linked->id, 'expected_date' => '2026-09-30',
        ]);
        $this->expense(User::factory()->create(), '2026-09-30', 'Private expense', '100.00');
        Transaction::factory()->for($user)->create(['category_id' => null, 'type' => Transaction::TYPE_INCOME, 'date' => '2026-09-30']);
        Transaction::factory()->for($user)->create(['category_id' => null, 'type' => Transaction::TYPE_EXPENSE, 'is_recurring' => true, 'date' => '2026-09-30']);
        $expectedIds = $expenses->sortByDesc('id')->take(20)->pluck('id')->all();

        $component = Livewire::actingAs($user)->test(BillManager::class);
        $component->assertViewHas('sourceTransactions', fn ($rows): bool => $rows->pluck('id')->all() === $expectedIds);
        $component->assertViewHas('paymentTransactions', fn ($rows): bool => $rows->pluck('id')->all() === $expectedIds);
    }

    public function test_bill_report_orders_by_date_then_name_and_loads_categories(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();
        foreach ([['2026-10-12', 'Insurance renewal'], ['2026-10-13', 'A later bill'], ['2026-10-12', 'Insurance'], ['2026-10-11', 'Z earlier bill']] as [$date, $name]) {
            $this->bill($user, $date, name: $name)->update(['category_id' => $category->id]);
        }

        $rows = PlannedBillReport::forRange($user->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
        $this->assertSame(['Z earlier bill', 'Insurance', 'Insurance renewal', 'A later bill'], $rows->pluck('bill.name')->all());
        $this->assertSame([0, 1, 2, 3], $rows->keys()->all());
        foreach ($rows as $row) {
            $this->assertTrue($row['bill']->relationLoaded('category'));
            $this->assertInstanceOf(Category::class, $row['bill']->category);
            $this->assertTrue($row['bill']->category->is($category));
        }
    }

    public function test_linked_expenses_allow_normal_edits_but_cannot_be_changed_to_income(): void
    {
        $user = User::factory()->create();
        $bill = $this->bill($user, '2026-10-12');
        $expense = $this->expense($user, '2025-10-12', 'Insurance', '500.00');
        $payment = $bill->payments()->create(['user_id' => $user->id, 'transaction_id' => $expense->id, 'expected_date' => '2025-10-12']);

        Livewire::actingAs($user)->test(TransactionManager::class)
            ->call('edit', $expense->id)->set('description', 'Corrected insurance receipt')
            ->set('amount', '510.25')->call('save')->assertHasNoErrors();
        $this->assertSame('Corrected insurance receipt', $expense->refresh()->description);
        $this->assertSame('510.25', $expense->amount);
        $this->assertDatabaseHas('planned_bill_payments', ['id' => $payment->id, 'transaction_id' => $expense->id]);

        Livewire::actingAs($user)->test(TransactionManager::class)
            ->call('edit', $expense->id)->set('type', Transaction::TYPE_INCOME)
            ->call('save')->assertHasErrors(['save']);
        $this->assertSame(Transaction::TYPE_EXPENSE, $expense->refresh()->type);
    }

    public function test_bill_crud_and_expense_category_validation(): void
    {
        $user = User::factory()->create();
        $expense = Category::factory()->for($user)->create(['type' => Category::TYPE_EXPENSE]);
        $income = Category::factory()->for($user)->create(['type' => Category::TYPE_INCOME]);

        Livewire::actingAs($user)->test(BillManager::class)
            ->set('name', 'Car insurance')
            ->set('categoryId', $income->id)
            ->set('estimatedAmount', '620.00')
            ->set('nextDueDate', '2026-10-12')
            ->set('frequency', 'yearly')
            ->call('save')->assertHasErrors(['categoryId'])
            ->set('categoryId', $expense->id)
            ->call('save')->assertHasNoErrors();

        $plannedBill = PlannedBill::forUser($user->id)->sole();
        $this->assertSame(12, $plannedBill->anchor_day);
        $this->assertSame('620.00', $plannedBill->estimated_amount);

        Livewire::actingAs($user)->test(BillManager::class)
            ->call('edit', $plannedBill->id)
            ->set('estimatedAmount', '680.00')
            ->set('nextDueDate', '2026-10-14')
            ->call('save')->assertHasNoErrors();

        $this->assertSame(14, $plannedBill->refresh()->anchor_day);
        $this->assertSame('680.00', $plannedBill->estimated_amount);

        Livewire::actingAs($user)->test(BillManager::class)
            ->call('confirmDelete', $plannedBill->id)
            ->call('delete');
        $this->assertDatabaseMissing('planned_bills', ['id' => $plannedBill->id]);
    }

    public function test_editing_preserves_parent_subcategory_and_uncategorised_selections(): void
    {
        $user = User::factory()->create();
        $parent = Category::factory()->for($user)->create(['type' => Category::TYPE_EXPENSE, 'name' => 'Transport']);
        $child = Category::factory()->for($user)->create(['type' => Category::TYPE_EXPENSE, 'parent_id' => $parent->id, 'name' => 'Fuel']);
        $bill = $this->bill($user, '2026-10-12');
        $testable = Livewire::actingAs($user)->test(BillManager::class)
            ->assertSeeHtml('<option value="' . $parent->id . '">Transport</option>')
            ->assertSeeHtml('<option value="' . $child->id . '">Fuel</option>');

        foreach ([$parent->id, $child->id, null] as $categoryId) {
            $bill->update(['category_id' => $categoryId]);
            $testable->call('edit', $bill->id)
                ->assertSet('categoryId', $categoryId)
                ->call('save')->assertHasNoErrors();
            $this->assertSame($categoryId, $bill->refresh()->category_id);
        }
    }

    public function test_transaction_prefill_includes_its_parent_category_even_when_it_has_children(): void
    {
        $user = User::factory()->create();
        $parent = Category::factory()->for($user)->create(['type' => Category::TYPE_EXPENSE, 'name' => 'Transport']);
        Category::factory()->for($user)->create(['type' => Category::TYPE_EXPENSE, 'parent_id' => $parent->id, 'name' => 'Fuel']);
        $transaction = $this->expense($user, '2025-10-12', 'Insurance renewal', '640.00');
        $transaction->update(['category_id' => $parent->id]);

        Livewire::actingAs($user)->test(BillManager::class)
            ->call('useTransaction', $transaction->id)
            ->assertSet('categoryId', $parent->id)
            ->assertSeeHtml('<option value="' . $parent->id . '">Transport</option>')
            ->call('save')->assertHasNoErrors();

        $this->assertSame($parent->id, PlannedBill::forUser($user->id)->sole()->category_id);
    }

    public function test_bill_can_start_from_a_previous_expense_and_keep_its_anchor_day(): void
    {
        $user = User::factory()->create();
        $transaction = $this->expense($user, '2024-02-29', 'Car tax', '175.00');

        Livewire::actingAs($user)->test(BillManager::class)
            ->call('useTransaction', $transaction->id)
            ->assertSet('name', 'Car tax')
            ->assertSet('estimatedAmount', '175.00')
            ->assertSet('nextDueDate', '2025-02-28')
            ->call('save')->assertHasNoErrors();

        $plannedBill = PlannedBill::forUser($user->id)->sole();
        $this->assertSame(29, $plannedBill->anchor_day);
        $this->assertSame('2028-02-29', $plannedBill->dateAfter(Carbon::parse('2027-02-28'))->toDateString());
        $this->assertDatabaseHas('planned_bill_payments', [
            'planned_bill_id' => $plannedBill->id,
            'transaction_id' => $transaction->id,
            'expected_date' => '2024-02-29',
        ]);
    }

    public function test_optional_notes_can_be_saved_edited_cleared_and_reset(): void
    {
        $user = User::factory()->create();
        $bill = $this->bill($user, '2026-10-12');
        $component = Livewire::actingAs($user)->test(BillManager::class)
            ->call('edit', $bill->id)
            ->assertSet('note', '')
            ->set('note', "  Renewal reference ABC123\nCompare quotes  ")
            ->call('save')->assertHasNoErrors();

        $this->assertSame("Renewal reference ABC123\nCompare quotes", $bill->refresh()->note);
        $component->assertSee('Renewal reference ABC123')
            ->call('edit', $bill->id)
            ->assertSet('note', "Renewal reference ABC123\nCompare quotes")
            ->set('note', str_repeat('n', 2001))
            ->call('save')->assertHasErrors(['note'])
            ->set('note', '0')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('0', $bill->refresh()->note);

        $component->call('edit', $bill->id)->set('note', '   ')->call('save')->assertHasNoErrors();
        $this->assertNull($bill->refresh()->note);
        $component->set('note', 'Unsaved note')->call('openModal')->assertSet('note', '');
    }

    public function test_payment_search_shows_selectable_expenses_and_keeps_the_selection_when_search_changes(): void
    {
        $user = User::factory()->create();
        $bill = $this->bill($user, '2026-10-12');
        $expense = $this->expense($user, '2026-10-11', 'Insurance renewal', '95.00');
        $this->expense($user, '2026-10-09', 'Fuel station', '60.00');
        $transaction = $this->expense(User::factory()->create(), '2026-10-11', 'Insurance private', '80.00');

        $testable = Livewire::actingAs($user)->test(BillManager::class)
            ->call('openPaymentModal', $bill->id)
            ->set('paymentSearch', 'Insurance');
        $testable->assertViewHas('paymentTransactions', fn ($rows): bool => $rows->pluck('id')->all() === [$expense->id]);

        $testable->assertDontSeeHtml('id="bill-payment-transaction"')
            ->call('selectPayment', $expense->id)
            ->assertSet('paymentTransactionId', $expense->id)
            ->assertSee('Selected:')
            ->set('paymentSearch', 'Fuel')
            ->assertViewHas('paymentTransactions', fn ($rows): bool => $rows->count() === 1 && $rows->first()->description === 'Fuel station');

        $testable->assertSet('paymentTransactionId', $expense->id)
            ->set('paymentSearch', 'No matching expense')
            ->assertViewHas('paymentTransactions', fn ($rows): bool => $rows->isEmpty());

        $testable->assertSee('No available recorded expenses found.')
            ->assertSet('paymentTransactionId', $expense->id)
            ->call('linkPayment')->assertHasNoErrors();

        Livewire::actingAs($user)->test(BillManager::class)
            ->call('selectPayment', $expense->id)->assertHasErrors(['paymentTransactionId']);
        Livewire::actingAs($user)->test(BillManager::class)
            ->call('selectPayment', $transaction->id)->assertNotFound();
    }

    public function test_unlink_requires_a_confirmation_and_is_scoped_to_the_latest_own_payment(): void
    {
        $owner = User::factory()->create();
        $bill = $this->bill($owner, '2027-10-12');
        $plannedBillPayment = $bill->payments()->create([
            'user_id' => $owner->id,
            'expected_date' => '2025-10-12',
            'transaction_id' => $this->expense($owner, '2025-10-12', 'Prior insurance', '600.00')->id,
        ]);
        $latest = $bill->payments()->create([
            'user_id' => $owner->id,
            'expected_date' => '2026-10-12',
            'transaction_id' => $this->expense($owner, '2026-10-12', 'Latest insurance', '600.00')->id,
        ]);
        Livewire::actingAs(User::factory()->create())->test(BillManager::class)
            ->call('confirmUnlinkPayment', $latest->id)->assertNotFound();
        Livewire::actingAs($owner)->test(BillManager::class)->call('unlinkPayment');
        $this->assertDatabaseHas('planned_bill_payments', ['id' => $latest->id]);
        Livewire::actingAs($owner)->test(BillManager::class)
            ->call('confirmUnlinkPayment', $plannedBillPayment->id)->call('unlinkPayment')
            ->assertSee('Only the latest payment can be unlinked.');
        $this->assertDatabaseHas('planned_bill_payments', ['id' => $plannedBillPayment->id]);
        $this->assertSame('2027-10-12', $bill->refresh()->next_due_date->toDateString());

        Livewire::actingAs($owner)->test(BillManager::class)
            ->assertSeeHtml('aria-label="Planned bills"')
            ->assertDontSee('Correct link')
            ->assertDontSeeHtml('wire:confirm=')
            ->assertDontSeeHtml('placeholder="Car insurance"');
    }

    public function test_payment_history_is_a_scoped_dialog_with_date_amount_and_transaction_links(): void
    {
        $owner = User::factory()->create();
        $category = Category::factory()->for($owner)->create(['type' => Category::TYPE_EXPENSE, 'name' => 'Transport']);
        $bill = $this->bill($owner, '2027-10-12', name: 'Insurance');
        $bill->update(['category_id' => $category->id]);

        $transaction = $this->expense($owner, '2026-10-11', 'Receipt details not needed in history', '610.25');
        $bill->payments()->create([
            'user_id' => $owner->id,
            'expected_date' => '2026-10-12',
            'transaction_id' => $transaction->id,
        ]);

        $component = Livewire::actingAs($owner)->test(BillManager::class)
            ->assertSee('(Transport)')
            ->call('openHistory', $bill->id)
            ->assertSet('historyBillId', $bill->id)
            ->assertDispatched('open-bill-history-modal');
        $component->assertViewHas('historyBill', fn ($history): bool => $history->id === $bill->id);

        $component->assertSee('11 Oct 2026')
            ->assertSee('£610.25')
            ->assertSeeHtml(route('transactions', ['transaction' => $transaction->id]))
            ->assertDontSee('Receipt details not needed in history')
            ->assertDontSee('Bill date')
            ->assertSee('Unlink payment')
            ->call('confirmUnlinkPayment', $bill->payments()->sole()->id)
            ->assertDispatched('close-bill-history-modal');

        Livewire::actingAs(User::factory()->create())->test(BillManager::class)
            ->call('openHistory', $bill->id)->assertNotFound();
    }

    public function test_an_empty_source_search_preserves_the_new_bill_form(): void
    {
        $owner = User::factory()->create();
        $this->expense($owner, '2026-10-11', 'Insurance receipt', '610.25');
        $testable = Livewire::actingAs($owner)->test(BillManager::class)
            ->call('openModal')
            ->set('name', 'Home renewal')
            ->set('note', 'Reference ABC123')
            ->set('sourceSearch', 'No matching expense');
        $testable->assertViewHas('sourceTransactions', fn ($rows): bool => $rows->isEmpty());

        $testable->assertSee('No available recorded expenses found.')
            ->assertSee('Save bill')
            ->assertSet('name', 'Home renewal')
            ->assertSet('note', 'Reference ABC123');
    }

    public function test_manually_changing_the_prefilled_due_date_uses_the_new_day_as_anchor(): void
    {
        $user = User::factory()->create();
        $transaction = $this->expense($user, '2024-02-29', 'Car tax', '175.00');

        Livewire::actingAs($user)->test(BillManager::class)
            ->call('useTransaction', $transaction->id)
            ->set('nextDueDate', '2025-03-15')
            ->call('save')->assertHasNoErrors();

        $plannedBill = PlannedBill::forUser($user->id)->sole();
        $this->assertSame(15, $plannedBill->anchor_day);
        $this->assertSame('2026-03-15', $plannedBill->dateAfter($plannedBill->next_due_date)->toDateString());
    }

    public function test_blank_names_and_reusing_a_linked_source_are_rejected(): void
    {
        $user = User::factory()->create();
        $transaction = $this->expense($user, '2025-06-10', 'Home insurance', '310.00');

        Livewire::actingAs($user)->test(BillManager::class)
            ->set('name', '   ')
            ->set('estimatedAmount', '310.00')
            ->set('nextDueDate', '2026-06-10')
            ->call('save')->assertHasErrors(['name']);

        Livewire::actingAs($user)->test(BillManager::class)
            ->call('useTransaction', $transaction->id)
            ->call('save')->assertHasNoErrors();
        Livewire::actingAs($user)->test(BillManager::class)
            ->call('useTransaction', $transaction->id)
            ->assertHasErrors(['sourceTransactionId']);
        $this->assertSame(1, PlannedBill::forUser($user->id)->count());
    }

    public function test_linking_and_correcting_a_payment_advances_and_restores_the_due_date(): void
    {
        $user = User::factory()->create();
        $bill = $this->bill($user, '2026-11-30', 'quarterly', 30);
        $transaction = $this->expense($user, '2026-11-27', 'Water rates', '151.25');

        Livewire::actingAs($user)->test(BillManager::class)
            ->call('openPaymentModal', $bill->id)
            ->set('paymentTransactionId', $transaction->id)
            ->call('linkPayment')->assertHasNoErrors()
            ->assertSet('paymentBillId', null)->assertSet('paymentTransactionId', null)
            ->assertSee('Payment linked. The next estimate now uses the actual amount.')
            ->assertDispatched('close-bill-payment-modal');

        $this->assertSame('2027-02-28', $bill->refresh()->next_due_date->toDateString());
        $this->assertSame('151.25', $bill->estimated_amount);
        $link = PlannedBillPayment::where('transaction_id', $transaction->id)->sole();
        $this->assertSame('2026-11-30', $link->expected_date->toDateString());
        $this->assertSame('600.00', $link->previous_estimated_amount);
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);

        Livewire::actingAs($user)->test(BillManager::class)
            ->call('confirmUnlinkPayment', $link->id)
            ->assertSet('unlinkingDescription', 'Water rates')
            ->assertDispatched('open-unlink-bill-payment-modal')
            ->call('unlinkPayment')
            ->assertSet('unlinkingPaymentId', null)
            ->assertSet('unlinkingDescription', '')
            ->assertSee('Payment unlinked.')
            ->assertDispatched('close-unlink-bill-payment-modal');
        $this->assertSame('2026-11-30', $bill->refresh()->next_due_date->toDateString());
        $this->assertSame('600.00', $bill->estimated_amount);
        $this->assertDatabaseMissing('planned_bill_payments', ['id' => $link->id]);
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);

        $replacement = $this->expense($user, '2026-11-29', 'Water rates corrected', '154.00');
        Livewire::actingAs($user)->test(BillManager::class)
            ->call('openPaymentModal', $bill->id)
            ->set('paymentTransactionId', $replacement->id)
            ->call('linkPayment')->assertHasNoErrors();
        $this->assertSame('154.00', $bill->refresh()->estimated_amount);
    }

    public function test_payment_cannot_be_reused_and_removing_a_bill_keeps_the_transaction(): void
    {
        $user = User::factory()->create();
        $plannedBill = $this->bill($user, '2026-10-10', name: 'Insurance');
        $second = $this->bill($user, '2026-10-15', name: 'Tax');
        $transaction = $this->expense($user, '2026-10-09', 'Insurance payment', '480.00');

        Livewire::actingAs($user)->test(BillManager::class)
            ->call('openPaymentModal', $plannedBill->id)
            ->set('paymentTransactionId', $transaction->id)
            ->call('linkPayment')->assertHasNoErrors();
        Livewire::actingAs($user)->test(BillManager::class)
            ->call('openPaymentModal', $second->id)
            ->set('paymentTransactionId', $transaction->id)
            ->call('linkPayment')->assertHasErrors(['paymentTransactionId']);

        Livewire::actingAs($user)->test(BillManager::class)
            ->call('confirmDelete', $plannedBill->id)
            ->assertDispatched('open-delete-bill-modal')
            ->call('delete')
            ->assertSet('deletingBillId', null)
            ->assertSee('Bill removed. Recorded transactions were kept.')
            ->assertDispatched('close-delete-bill-modal');
        $this->assertDatabaseMissing('planned_bills', ['id' => $plannedBill->id]);
        $this->assertDatabaseMissing('planned_bill_payments', ['transaction_id' => $transaction->id]);
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
    }

    public function test_due_date_cannot_move_before_the_last_linked_payment(): void
    {
        $user = User::factory()->create();
        $bill = $this->bill($user, '2027-10-10');
        $transaction = $this->expense($user, '2026-10-09', 'Insurance payment', '480.00');
        $bill->payments()->create([
            'user_id' => $user->id,
            'expected_date' => '2026-10-10',
            'transaction_id' => $transaction->id,
        ]);

        Livewire::actingAs($user)->test(BillManager::class)
            ->call('edit', $bill->id)
            ->set('nextDueDate', '2026-10-10')
            ->call('save')->assertHasErrors(['nextDueDate']);
        $this->assertSame('2027-10-10', $bill->refresh()->next_due_date->toDateString());
    }

    public function test_category_used_by_a_bill_cannot_be_changed_to_income(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create(['type' => Category::TYPE_EXPENSE]);
        $bill = $this->bill($user, '2026-10-10');
        $bill->update(['category_id' => $category->id]);

        $this->expectException(DomainException::class);
        $category->update(['type' => Category::TYPE_INCOME]);
    }

    public function test_user_cannot_access_other_users_bills_or_link_their_transactions(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $bill = $this->bill($owner, '2026-10-10');
        $transaction = $this->expense($other, '2026-10-10', 'Other payment', '50.00');

        Livewire::actingAs($other)->test(BillManager::class)
            ->call('edit', $bill->id)->assertNotFound();

        Livewire::actingAs($owner)->test(BillManager::class)
            ->call('openPaymentModal', $bill->id)
            ->set('paymentTransactionId', $transaction->id)
            ->call('linkPayment')->assertNotFound();

        $this->assertDatabaseCount('planned_bill_payments', 0);
    }

    public function test_dashboard_lists_three_months_from_the_selected_period_at_the_bottom(): void
    {
        Carbon::setTestNow('2026-09-30');
        try {
            $user = User::factory()->create(['selected_month' => 12, 'selected_year' => 2026]);
            $this->bill($user, '2026-12-12', name: 'December car insurance');
            $this->bill($user, '2027-01-08', name: 'January home insurance');
            $this->bill($user, '2027-02-02', name: 'February MOT');
            $this->bill($user, '2027-03-02', name: 'March service');

            $component = Livewire::actingAs($user)->test(Dashboard::class)
                ->assertSee('December 2026')
                ->assertSee('January 2027')
                ->assertSee('December car insurance')
                ->assertSee('January home insurance')
                ->assertSee('February 2027')
                ->assertSee('February MOT')
                ->assertDontSee('March service');
            $component->assertSeeInOrder(['Cash flow trend', 'Where spending went', 'Planned bills', 'December car insurance', 'January home insurance', 'February MOT']);

            $component->dispatch('period-changed', month: 1, year: 2027)
                ->assertSee('January home insurance')
                ->assertSee('February MOT')
                ->assertSee('March service')
                ->assertDontSee('December car insurance');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_bill_dates_and_paid_history_do_not_change_financial_totals_or_show_due_statuses(): void
    {
        Carbon::setTestNow('2026-10-15');
        try {
            $user = User::factory()->create(['selected_month' => 10, 'selected_year' => 2026]);
            $overdue = $this->bill($user, '2026-10-10', name: 'Overdue insurance');
            $this->bill($user, '2026-10-20', name: 'Expected tax');
            $paid = $this->bill($user, '2026-10-12', name: 'Paid service');
            $transaction = $this->expense($user, '2026-10-11', 'Service payment', '95.00');
            $paid->payments()->create([
                'user_id' => $user->id,
                'expected_date' => '2026-10-12',
                'transaction_id' => $transaction->id,
            ]);
            $paid->update(['next_due_date' => '2027-10-12']);

            $rows = PlannedBillReport::forRange($user->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
            $overdueRow = $rows->firstWhere('bill.id', $overdue->id);
            $expectedRow = $rows->firstWhere('bill.name', 'Expected tax');
            $paidRow = $rows->firstWhere('bill.name', 'Paid service');
            $this->assertNotNull($overdueRow);
            $this->assertNotNull($expectedRow);
            $this->assertNotNull($paidRow);
            $this->assertNull($overdueRow['payment']);
            $this->assertNull($expectedRow['payment']);
            $this->assertInstanceOf(PlannedBillPayment::class, $paidRow['payment']);
            $this->assertSame($transaction->id, $paidRow['payment']->transaction_id);
            $this->assertCount(1, TransactionReport::projectedForMonth($user->id, 10, 2026));

            Livewire::actingAs($user)->test(Dashboard::class)
                ->assertSee('Overdue insurance')
                ->assertSee('Expected tax')
                ->assertSee('Paid 11 Oct')
                ->assertDontSeeHtml('>Overdue</')
                ->assertDontSeeHtml('>Expected</');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_linked_expense_cannot_be_deleted_or_made_recurring_until_unlinked(): void
    {
        $user = User::factory()->create();
        $bill = $this->bill($user, '2026-10-10');
        $expense = $this->expense($user, '2026-10-10', 'Car insurance', '600.00');
        $bill->payments()->create(['user_id' => $user->id, 'expected_date' => '2025-10-10', 'transaction_id' => $expense->id]);

        Livewire::actingAs($user)->test(TransactionManager::class)
            ->call('confirmDelete', $expense->id)
            ->call('delete')->assertHasErrors(['delete']);
        Livewire::actingAs($user)->test(TransactionManager::class)
            ->call('edit', $expense->id)
            ->set('is_recurring', true)
            ->set('frequency', 'yearly')
            ->call('save')->assertHasErrors(['save']);

        $this->assertDatabaseHas('transactions', ['id' => $expense->id]);
    }

    private function bill(User $user, string $date, string $frequency = 'yearly', ?int $anchorDay = null, string $name = 'Planned bill'): PlannedBill
    {
        return PlannedBill::create([
            'user_id' => $user->id,
            'name' => $name,
            'estimated_amount' => '600.00',
            'next_due_date' => $date,
            'anchor_day' => $anchorDay ?? Carbon::parse($date)->day,
            'frequency' => $frequency,
        ]);
    }

    private function expense(User $user, string $date, string $description, string $amount): Transaction
    {
        return Transaction::factory()->for($user)->create([
            'category_id' => null,
            'type' => Transaction::TYPE_EXPENSE,
            'is_recurring' => false,
            'frequency' => null,
            'date' => $date,
            'description' => $description,
            'amount' => $amount,
        ]);
    }
}
