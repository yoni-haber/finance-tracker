<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\PeriodSelector;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class PeriodSelectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_initialises_from_the_users_saved_period(): void
    {
        $user = User::factory()->create(['selected_month' => 3, 'selected_year' => 2023]);

        Livewire::actingAs($user)
            ->test(PeriodSelector::class)
            ->assertSet('month', 3)
            ->assertSet('year', 2023);
    }

    public function test_it_defaults_to_the_current_month_when_unset(): void
    {
        Carbon::setTestNow('2024-05-15');

        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(PeriodSelector::class)
            ->assertSet('month', 5)
            ->assertSet('year', 2024);
    }

    public function test_changing_the_month_persists_and_dispatches(): void
    {
        $user = User::factory()->create(['selected_month' => 5, 'selected_year' => 2024]);

        Livewire::actingAs($user)
            ->test(PeriodSelector::class)
            ->set('month', 8)
            ->assertDispatched('period-changed', month: 8, year: 2024);

        $user->refresh();
        $this->assertSame(8, $user->selected_month);
        $this->assertSame(2024, $user->selected_year);
    }

    public function test_previous_month_rolls_back_across_year_boundary(): void
    {
        $user = User::factory()->create(['selected_month' => 1, 'selected_year' => 2024]);

        Livewire::actingAs($user)
            ->test(PeriodSelector::class)
            ->call('previousMonth')
            ->assertSet('month', 12)
            ->assertSet('year', 2023)
            ->assertDispatched('period-changed', month: 12, year: 2023);

        $user->refresh();
        $this->assertSame(12, $user->selected_month);
        $this->assertSame(2023, $user->selected_year);
    }

    public function test_next_month_rolls_forward_across_year_boundary(): void
    {
        $user = User::factory()->create(['selected_month' => 12, 'selected_year' => 2024]);

        Livewire::actingAs($user)
            ->test(PeriodSelector::class)
            ->call('nextMonth')
            ->assertSet('month', 1)
            ->assertSet('year', 2025)
            ->assertDispatched('period-changed', month: 1, year: 2025);
    }

    public function test_year_options_include_a_recent_window_and_the_selection(): void
    {
        Carbon::setTestNow('2024-05-15');

        $user = User::factory()->create(['selected_month' => 5, 'selected_year' => 2010]);

        // years: descending, spans next year back ten years, and includes the
        // out-of-window selected year (2010).
        Livewire::actingAs($user)
            ->test(PeriodSelector::class)
            ->assertViewHas('years', fn (array $years): bool => $years[0] === 2025
                && in_array(2014, $years, true)
                && in_array(2010, $years, true)
                && $years === collect($years)->sortDesc()->values()->all());
    }

    public function test_year_options_span_exactly_next_year_back_ten_years_when_in_window(): void
    {
        Carbon::setTestNow('2024-05-15');

        $user = User::factory()->create(['selected_month' => 5, 'selected_year' => 2020]);

        Livewire::actingAs($user)
            ->test(PeriodSelector::class)
            ->assertViewHas('years', fn (array $years): bool => $years === [
                2025, 2024, 2023, 2022, 2021, 2020, 2019, 2018, 2017, 2016, 2015, 2014,
            ]);
    }

    public function test_year_options_sort_an_above_window_selection_to_the_front(): void
    {
        Carbon::setTestNow('2024-05-15');

        $user = User::factory()->create(['selected_month' => 5, 'selected_year' => 2035]);

        Livewire::actingAs($user)
            ->test(PeriodSelector::class)
            ->assertViewHas('years', fn (array $years): bool => $years[0] === 2035
                && $years === collect($years)->sortDesc()->values()->all());
    }

    public function test_changing_the_year_persists_and_dispatches(): void
    {
        $user = User::factory()->create(['selected_month' => 5, 'selected_year' => 2024]);

        Livewire::actingAs($user)
            ->test(PeriodSelector::class)
            ->set('year', 2022)
            ->assertDispatched('period-changed', month: 5, year: 2022);

        $user->refresh();
        $this->assertSame(5, $user->selected_month);
        $this->assertSame(2022, $user->selected_year);
    }

    public function test_sync_period_clamps_out_of_range_payloads(): void
    {
        $user = User::factory()->create(['selected_month' => 5, 'selected_year' => 2024]);

        Livewire::actingAs($user)
            ->test(PeriodSelector::class)
            ->call('syncPeriod', 13, 2101)
            ->assertSet('month', 12)
            ->assertSet('year', 2100);
    }

    public function test_it_defaults_to_the_current_month_for_a_guest(): void
    {
        Carbon::setTestNow('2024-05-15');

        Livewire::test(PeriodSelector::class)
            ->assertSet('month', 5)
            ->assertSet('year', 2024);
    }

    public function test_previous_month_works_for_a_guest(): void
    {
        Carbon::setTestNow('2024-05-15');

        Livewire::test(PeriodSelector::class)
            ->call('previousMonth')
            ->assertSet('month', 4)
            ->assertSet('year', 2024)
            ->assertDispatched('period-changed', month: 4, year: 2024);
    }
}
