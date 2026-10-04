<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\PlannedPayment;
use App\Models\User;
use App\Support\PlannedPayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlannedPaymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_quarterly_month_end_uses_original_anchor(): void
    {
        $plan = $this->plan('2025-01-31', 'quarterly');
        $this->assertSame('2025-01-31', $plan->nextDueDate()?->toDateString());
        foreach (['2025-04-30', '2025-07-31', '2025-10-31', '2026-01-31'] as $index => $date) {
            $plan->completed_occurrences = $index + 1;
            $this->assertSame($date, $plan->nextDueDate()?->toDateString());
        }
    }

    public function test_yearly_leap_day_returns_to_february_29_and_one_off_archives(): void
    {
        $plan = $this->plan('2024-02-29', 'yearly');
        foreach (['2025-02-28', '2026-02-28', '2027-02-28', '2028-02-29'] as $index => $date) {
            $plan->completed_occurrences = $index + 1;
            $this->assertSame($date, $plan->nextDueDate()?->toDateString());
        }

        $plannedPayment = $this->plan('2026-11-01', 'once');
        $plannedPayment->completed_occurrences = 1;
        $this->assertNull($plannedPayment->nextDueDate());
    }

    public function test_overdue_and_future_plans_are_sorted_by_next_due_date_and_scoped_to_user(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 4)->startOfDay());
        $user = User::factory()->create();
        $other = User::factory()->create();
        foreach ([['Future', '2026-12-01'], ['Overdue', '2026-09-01'], ['Soon', '2026-10-10']] as [$name, $date]) {
            PlannedPayment::create(['user_id' => $user->id, 'name' => $name, 'amount' => '100', 'first_due_on' => $date, 'frequency' => 'once']);
        }

        PlannedPayment::create(['user_id' => $other->id, 'name' => 'Foreign', 'amount' => '100', 'first_due_on' => '2026-08-01', 'frequency' => 'once']);
        $this->assertSame(['Overdue', 'Soon', 'Future'], PlannedPayments::outstanding($user->id)->pluck('name')->all());
        $this->assertSame('Overdue by 33 days', PlannedPayments::outstanding($user->id)->first()?->dueLabel());
    }

    public function test_next_twelve_months_expands_repeats_and_keeps_one_manageable_date_for_overdue_and_later_plans(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 4)->startOfDay());
        $user = User::factory()->create();
        $quarterly = PlannedPayment::create(['user_id' => $user->id, 'name' => 'Quarterly', 'amount' => '50.00', 'first_due_on' => '2026-09-30', 'frequency' => 'quarterly']);
        $yearly = PlannedPayment::create(['user_id' => $user->id, 'name' => 'Yearly', 'amount' => '100.00', 'first_due_on' => '2027-02-28', 'frequency' => 'yearly']);
        PlannedPayment::create(['user_id' => $user->id, 'name' => 'Overdue once', 'amount' => '20.00', 'first_due_on' => '2026-08-01', 'frequency' => 'once']);
        PlannedPayment::create(['user_id' => $user->id, 'name' => 'Later', 'amount' => '20.00', 'first_due_on' => '2028-01-01', 'frequency' => 'once']);
        PlannedPayment::create(['user_id' => $user->id, 'name' => 'Later annual', 'amount' => '20.00', 'first_due_on' => '2028-02-01', 'frequency' => 'yearly']);
        PlannedPayment::create(['user_id' => $user->id, 'name' => 'Finished', 'amount' => '20.00', 'first_due_on' => '2026-01-01', 'frequency' => 'once', 'completed_occurrences' => 1]);
        PlannedPayment::create(['user_id' => User::factory()->create()->id, 'name' => 'Foreign', 'amount' => '10.00', 'first_due_on' => '2026-10-10', 'frequency' => 'once']);

        $items = PlannedPayments::nextTwelveMonths($user->id);
        $this->assertSame(['2026-08-01', '2026-09-30', '2026-12-30', '2027-02-28', '2027-03-30', '2027-06-30', '2027-09-30', '2028-01-01', '2028-02-01'], $items->pluck('due')->map->toDateString()->all());
        $this->assertSame(['Overdue once', 'Quarterly', 'Quarterly', 'Yearly', 'Quarterly', 'Quarterly', 'Quarterly', 'Later', 'Later annual'], $items->pluck('plan')->pluck('name')->all());
        $this->assertSame([true, true, false, true, false, false, false, true, true], $items->pluck('is_next')->all());
        $future = $items->get(2);
        $this->assertNotNull($future);
        $this->assertSame('Due in 87 days', $quarterly->dueLabel($future['due']));
        $this->assertSame('2028-02-28', $yearly->dueDateForOccurrence(1)->toDateString());
    }

    public function test_year_boundary_includes_the_same_date_next_year(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 4)->startOfDay());
        $user = User::factory()->create();
        PlannedPayment::create(['user_id' => $user->id, 'name' => 'Annual', 'amount' => '650.00', 'first_due_on' => '2026-10-04', 'frequency' => 'yearly']);

        $this->assertSame(['2026-10-04', '2027-10-04'], PlannedPayments::nextTwelveMonths($user->id)->pluck('due')->map->toDateString()->all());
    }

    public function test_frequency_and_due_labels_cover_today_tomorrow_and_overdue_grammar(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 4)->startOfDay());
        $plan = $this->plan('2026-10-04', 'once');
        $this->assertSame('One-off', $plan->frequencyLabel());
        $this->assertSame('Due today', $plan->dueLabel());

        foreach ([
            ['2026-10-02', 'Overdue by 2 days'],
            ['2026-10-03', 'Overdue by 1 day'],
            ['2026-10-05', 'Due tomorrow'],
            ['2026-10-06', 'Due in 2 days'],
        ] as [$date, $label]) {
            $plan->first_due_on = $date;
            $this->assertSame($label, $plan->dueLabel());
        }

        $plan->frequency = 'quarterly';
        $this->assertSame('Quarterly', $plan->frequencyLabel());
        $plan->frequency = 'yearly';
        $this->assertSame('Yearly', $plan->frequencyLabel());
        $plan->frequency = 'once';
        $plan->completed_occurrences = 1;
        $this->assertSame('Completed', $plan->dueLabel());
    }

    public function test_plans_with_the_same_due_date_keep_creation_order_in_both_lists(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 4)->startOfDay());
        $user = User::factory()->create();
        $first = PlannedPayment::create(['user_id' => $user->id, 'name' => 'First', 'amount' => '10', 'first_due_on' => '2026-11-01', 'frequency' => 'once']);
        $second = PlannedPayment::create(['user_id' => $user->id, 'name' => 'Second', 'amount' => '20', 'first_due_on' => '2026-11-01', 'frequency' => 'once']);

        $this->assertSame([$first->id, $second->id], PlannedPayments::outstanding($user->id)->pluck('id')->all());
        $this->assertSame([$first->id, $second->id], PlannedPayments::nextTwelveMonths($user->id)->pluck('plan')->pluck('id')->all());
    }

    private function plan(string $date, string $frequency): PlannedPayment
    {
        return PlannedPayment::create(['user_id' => User::factory()->create()->id, 'name' => 'Plan', 'amount' => '10.00', 'first_due_on' => $date, 'frequency' => $frequency]);
    }
}
