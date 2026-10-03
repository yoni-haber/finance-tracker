<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Transactions\TransactionManager;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RecurrenceEndDateTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_has_no_end_date_or_date_input_and_opening_focuses_the_heading(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 2));
        Livewire::actingAs(User::factory()->create())->test(TransactionManager::class)
            ->call('openModal')->assertDispatched('open-transaction-modal')
            ->assertSeeHtml('tabindex="-1" autofocus="autofocus" data-transaction-heading="data-transaction-heading"')
            ->assertSeeHtml('preventScroll: true')->assertSeeHtml('scrollTop = 0')
            ->set('is_recurring', true)->assertSet('hasEndDate', false)->assertSet('recurring_until', null)
            ->assertSee('No end date · Repeats indefinitely.')->assertDontSeeHtml('id="transaction-recurrence-end"')
            ->set('amount', '20')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('transactions', ['date' => '2026-10-02', 'is_recurring' => true, 'frequency' => 'monthly', 'recurring_until' => null]);
    }

    /** @return iterable<string, array{bool, bool, bool}> */
    public static function endDateChoices(): iterable
    {
        yield 'one-off, no end date' => [false, false, false];

        yield 'one-off, stale end-date choice' => [false, true, false];

        yield 'recurring, no end date' => [true, false, false];

        yield 'recurring, requires selected date' => [true, true, true];
    }

    #[DataProvider('endDateChoices')]
    public function test_end_date_is_required_only_when_a_recurring_payment_opts_in(bool $recurring, bool $hasEndDate, bool $invalid): void
    {
        Livewire::actingAs(User::factory()->create());
        $testable = Livewire::test(TransactionManager::class)->set('amount', '20')->set('date', '2026-12-15')
            ->set('is_recurring', $recurring)->set('hasEndDate', $hasEndDate)->call('save');
        if ($invalid) {
            $testable->assertHasErrors(['recurring_until' => 'required'])->assertSet('hasEndDate', true)
                ->assertSeeHtml('id="transaction-recurrence-end"');
            $this->assertDatabaseCount('transactions', 0);
        } else {
            $testable->assertHasNoErrors()->assertSet('hasEndDate', false);
            $this->assertDatabaseHas('transactions', ['is_recurring' => $recurring, 'recurring_until' => null]);
        }
    }

    public function test_toggling_the_end_date_off_clears_the_date_and_its_errors_but_preserves_the_schedule(): void
    {
        Livewire::actingAs(User::factory()->create())->test(TransactionManager::class)
            ->set('amount', '30')->set('date', '2026-12-15')->set('is_recurring', true)->set('frequency', 'yearly')
            ->set('hasEndDate', true)->assertSet('recurring_until', null)->call('save')->assertHasErrors('recurring_until')
            ->set('recurring_until', '2026-12-14')->call('save')->assertHasErrors(['recurring_until' => 'after_or_equal'])
            ->set('amount', '')->call('save')->assertHasErrors(['amount', 'recurring_until'])
            ->set('hasEndDate', false)->assertSet('recurring_until', null)->assertHasNoErrors('recurring_until')
            ->assertHasErrors('amount')->set('amount', '30')
            ->assertSet('frequency', 'yearly')->assertSet('is_recurring', true)->assertSee('Repeats indefinitely.')
            ->set('hasEndDate', true)->assertSet('recurring_until', null)
            ->set('recurring_until', '2026-12-15')->call('save')->assertHasNoErrors()->assertSet('hasEndDate', false);
        $this->assertDatabaseHas('transactions', ['recurring_until' => '2026-12-15', 'frequency' => 'yearly']);
    }

    public function test_enabling_the_end_date_preserves_a_date_already_supplied_with_the_form(): void
    {
        Livewire::actingAs(User::factory()->create())->test(TransactionManager::class)
            ->set('amount', '30')->set('date', '2026-12-15')->set('is_recurring', true)
            ->set('recurring_until', '2027-12-15')->set('hasEndDate', true)
            ->assertSet('recurring_until', '2027-12-15')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('transactions', ['recurring_until' => '2027-12-15', 'frequency' => 'monthly']);
    }

    public function test_edit_loads_finite_and_infinite_series_and_can_remove_an_existing_end_date(): void
    {
        $user = User::factory()->create();
        $finite = Transaction::factory()->for($user)->recurring('quarterly')->create(['date' => '2026-12-15', 'recurring_until' => '2027-12-15']);
        $infinite = Transaction::factory()->for($user)->recurring('yearly')->create(['recurring_until' => null]);
        Livewire::actingAs($user)->test(TransactionManager::class)->call('edit', $finite->id)
            ->assertSet('hasEndDate', true)->assertSet('recurring_until', '2027-12-15')->assertSee('Repeat until')
            ->set('hasEndDate', false)->call('save')->assertHasNoErrors();
        $this->assertNull($finite->refresh()->recurring_until);
        Livewire::test(TransactionManager::class)->call('edit', $infinite->id)->assertSet('hasEndDate', false)
            ->assertSet('recurring_until', null)->assertSee('Repeats indefinitely.');
    }

    public function test_disabling_recurrence_and_cancelling_each_clear_the_end_date_choice(): void
    {
        $testable = Livewire::actingAs(User::factory()->create())->test(TransactionManager::class)
            ->set('is_recurring', true)->set('hasEndDate', true)->set('recurring_until', '2027-12-15')
            ->set('is_recurring', false)->assertSet('hasEndDate', false)->assertSet('recurring_until', null)
            ->assertSet('frequency', null)->set('is_recurring', true)->set('hasEndDate', true)
            ->set('recurring_until', '2027-12-15')->call('resetForm')->assertSet('hasEndDate', false)
            ->assertSet('recurring_until', null)->call('openModal')->assertSet('hasEndDate', false);
        $testable->set('is_recurring', true)->assertDontSeeHtml('id="transaction-recurrence-end"');
        $this->assertDatabaseCount('transactions', 0);
    }
}
