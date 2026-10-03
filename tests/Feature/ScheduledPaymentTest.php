<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\Transactions\TransactionManager;
use App\Livewire\UpcomingPayments;
use App\Models\Transaction;
use App\Models\User;
use App\Support\TransactionReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ScheduledPaymentTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{bool, bool}> */
    public static function newPaymentActions(): iterable
    {
        yield 'ordinary ledger visit' => [false, false];

        yield 'new transaction' => [true, false];

        yield 'scheduled payment' => [false, true];

        yield 'new scheduled payment' => [true, true];
    }

    #[DataProvider('newPaymentActions')]
    public function test_mount_opens_the_editor_only_for_an_explicit_new_or_scheduled_action(bool $new, bool $scheduled): void
    {
        $this->travelTo(now()->setDate(2026, 10, 2));
        $user = User::factory()->create(['selected_month' => 1, 'selected_year' => 2020]);

        $testable = Livewire::actingAs($user)->withQueryParams(['new' => $new, 'scheduled' => $scheduled])
            ->test(TransactionManager::class)
            ->assertSet('new', $new)->assertSet('scheduled', $scheduled)
            ->assertSet('date', $scheduled ? '2026-10-02' : '2020-01-01');

        if ($new || $scheduled) {
            $testable->assertDispatched('open-transaction-modal');
        } else {
            $testable->assertNotDispatched('open-transaction-modal');
        }

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_livewire_field_validation_can_access_transaction_rules_without_explicit_rules(): void
    {
        $component = Livewire::actingAs(User::factory()->create())->test(TransactionManager::class)->instance();
        $this->assertInstanceOf(TransactionManager::class, $component);
        $component->amount = '0.01';
        $this->assertSame(['amount' => '0.01'], $component->validateOnly('amount'));

        $this->assertDatabaseCount('transactions', 0);
        $component->amount = '0';
        $this->expectException(ValidationException::class);
        $component->validateOnly('amount');
    }

    public function test_scheduled_add_uses_today_even_with_a_historical_period_and_creates_a_future_yearly_payment(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 2));
        $user = User::factory()->create(['selected_month' => 1, 'selected_year' => 2020]);
        Livewire::actingAs($user);
        Livewire::withQueryParams(['scheduled' => 1, 'scope' => 'all'])->test(TransactionManager::class)
            ->assertDispatched('open-transaction-modal')->assertSet('date', '2026-10-02')->assertSet('type', 'expense')
            ->assertSee('No previous payment is needed.')->assertSee('First expected payment date')
            ->set('description', 'First car insurance')->set('amount', '650.00')->set('date', '2026-12-15')
            ->set('is_recurring', true)->set('frequency', 'yearly')->call('save')->assertHasNoErrors()
            ->assertSet('scheduled', false)->assertSet('new', false)->assertDispatched('close-transaction-modal');

        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseHas('transactions', ['user_id' => $user->id, 'date' => '2026-12-15', 'frequency' => 'yearly']);
        $this->assertTrue(TransactionReport::projectedForMonth($user->id, 10, 2026)->isEmpty());
        $this->assertTrue(TransactionReport::projectedForMonth($user->id, 11, 2026)->isEmpty());
        $this->assertSame('650.00', (string) TransactionReport::projectedForMonth($user->id, 12, 2026)->sole()->amount);
        Livewire::withQueryParams([])->test(UpcomingPayments::class)->assertSee('First car insurance');
    }

    public function test_edit_link_opens_the_existing_series_and_save_clears_the_query_state(): void
    {
        $user = User::factory()->create();
        $transaction = Transaction::factory()->for($user)->recurring('quarterly')->create(['type' => 'expense', 'date' => '2026-12-15']);
        Livewire::actingAs($user);
        Livewire::withQueryParams(['edit' => $transaction->id, 'scope' => 'all', 'scheduled' => 1, 'new' => 1])
            ->test(TransactionManager::class)->assertDispatched('open-transaction-modal')
            ->assertSet('transactionId', $transaction->id)->assertSet('date', '2026-12-15')
            ->assertSet('frequency', 'quarterly')->assertSee('Edit recurring series')
            ->set('amount', '700.00')->call('save')->assertHasNoErrors()->assertSet('editId', null)
            ->assertSet('scheduled', false)->assertSet('new', false);
        $this->assertSame('700.00', $transaction->refresh()->amount);
    }

    public function test_edit_one_off_and_cancel_clear_modal_url_state_without_saving(): void
    {
        $user = User::factory()->create();
        $transaction = Transaction::factory()->for($user)->create(['description' => 'Original']);
        Livewire::actingAs($user);
        Livewire::withQueryParams(['edit' => $transaction->id])->test(TransactionManager::class)
            ->assertSee('Edit transaction')->set('description', 'Unsaved')->call('resetForm')
            ->assertSet('editId', null)->assertSet('transactionId', null)->assertSet('description', null);
        $this->assertSame('Original', $transaction->refresh()->description);
        Livewire::withQueryParams(['new' => 1, 'scheduled' => 1])->test(TransactionManager::class)
            ->call('resetForm')->assertSet('new', false)->assertSet('scheduled', false);
    }

    public function test_edit_links_for_missing_or_foreign_records_return_404(): void
    {
        $user = User::factory()->create();
        $foreign = Transaction::factory()->create();
        $this->actingAs($user)->get(route('transactions', ['edit' => $foreign->id]))->assertNotFound();
        $this->get(route('transactions', ['edit' => 999999]))->assertNotFound();
    }

    public function test_dashboard_matches_six_month_forecast_shows_five_and_ignores_selected_period(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 2));
        $user = User::factory()->create(['selected_month' => 1, 'selected_year' => 2020]);
        for ($day = 3; $day <= 8; $day++) {
            Transaction::factory()->for($user)->create([
                'type' => 'expense', 'category_id' => null, 'date' => '2026-10-' . $day,
                'amount' => '10.10', 'description' => 'Future payment ' . $day,
            ]);
        }

        $testable = Livewire::actingAs($user)->test(Dashboard::class)
            ->assertSee('2 Oct 2026')->assertSee('31 Mar 2027')->assertSee('£10.10')->assertDontSee('£60.60')
            ->assertSee('Future payment 3')->assertSee('Future payment 7')->assertDontSee('Future payment 8');
        $testable->dispatch('period-changed', month: 2, year: 2020)->assertSee('£10.10')->assertDontSee('£60.60');
        Livewire::test(UpcomingPayments::class)->assertSee('Future payment 8')->assertSee('£60.60');
        $testable->assertSeeInOrder(['Cash flow trend', 'Where spending went', 'dashboard-upcoming-heading'])
            ->assertSeeHtml('grid gap-5 lg:grid-cols-2')->assertDontSee('expected over six months');
    }

    public function test_saving_a_payment_started_from_upcoming_returns_after_persisting_and_clears_the_origin(): void
    {
        $user = User::factory()->create();
        Livewire::actingAs($user);
        $testable = Livewire::withQueryParams(['new' => 1, 'scheduled' => 1, 'upcoming' => 1])->test(TransactionManager::class)
            ->assertSet('returnToUpcoming', true)->set('amount', '650')->set('date', '2026-12-15')
            ->set('is_recurring', true)->set('frequency', 'yearly')->call('save')->assertHasNoErrors()
            ->assertRedirect(route('upcoming-payments'))->assertSet('returnToUpcoming', false)
            ->assertSet('scheduled', false)->assertSet('new', false)->assertSessionHas('status', 'Transaction saved successfully.');
        $this->assertTrue($testable->effects['redirectUsingNavigate']);
        $this->assertDatabaseHas('transactions', ['user_id' => $user->id, 'amount' => '650.00', 'date' => '2026-12-15', 'frequency' => 'yearly']);
    }

    public function test_editing_from_upcoming_returns_only_after_a_successful_save(): void
    {
        $user = User::factory()->create();
        $transaction = Transaction::factory()->for($user)->recurring('yearly')->create(['amount' => '100.00']);
        Livewire::actingAs($user);
        $testable = Livewire::withQueryParams(['edit' => $transaction->id, 'upcoming' => 1])->test(TransactionManager::class)
            ->set('amount', '0')->call('save')->assertHasErrors('amount')->assertNoRedirect()
            ->assertSet('returnToUpcoming', true)->assertSet('transactionId', $transaction->id);
        $this->assertSame('100.00', $transaction->refresh()->amount);
        $testable->set('amount', '200')->call('save')->assertHasNoErrors()->assertRedirect(route('upcoming-payments'));
        $this->assertSame('200.00', $transaction->refresh()->amount);
    }

    public function test_missing_record_does_not_redirect_and_cancelling_clears_the_origin_without_a_save(): void
    {
        $user = User::factory()->create();
        $transaction = Transaction::factory()->for($user)->create(['amount' => '100.00']);
        Livewire::actingAs($user);
        $testable = Livewire::withQueryParams(['edit' => $transaction->id, 'upcoming' => 1])->test(TransactionManager::class);
        $transaction->delete();
        $testable->set('amount', '200')->call('save')->assertHasErrors('save')->assertNoRedirect()
            ->assertSet('returnToUpcoming', true)->call('resetForm')->assertSet('returnToUpcoming', false)->assertNoRedirect();
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_regular_save_stays_on_transactions_and_cancelling_upcoming_does_not_leak_to_next_form(): void
    {
        Livewire::actingAs(User::factory()->create());
        Livewire::withQueryParams(['new' => 1, 'scheduled' => 1, 'upcoming' => 1])->test(TransactionManager::class)
            ->call('resetForm')->call('openModal')->assertSet('returnToUpcoming', false)
            ->set('amount', '10')->call('save')->assertHasNoErrors()->assertNoRedirect();
        Livewire::withQueryParams([])->test(TransactionManager::class)
            ->set('amount', '20')->call('save')->assertHasNoErrors()->assertNoRedirect();
    }

    public function test_dashboard_empty_preview_offers_scheduled_payment_and_fallback_labels_render(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 2));
        $user = User::factory()->create();
        Livewire::actingAs($user)->test(Dashboard::class)->assertSee('No scheduled spending payments')
            ->assertSeeHtml(e(route('transactions', ['new' => 1, 'scheduled' => 1, 'scope' => 'all'])));
        Transaction::factory()->for($user)->recurring('yearly')->create([
            'type' => 'expense', 'category_id' => null, 'description' => null, 'date' => '2026-12-01',
        ]);
        $named = Transaction::factory()->for($user)->create(['type' => 'expense', 'description' => null, 'date' => '2026-12-02']);
        Livewire::test(Dashboard::class)->assertSee('Payment')->assertSee('Yearly')->assertSee('One-off')->assertSee($named->category()->firstOrFail()->name);
    }
}
