<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\UpcomingPayments;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class UpcomingPaymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_and_authenticated_users_see_navigation_but_no_period_selector(): void
    {
        $this->get(route('upcoming-payments'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('upcoming-payments'))
            ->assertOk()->assertSee('Planning ahead')->assertSee('Upcoming Payments')
            ->assertSee('Add scheduled payment')->assertDontSeeHtml('aria-label="Previous month"')
            ->assertSeeHtml('dark:')->assertSeeHtml('Forecast filters');
    }

    public function test_forecast_displays_future_first_payment_and_zero_months_independently_of_selected_period(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 2));
        $user = User::factory()->create(['selected_month' => 1, 'selected_year' => 2020]);
        $payment = Transaction::factory()->for($user)->recurring('yearly')->create([
            'type' => 'expense', 'category_id' => null, 'date' => '2026-12-15', 'description' => 'Car insurance', 'amount' => '650.00',
        ]);
        $testable = Livewire::actingAs($user)->test(UpcomingPayments::class)
            ->assertSet('months', '6')->assertSet('minimum', '')->assertSet('includeRegular', false)
            ->assertSee('2 Oct 2026')->assertSee('31 Mar 2027')->assertSee('Car insurance')
            ->assertSee('£650.00')->assertSee('Yearly')->assertSee('November 2026')->assertSee('£0.00')
            ->assertSeeHtml(e(route('transactions', ['edit' => $payment->id, 'scope' => 'all', 'upcoming' => 1])))
            ->assertSee('Edit series');
        $testable->call('$refresh')->assertSee('2 Oct 2026');
        $this->assertSame(2020, $user->refresh()->selected_year);
    }

    public function test_monthly_totals_remain_visible_without_an_overall_forecast_total(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 2));
        $user = User::factory()->create();
        Transaction::factory()->for($user)->create(['type' => 'expense', 'category_id' => null, 'date' => '2026-11-15', 'amount' => '100.10']);
        Transaction::factory()->for($user)->create(['type' => 'expense', 'category_id' => null, 'date' => '2026-12-15', 'amount' => '200.20']);
        Livewire::actingAs($user)->test(UpcomingPayments::class)->assertSee('£100.10')->assertSee('£200.20')
            ->assertDontSee('£300.30')->assertSee('2 payments')->assertSeeHtml(e(route('transactions', ['new' => 1, 'scheduled' => 1, 'scope' => 'all', 'upcoming' => 1])))
            ->assertDontSeeHtml('aria-label="Forecast summary"')
            ->assertSeeInOrder(['Forecast filters', '2 Oct 2026', '2 payments', 'October 2026'])
            ->assertSeeHtml('border-l-emerald-600/60')->assertSeeHtml('dark:border-l-[#75ddb2]/60')
            ->assertSeeHtml('text-[#bd5b52] dark:text-[#f19b91]')->assertSeeHtml('text-base font-medium app-muted');
    }

    public function test_filters_load_from_url_apply_together_and_reset(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 2));
        $user = User::factory()->create();
        Transaction::factory()->for($user)->recurring('monthly')->create([
            'type' => 'expense', 'category_id' => null, 'date' => '2027-02-01', 'amount' => '400.00', 'description' => 'Large monthly bill',
        ]);
        Livewire::actingAs($user);
        Livewire::withQueryParams(['months' => '12', 'regular' => true, 'minimum' => '400.00'])
            ->test(UpcomingPayments::class)->assertSet('months', '12')->assertSet('includeRegular', true)
            ->assertSet('minimum', '400')->assertSee('Large monthly bill')->assertDontSee('£3,200.00')->assertSee('£400.00')
            ->set('months', '3')->assertDontSee('Large monthly bill')->assertSee('No upcoming payments match these filters.')
            ->call('resetFilters')->assertSet('months', '6')->assertSet('includeRegular', false)->assertSet('minimum', '')
            ->assertHasNoErrors()->assertSee('No scheduled spending payments in this date range.');
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidFilters(): iterable
    {
        foreach (['', '0', '4', '13', '-1', '100000', 'three'] as $value) {
            yield 'months ' . $value => ['months', $value];
        }

        foreach (['-1', '1.001', 'abc', '1e2', '£100', '10000000000', ' 100 ', '.5'] as $value) {
            yield 'minimum ' . $value => ['minimum', $value];
        }
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_show_errors_without_generating_a_forecast(string $field, string $value): void
    {
        Livewire::actingAs(User::factory()->create())->test(UpcomingPayments::class)
            ->set($field, $value)->assertHasErrors($field)->assertViewHas('forecast', null)
            ->assertSee('Correct the filters')->assertSeeHtml('data-action-error role="alert"')
            ->call('resetFilters')->assertHasNoErrors()->assertDontSeeHtml('data-action-error');
    }

    public function test_invalid_query_parameters_are_validated_and_valid_minimum_limits_are_accepted(): void
    {
        Livewire::actingAs(User::factory()->create());
        Livewire::withQueryParams(['months' => '999', 'minimum' => '-5'])->test(UpcomingPayments::class)
            ->assertHasErrors(['months', 'minimum'])->assertViewHas('forecast', null);
        Livewire::withQueryParams([]);
        foreach (['0', '0.01', '1.1', '9999999999.99', ''] as $minimum) {
            Livewire::test(UpcomingPayments::class)->set('minimum', $minimum)->assertHasNoErrors();
        }
    }

    public function test_one_off_labels_and_description_fallbacks_and_income_exclusion(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 2));
        $user = User::factory()->create();
        Transaction::factory()->for($user)->create([
            'type' => 'expense', 'date' => '2026-10-03', 'description' => null, 'category_id' => null,
        ]);
        $named = Transaction::factory()->for($user)->create(['type' => 'expense', 'date' => '2026-10-04', 'description' => '']);
        Transaction::factory()->for($user)->create(['type' => 'income', 'date' => '2026-10-03', 'description' => 'Hidden income']);
        Livewire::actingAs($user)->test(UpcomingPayments::class)->assertSee('One-off')->assertSee('Uncategorised')
            ->assertSee($named->category()->firstOrFail()->name)->assertSee('Payment')->assertSee('Edit')->assertDontSee('Hidden income');
    }
}
