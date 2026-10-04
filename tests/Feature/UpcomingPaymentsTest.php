<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\Reports\ReportsHub;
use App\Livewire\UpcomingPayments;
use App\Models\Budget;
use App\Models\Category;
use App\Models\PlannedPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BudgetProgress;
use App\Support\PlannedPayments;
use App\Support\TransactionReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

final class UpcomingPaymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_edit_and_cancel_stay_on_planning_page_and_never_create_a_transaction(): void
    {
        $user = User::factory()->create();
        Livewire::actingAs($user)->test(UpcomingPayments::class)
            ->call('openCreate')->assertDispatched('open-planned-payment-modal')
            ->set('name', 'Unsaved')->set('note', 'Unsaved note')->set('amount', '30')->call('resetForm')
            ->assertSet('name', '')->assertSet('note', null)->assertNoRedirect()
            ->call('openCreate')->set('name', 'Car insurance')->set('amount', '650.00')
            ->set('due_on', '2026-11-15')->set('frequency', 'yearly')->set('note', '  Check renewal quote  ')
            ->call('save')->assertHasNoErrors()->assertNoRedirect()->assertSee('Payment plan added.')
            ->assertDispatched('close-planned-payment-modal')->assertSee('Car insurance')
            ->assertSee('£650.00')->assertSee('Yearly')->assertSee('Check renewal quote');

        $plan = PlannedPayment::sole();
        $this->assertSame($user->id, $plan->user_id);
        $this->assertSame('Check renewal quote', $plan->note);
        $this->assertDatabaseCount('transactions', 0);

        Livewire::actingAs($user)->test(UpcomingPayments::class)
            ->call('edit', $plan->id)->assertSet('due_on', '2026-11-15')
            ->assertSet('note', 'Check renewal quote')
            ->set('name', 'Annual car insurance')->set('amount', '675.00')->set('note', 'New quote requested')
            ->call('save')->assertHasNoErrors()->assertNoRedirect()->assertSee('Annual car insurance')->assertSee('Payment plan updated.');
        $this->assertSame('675.00', $plan->refresh()->amount);
        $this->assertSame('New quote requested', $plan->note);
        $this->assertSame('2026-11-15', $plan->nextDueDate()?->toDateString());
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_overdue_completion_advances_one_occurrence_and_undo_restores_it(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 4)->startOfDay());
        $user = User::factory()->create();
        $plan = PlannedPayment::create(['user_id' => $user->id, 'name' => 'Water', 'amount' => '180.00', 'first_due_on' => '2026-03-31', 'frequency' => 'quarterly']);
        $testable = Livewire::actingAs($user)->test(UpcomingPayments::class)
            ->assertSee('Overdue by')->assertSee('31 Mar 2026')
            ->call('markDone', $plan->id, '2026-03-31')->assertSee('30 Jun 2026');
        $this->assertSame(1, $plan->refresh()->completed_occurrences);
        $testable->call('markDone', $plan->id, '2026-03-31');
        $this->assertDatabaseHas('planned_payments', ['id' => $plan->id, 'completed_occurrences' => 1]);
        $testable->call('undo', $plan->id)->assertSee('31 Mar 2026');
        $this->assertSame(0, $plan->refresh()->completed_occurrences);
    }

    public function test_one_off_can_be_restored_and_delete_requires_confirmed_action(): void
    {
        $user = User::factory()->create();
        $plan = PlannedPayment::create(['user_id' => $user->id, 'name' => 'Service', 'amount' => '240.00', 'first_due_on' => '2026-11-25', 'frequency' => 'once']);
        $testable = Livewire::actingAs($user)->test(UpcomingPayments::class)
            ->call('markDone', $plan->id, '2026-11-25')
            ->assertDontSee('Completed one-off plans')->assertSee('No upcoming payments yet')
            ->assertSee('Payment marked done.')->assertSee('Undo');
        $this->assertSame(1, $plan->refresh()->completed_occurrences);
        $testable->call('undo', $plan->id)->assertSee('25 Nov 2026');
        $testable->call('confirmDelete', $plan->id)->assertDispatched('open-delete-planned-payment-modal');
        $testable->call('resetDelete')->assertSet('deletingId', null);
        $this->assertDatabaseCount('planned_payments', 1);
        $testable->call('confirmDelete', $plan->id);
        $testable->call('delete')->assertDispatched('close-delete-planned-payment-modal');
        $this->assertDatabaseCount('planned_payments', 0);
    }

    public function test_repeating_dates_show_for_the_next_year_but_only_the_earliest_can_be_completed(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 4)->startOfDay());
        $user = User::factory()->create();
        $plan = PlannedPayment::create(['user_id' => $user->id, 'name' => 'Water', 'amount' => '180.00', 'first_due_on' => '2026-10-15', 'frequency' => 'quarterly']);

        $testable = Livewire::actingAs($user)->test(UpcomingPayments::class);
        $testable->assertSeeInOrder(['15 Oct 2026', '15 Jan 2027', '15 Apr 2027', '15 Jul 2027'])
            ->assertDontSee('15 Oct 2027');
        $this->assertSame(2, substr_count($testable->html(), 'wire:click="markDone(' . $plan->id . ', \'2026-10-15\')"'));
        $this->assertStringNotContainsString('wire:click="markDone(' . $plan->id . ', \'2027-01-15\')"', $testable->html());
        $this->assertSame(8, substr_count($testable->html(), 'wire:click="edit(' . $plan->id . ')"'));
        $this->assertSame(8, substr_count($testable->html(), 'wire:click="confirmDelete(' . $plan->id . ')"'));

        $testable->call('markDone', $plan->id, '2026-10-15')
            ->assertSee('15 Jan 2027')->assertSee('Undo');
        $this->assertSame(1, $plan->refresh()->completed_occurrences);
        $this->assertSame(2, substr_count($testable->html(), 'wire:click="markDone(' . $plan->id . ', \'2027-01-15\')"'));
        $this->assertSame(1, substr_count($testable->html(), 'wire:click="undo(' . $plan->id . ')"'));
    }

    public function test_validation_ownership_and_schedule_editing_rules(): void
    {
        $user = User::factory()->create();
        $foreign = PlannedPayment::create(['user_id' => User::factory()->create()->id, 'name' => 'Private', 'amount' => '10', 'first_due_on' => '2026-11-01', 'frequency' => 'once']);
        Livewire::actingAs($user)->test(UpcomingPayments::class)
            ->set('name', ' ')->set('amount', '0')->set('due_on', 'bad')->set('frequency', 'weekly')->set('note', str_repeat('a', 501))
            ->call('save')->assertHasErrors(['name', 'note', 'amount', 'due_on', 'frequency']);
        Livewire::actingAs($user)->test(UpcomingPayments::class)->call('edit', $foreign->id)->assertNotFound();
        Livewire::actingAs($user)->test(UpcomingPayments::class)->call('markDone', $foreign->id, '2026-11-01')->assertNotFound();
        Livewire::actingAs($user)->test(UpcomingPayments::class)->call('undo', $foreign->id)->assertNotFound();
        Livewire::actingAs($user)->test(UpcomingPayments::class)->call('confirmDelete', $foreign->id)->assertNotFound();

        $plan = PlannedPayment::create(['user_id' => $user->id, 'name' => 'Insurance', 'amount' => '650', 'first_due_on' => '2025-01-31', 'frequency' => 'quarterly', 'completed_occurrences' => 2]);
        Livewire::actingAs($user)->test(UpcomingPayments::class)->call('edit', $plan->id)
            ->set('amount', '700')->call('save')->assertHasNoErrors();
        $this->assertSame(2, $plan->refresh()->completed_occurrences);
        $this->assertSame('2025-07-31', $plan->nextDueDate()?->toDateString());
        Livewire::actingAs($user)->test(UpcomingPayments::class)->call('edit', $plan->id)
            ->set('due_on', '2026-11-30')->set('frequency', 'yearly')->call('save')->assertHasNoErrors();
        $this->assertSame(0, $plan->refresh()->completed_occurrences);
        $this->assertSame('2026-11-30', $plan->nextDueDate()?->toDateString());
    }

    public function test_each_required_field_is_validated_and_a_one_off_name_is_trimmed(): void
    {
        $user = User::factory()->create();
        $valid = ['name' => '  Car insurance  ', 'amount' => '650.00', 'due_on' => '2026-11-15', 'frequency' => 'once'];

        foreach (['name', 'amount', 'due_on', 'frequency'] as $field) {
            $input = $valid;
            $input[$field] = '';
            Livewire::actingAs($user)->test(UpcomingPayments::class)
                ->set($input)->call('save')->assertHasErrors([$field => 'required']);
        }

        $this->assertDatabaseCount('planned_payments', 0);
        Livewire::actingAs($user)->test(UpcomingPayments::class)
            ->set($valid)->call('save')->assertHasNoErrors()
            ->assertSet('editingId', null)->assertSet('name', '')->assertSet('amount', '')
            ->assertSet('due_on', '')->assertSet('frequency', 'once');
        $plan = PlannedPayment::sole();
        $this->assertSame('Car insurance', $plan->name);
        $this->assertSame('once', $plan->frequency);
    }

    public function test_create_and_edit_reset_stale_form_state_and_validation_errors(): void
    {
        $user = User::factory()->create();
        $first = PlannedPayment::create(['user_id' => $user->id, 'name' => 'First', 'amount' => '10', 'first_due_on' => '2026-11-01', 'frequency' => 'once']);
        $second = PlannedPayment::create(['user_id' => $user->id, 'name' => 'Second', 'amount' => '20', 'first_due_on' => '2026-12-01', 'frequency' => 'yearly']);

        $component = Livewire::actingAs($user)->test(UpcomingPayments::class)
            ->call('edit', $first->id)->assertDispatched('open-planned-payment-modal')
            ->set('name', '')->set('amount', '')->call('save')->assertHasErrors(['name', 'amount']);
        $component->call('openCreate')->assertDispatched('open-planned-payment-modal')
            ->assertHasNoErrors()->assertSet('editingId', null)->assertSet('name', '')
            ->assertSet('amount', '')->assertSet('frequency', 'once')
            ->set('name', 'Partial')->set('amount', '99')->set('frequency', 'quarterly');
        $component->call('edit', $second->id)->assertDispatched('open-planned-payment-modal')
            ->assertSet('editingId', $second->id)->assertSet('name', 'Second')
            ->assertSet('amount', '20.00')->assertSet('frequency', 'yearly');

        $component->set('name', '')->call('save')->assertHasErrors(['name']);
        $component->call('resetForm')->assertHasNoErrors()->assertSet('editingId', null);
    }

    public function test_changing_only_date_or_only_repeat_restarts_schedule(): void
    {
        $user = User::factory()->create();
        $plan = PlannedPayment::create(['user_id' => $user->id, 'name' => 'Insurance', 'amount' => '650', 'first_due_on' => '2025-01-31', 'frequency' => 'quarterly', 'completed_occurrences' => 2]);

        Livewire::actingAs($user)->test(UpcomingPayments::class)
            ->call('edit', $plan->id)->set('due_on', '2026-11-30')
            ->call('save')->assertHasNoErrors();
        $this->assertSame(0, $plan->refresh()->completed_occurrences);
        $this->assertSame('2026-11-30', $plan->nextDueDate()?->toDateString());
        $this->assertSame('quarterly', $plan->frequency);

        $plan->update(['completed_occurrences' => 1]);
        Livewire::actingAs($user)->test(UpcomingPayments::class)
            ->call('edit', $plan->id)->set('frequency', 'yearly')
            ->call('save')->assertHasNoErrors();
        $this->assertSame(0, $plan->refresh()->completed_occurrences);
        $this->assertSame('2027-02-28', $plan->first_due_on->toDateString());
        $this->assertSame('yearly', $plan->frequency);
    }

    public function test_completed_one_off_can_be_edited_and_cannot_be_completed_again(): void
    {
        $user = User::factory()->create();
        $plan = PlannedPayment::create(['user_id' => $user->id, 'name' => 'Service', 'amount' => '240', 'first_due_on' => '2026-11-25', 'frequency' => 'once', 'completed_occurrences' => 1]);

        Livewire::actingAs($user)->test(UpcomingPayments::class)
            ->call('edit', $plan->id)->assertSet('due_on', '2026-11-25')
            ->set('name', 'Car service')->call('save')->assertHasNoErrors();
        $this->assertSame(1, $plan->refresh()->completed_occurrences);
        $this->assertSame('Car service', $plan->name);

        Livewire::actingAs($user)->test(UpcomingPayments::class)
            ->call('markDone', $plan->id, '2026-11-25')
            ->assertSet('status', 'This payment has already moved to another date.')
            ->assertSet('statusUndoId', null);
        $this->assertSame(1, $plan->refresh()->completed_occurrences);
    }

    public function test_stale_completion_has_no_undo_and_undo_at_zero_does_not_go_negative(): void
    {
        $user = User::factory()->create();
        $plan = PlannedPayment::create(['user_id' => $user->id, 'name' => 'Water', 'amount' => '50', 'first_due_on' => '2026-11-25', 'frequency' => 'quarterly']);

        $component = Livewire::actingAs($user)->test(UpcomingPayments::class)
            ->call('markDone', $plan->id, '2026-12-25')
            ->assertSet('status', 'This payment has already moved to another date.')
            ->assertSet('statusUndoId', null);
        $this->assertSame(0, $plan->refresh()->completed_occurrences);
        $component->call('undo', $plan->id)->assertSet('status', 'Payment plan restored.');
        $this->assertSame(0, $plan->refresh()->completed_occurrences);
    }

    public function test_deleting_a_plan_clears_the_pending_confirmation(): void
    {
        $user = User::factory()->create();
        $plan = PlannedPayment::create(['user_id' => $user->id, 'name' => 'Service', 'amount' => '240', 'first_due_on' => '2026-11-25', 'frequency' => 'once']);

        Livewire::actingAs($user)->test(UpcomingPayments::class)
            ->call('confirmDelete', $plan->id)->assertSet('deletingName', 'Service')
            ->call('delete')->assertSet('deletingId', null)->assertSet('deletingName', '')
            ->assertDispatched('close-delete-planned-payment-modal');
        $this->assertDatabaseCount('planned_payments', 0);
    }

    public function test_render_accepts_a_numeric_string_auth_identifier(): void
    {
        $user = User::factory()->create();
        $plan = PlannedPayment::create(['user_id' => $user->id, 'name' => 'Service', 'amount' => '240', 'first_due_on' => '2026-11-25', 'frequency' => 'once']);
        $this->actingAs($user);
        Auth::shouldReceive('id')->once()->andReturn((string) $user->id);

        $payments = app(UpcomingPayments::class)->render()->getData()['payments'];
        $this->assertSame($plan->id, $payments->first()['plan']->id);
    }

    public function test_dashboard_shows_first_three_plans_below_summary_without_changing_actuals(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 4)->startOfDay());
        $user = User::factory()->create(['selected_month' => 10, 'selected_year' => 2026]);
        foreach ([['Last', '2026-12-01'], ['Overdue', '2026-09-01'], ['Third', '2026-11-01'], ['Second', '2026-10-20']] as [$name, $date]) {
            PlannedPayment::create(['user_id' => $user->id, 'name' => $name, 'amount' => '100', 'first_due_on' => $date, 'frequency' => 'once']);
        }

        Transaction::factory()->for($user)->create(['type' => 'expense', 'date' => '2026-10-01', 'amount' => '12.00']);
        $before = TransactionReport::projectedForMonth($user->id, 10, 2026)->pluck('amount')->all();
        Livewire::actingAs($user)->test(Dashboard::class)
            ->assertSeeInOrder(['Monthly summary', 'Budgets needing attention', 'Cash flow trend', 'Where spending went', 'dashboard-upcoming-heading', 'Overdue', 'Second', 'Third'])
            ->assertDontSee('Last')->assertSee('£12.00');
        $this->assertSame($before, TransactionReport::projectedForMonth($user->id, 10, 2026)->pluck('amount')->all());
        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame(['Overdue', 'Second', 'Third', 'Last'], PlannedPayments::outstanding($user->id)->pluck('name')->all());
    }

    public function test_dashboard_preview_orders_repeated_occurrences_with_other_plans(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 4)->startOfDay());
        $user = User::factory()->create(['selected_month' => 10, 'selected_year' => 2026]);
        PlannedPayment::create(['user_id' => $user->id, 'name' => 'Water', 'amount' => '180.00', 'first_due_on' => '2026-10-15', 'frequency' => 'quarterly']);
        PlannedPayment::create(['user_id' => $user->id, 'name' => 'Insurance', 'amount' => '650.00', 'first_due_on' => '2026-12-01', 'frequency' => 'once']);

        Livewire::actingAs($user)->test(Dashboard::class)
            ->assertSeeInOrder(['15 Oct 2026', '1 Dec 2026', '15 Jan 2027'])
            ->assertDontSee('15 Apr 2027');
    }

    public function test_creating_and_completing_plan_leaves_reports_and_budget_actuals_unchanged(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 4)->startOfDay());
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();
        $budget = Budget::factory()->for($user)->for($category, 'category')->create(['month' => 10, 'year' => 2026, 'amount' => '500.00']);
        Transaction::factory()->for($user)->for($category, 'category')->create(['type' => 'expense', 'date' => '2026-10-01', 'amount' => '50.00']);
        $actuals = TransactionReport::projectedForMonth($user->id, 10, 2026);
        $budgetActual = BudgetProgress::forPeriod(collect([$budget->load('category.children')]), $actuals, 10, 2026)->sole()['actual'];
        $testable = Livewire::actingAs($user)->test(ReportsHub::class);
        $chartData = $testable->get('chartData');
        $reportBudgets = $testable->get('budgetData');

        Livewire::actingAs($user)->test(UpcomingPayments::class)
            ->set('name', 'Insurance')->set('amount', '650')->set('due_on', '2026-11-15')
            ->set('frequency', 'yearly')->call('save')->assertHasNoErrors();
        $plan = PlannedPayment::where('user_id', $user->id)->sole();
        Livewire::actingAs($user)->test(UpcomingPayments::class)->call('markDone', $plan->id, '2026-11-15');

        $this->assertSame('50.00', $budgetActual);
        $this->assertSame($actuals->pluck('id')->all(), TransactionReport::projectedForMonth($user->id, 10, 2026)->pluck('id')->all());
        $this->assertSame($budgetActual, BudgetProgress::forPeriod(collect([$budget]), TransactionReport::projectedForMonth($user->id, 10, 2026), 10, 2026)->sole()['actual']);
        $afterReport = Livewire::actingAs($user)->test(ReportsHub::class);
        $this->assertSame($chartData, $afterReport->get('chartData'));
        $this->assertSame($reportBudgets, $afterReport->get('budgetData'));
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_guest_is_redirected_and_page_has_no_reporting_period_controls(): void
    {
        $this->get(route('upcoming-payments'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('upcoming-payments'))
            ->assertOk()->assertSee('Add payment')->assertDontSeeHtml('aria-label="Previous month"');
    }
}
