<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Budgets\BudgetManager;
use App\Livewire\Dashboard;
use App\Models\Budget;
use App\Models\Category;
use App\Models\PlannedPayment;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $testResponse = $this->get(route('dashboard'));
        $testResponse->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $testResponse = $this->get(route('dashboard'));
        $testResponse->assertStatus(200);
    }

    public function test_mount_sets_current_month_and_year(): void
    {
        Carbon::setTestNow('2024-05-15');

        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertSet('periodMonth', 5)
            ->assertSet('periodYear', 2024);
    }

    public function test_mount_uses_the_users_persisted_period(): void
    {
        $user = User::factory()->create(['selected_month' => 2, 'selected_year' => 2023]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertSet('periodMonth', 2)
            ->assertSet('periodYear', 2023);
    }

    public function test_period_changed_event_updates_the_dashboard_period(): void
    {
        $user = User::factory()->create(['selected_month' => 5, 'selected_year' => 2024]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->dispatch('period-changed', month: 9, year: 2022)
            ->assertSet('periodMonth', 9)
            ->assertSet('periodYear', 2022);
    }

    public function test_period_changed_event_clamps_out_of_range_values(): void
    {
        $user = User::factory()->create(['selected_month' => 5, 'selected_year' => 2024]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->dispatch('period-changed', month: 13, year: 2101)
            ->assertSet('periodMonth', 12)
            ->assertSet('periodYear', 2100);
    }

    public function test_mount_defaults_to_the_current_month_for_a_guest(): void
    {
        Carbon::setTestNow('2024-05-15');

        Livewire::test(Dashboard::class)
            ->assertSet('periodMonth', 5)
            ->assertSet('periodYear', 2024);
    }

    public function test_render_calculates_dashboard_metrics(): void
    {
        Carbon::setTestNow('2024-05-15');

        $user = User::factory()->create();
        $salaryCategory = Category::factory()->for($user)->income()->create(['name' => 'Salary']);
        $groceriesCategory = Category::factory()->for($user)->expense()->create(['name' => 'Groceries']);
        $savingsCategory = Category::factory()->for($user)->expense()->create([
            'name' => 'Savings',
            'expense_treatment' => Category::TREATMENT_SAVING,
        ]);

        Budget::factory()->create([
            'user_id' => $user->id,
            'category_id' => $groceriesCategory->id,
            'month' => 5,
            'year' => 2024,
            'amount' => 500,
        ]);

        $user->transactions()->createMany([
            [
                'category_id' => $salaryCategory->id,
                'type' => Transaction::TYPE_INCOME,
                'amount' => 2000,
                'date' => '2024-05-05',
            ],
            [
                'category_id' => null,
                'type' => Transaction::TYPE_INCOME,
                'amount' => 500,
                'date' => '2024-05-06',
            ],
            [
                'category_id' => $groceriesCategory->id,
                'type' => Transaction::TYPE_EXPENSE,
                'amount' => 200,
                'date' => '2024-05-07',
            ],
            [
                'category_id' => null,
                'type' => Transaction::TYPE_EXPENSE,
                'amount' => 50,
                'date' => '2024-05-08',
            ],
            [
                'category_id' => $savingsCategory->id,
                'type' => Transaction::TYPE_EXPENSE,
                'amount' => 300,
                'date' => '2024-05-09',
            ],
        ]);

        $testable = Livewire::actingAs($user)->test(Dashboard::class);

        $testable
            ->assertViewHas('income', '2500.00')
            ->assertViewHas('spending', '250.00')
            ->assertViewHas('invested', '0.00')
            ->assertViewHas('savings', '2250.00')
            ->assertViewHas('periodLabel', 'May 2024')
            ->assertSee('Expected remainder')
            ->assertDontSee('Estimated month-end remainder')
            ->assertSee('Investment goals appear here after month-end.')
            ->assertDontSee('past investment goals missed');

        $testable->assertDispatched('dashboard-trend-updated');

        $testable->assertViewHas('budgetSummaries', function ($summaries): bool {
            $groceries = $summaries->firstWhere('category', 'Groceries');

            return $groceries['budget'] === '500.00'
                && $groceries['actual'] === '200.00'
                && $groceries['remaining'] === '300.00'
                && $groceries['overspent'] === false;
        });

        $testable->assertViewHas('spendingCategoryBreakdown', function ($breakdown): bool {
            $groceries = collect($breakdown)->firstWhere('category', 'Groceries');
            $uncategorised = collect($breakdown)->firstWhere('category', 'Uncategorised');

            return $groceries['total'] === '200.00'
                && $uncategorised['total'] === '50.00';
        });

        $testable->assertViewHas('trend', fn (array $trend): bool => count($trend['labels']) === 6
            && $trend['labels'][5] === 'May 2024'
            && $trend['income'][5] === 2500.0
            && $trend['spending'][5] === 250.0
            && $trend['invested'][5] === 0.0
            && $trend['savings'][5] === 2250.0);
    }

    public function test_budget_actuals_ignore_future_projected_recurring_transactions(): void
    {
        Carbon::setTestNow('2024-05-10');

        $user = User::factory()->create();
        $groceriesCategory = Category::factory()->for($user)->expense()->create(['name' => 'Groceries']);

        Budget::factory()->create([
            'user_id' => $user->id,
            'category_id' => $groceriesCategory->id,
            'month' => 5,
            'year' => 2024,
            'amount' => 500,
        ]);

        $user->transactions()->create([
            'category_id' => $groceriesCategory->id,
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => 100,
            'date' => '2024-05-01',
            'is_recurring' => true,
            'frequency' => 'weekly',
        ]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertViewHas('budgetSummaries', function ($summaries): bool {
                $groceries = $summaries->firstWhere('category', 'Groceries');

                return $groceries['actual'] === '200.00'
                    && $groceries['remaining'] === '300.00';
            });
    }

    public function test_budget_actuals_include_subcategory_transactions(): void
    {
        Carbon::setTestNow('2024-05-15');

        $user = User::factory()->create();

        // Food parent with Groceries subcategory. Budget is on the parent.
        $foodParent = Category::factory()->for($user)->expense()->create(['name' => 'Food']);
        $groceries = Category::factory()->subcategoryOf($foodParent)->create(['name' => 'Groceries']);

        Budget::factory()->for($user)->for($foodParent)->create([
            'month' => 5,
            'year' => 2024,
            'amount' => 500,
        ]);

        // Transaction assigned to the subcategory, NOT the parent.
        $user->transactions()->create([
            'category_id' => $groceries->id,
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => 150,
            'date' => '2024-05-07',
            'is_recurring' => false,
        ]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertViewHas('budgetSummaries', function ($summaries): bool {
                $food = $summaries->firstWhere('category', 'Food');

                // Subcategory transaction must count towards the parent budget actual.
                return $food !== null
                    && $food['actual'] === '150.00'
                    && $food['remaining'] === '350.00'
                    && $food['overspent'] === false;
            });
    }

    public function test_category_totals_rolls_subcategory_transactions_up_to_parent(): void
    {
        Carbon::setTestNow('2024-05-15');

        $user = User::factory()->create();

        $foodParent = Category::factory()->for($user)->expense()->create(['name' => 'Food']);
        $groceries = Category::factory()->subcategoryOf($foodParent)->create(['name' => 'Groceries']);

        $user->transactions()->createMany([
            [
                'category_id' => $groceries->id,
                'type' => Transaction::TYPE_EXPENSE,
                'amount' => 80,
                'date' => '2024-05-07',
                'is_recurring' => false,
            ],
            [
                'category_id' => $foodParent->id,
                'type' => Transaction::TYPE_EXPENSE,
                'amount' => 20,
                'date' => '2024-05-08',
                'is_recurring' => false,
            ],
        ]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertViewHas('spendingCategoryBreakdown', function ($breakdown) use ($foodParent): bool {
                $food = collect($breakdown)->firstWhere('category', 'Food');
                $groceries = collect($breakdown)->firstWhere('category', 'Groceries');

                // Both parent and subcategory transactions should be grouped under "Food".
                return $food !== null
                    && $food['total'] === '100.00'
                    && $food['category_id'] === $foodParent->id
                    && $food['type'] === Transaction::TYPE_EXPENSE
                    && $groceries === null; // Groceries should not appear as its own entry.
            });
    }

    public function test_render_returns_zeroed_data_when_user_id_is_zero(): void
    {
        // Directly test the component without authentication to cover the userId === 0 branch.
        $dashboard = new Dashboard();
        $dashboard->periodMonth = 5;
        $dashboard->periodYear = 2024;

        // Temporarily clear auth so Auth::id() returns null (cast to int = 0).
        $view = $dashboard->render();

        $this->assertEquals(0, $view->getData()['income']);
        $this->assertEquals(0, $view->getData()['spending']);
        $this->assertSame('0.00', $view->getData()['invested']);
        $this->assertSame('0.00', $view->getData()['savings']);
        $this->assertCount(0, $view->getData()['budgetHighlights']);
    }

    public function test_empty_dashboard_shows_accessible_empty_states(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertSee('No cash flow data for these months yet.')
            ->assertSee('No spending recorded for this period.')
            ->assertSee('No budgets for this period.')
            ->assertSee('No recorded transactions for this period yet.')
            ->assertDontSeeHtml('<canvas id="dashboardTrendChart"');
    }

    public function test_calculated_savings_can_be_negative(): void
    {
        Carbon::setTestNow('2024-05-15');
        $user = User::factory()->create();
        $savings = Category::factory()->for($user)->expense()->create([
            'expense_treatment' => Category::TREATMENT_SAVING,
        ]);

        $user->transactions()->createMany([
            ['type' => Transaction::TYPE_INCOME, 'amount' => 10, 'date' => '2024-05-01'],
            ['type' => Transaction::TYPE_EXPENSE, 'amount' => 20, 'date' => '2024-05-02'],
            ['type' => Transaction::TYPE_EXPENSE, 'category_id' => $savings->id, 'amount' => 5, 'date' => '2024-05-03'],
        ]);

        Livewire::actingAs($user)->test(Dashboard::class)->assertViewHas('savings', '-10.00');
    }

    public function test_zero_amount_transactions_do_not_create_spending_bars(): void
    {
        Carbon::setTestNow('2024-05-15');
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Bills']);
        $user->transactions()->create([
            'category_id' => $category->id,
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => 0,
            'date' => '2024-05-06',
        ]);

        Livewire::actingAs($user)->test(Dashboard::class)
            ->assertViewHas('spendingCategoryBreakdown', fn ($breakdown): bool => $breakdown->isEmpty())
            ->assertSee('No spending recorded for this period.');
    }

    public function test_trend_ends_at_the_selected_historical_month(): void
    {
        Carbon::setTestNow('2024-09-15');
        $user = User::factory()->create(['selected_month' => 5, 'selected_year' => 2024]);
        $user->transactions()->create([
            'type' => Transaction::TYPE_INCOME,
            'amount' => 125,
            'date' => '2024-02-10',
        ]);

        Livewire::actingAs($user)->test(Dashboard::class)
            ->assertViewHas('trend', fn (array $trend): bool => $trend['labels'] === [
                'Dec 2023', 'Jan 2024', 'Feb 2024', 'Mar 2024', 'Apr 2024', 'May 2024',
            ] && $trend['income'][2] === 125.0);
    }

    public function test_budget_highlights_show_only_three_with_overspent_first(): void
    {
        Carbon::setTestNow('2024-05-15');
        $user = User::factory()->create();

        foreach (['Low' => 10, 'High Z' => 90, 'Medium' => 85, 'Over' => 120, 'High A' => 90, 'Unused' => 0, 'Zero' => 5] as $name => $spent) {
            $category = Category::factory()->for($user)->expense()->create(['name' => $name]);
            Budget::factory()->for($user)->for($category)->create([
                'month' => 5, 'year' => 2024, 'amount' => $name === 'Zero' ? 0 : 100,
            ]);
            if ($spent > 0) {
                $user->transactions()->create([
                    'category_id' => $category->id,
                    'type' => Transaction::TYPE_EXPENSE,
                    'amount' => $spent,
                    'date' => '2024-05-10',
                ]);
            }
        }

        Livewire::actingAs($user)->test(Dashboard::class)
            ->assertViewHas('budgetHighlights', fn ($highlights): bool => $highlights->pluck('category')->all() === ['Zero', 'Over', 'High A']);
    }

    public function test_budget_highlights_include_exactly_eighty_percent_but_exclude_lower_utilisation(): void
    {
        Carbon::setTestNow('2024-05-15');
        $user = User::factory()->create();

        foreach (['At threshold' => '80.00', 'Below threshold' => '79.99'] as $name => $spent) {
            $category = Category::factory()->for($user)->expense()->create(['name' => $name]);
            Budget::factory()->for($user)->for($category)->create([
                'month' => 5, 'year' => 2024, 'amount' => 100,
            ]);
            Transaction::factory()->for($user)->for($category)->create([
                'type' => Transaction::TYPE_EXPENSE,
                'amount' => $spent,
                'date' => '2024-05-10',
            ]);
        }

        Livewire::actingAs($user)->test(Dashboard::class)
            ->assertViewHas('budgetHighlights', fn ($highlights): bool => $highlights->pluck('category')->all() === ['At threshold']);
    }

    public function test_recent_activity_is_limited_to_recorded_current_user_entries_through_today(): void
    {
        Carbon::setTestNow('2024-05-15');
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        foreach (range(1, 6) as $day) {
            $user->transactions()->create([
                'type' => Transaction::TYPE_EXPENSE,
                'amount' => $day,
                'description' => "Entry {$day}",
                'date' => "2024-05-0{$day}",
            ]);
        }

        $user->transactions()->create([
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => 10,
            'description' => 'Future entry',
            'date' => '2024-05-20',
        ]);
        $user->transactions()->create([
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => 10,
            'description' => 'Earlier recurring series',
            'date' => '2024-04-01',
            'is_recurring' => true,
            'frequency' => 'weekly',
        ]);
        $otherUser->transactions()->create([
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => 10,
            'description' => 'Another user',
            'date' => '2024-05-14',
        ]);

        Livewire::actingAs($user)->test(Dashboard::class)
            ->assertViewHas('recentTransactions', fn ($items): bool => $items->pluck('description')->all() === [
                'Entry 6', 'Entry 5', 'Entry 4', 'Entry 3', 'Entry 2',
            ]);
    }

    public function test_savings_is_signed_remainder_and_saving_transfers_are_only_activity(): void
    {
        Carbon::setTestNow('2024-06-15');
        $user = User::factory()->create(['selected_month' => 6, 'selected_year' => 2024]);
        $spending = Category::factory()->for($user)->expense()->create();
        $investment = Category::factory()->for($user)->expense()->create([
            'expense_treatment' => Category::TREATMENT_INVESTMENT,
        ]);
        $saving = Category::factory()->for($user)->expense()->create([
            'expense_treatment' => Category::TREATMENT_SAVING,
        ]);

        foreach ([
            ['income', null, '100.00', '2024-04-02'],
            ['expense', $spending->id, '60.00', '2024-04-03'],
            ['expense', $investment->id, '40.00', '2024-04-04'],
            ['expense', $saving->id, '20.00', '2024-04-05'],
            ['income', null, '50.00', '2024-05-02'],
            ['expense', $spending->id, '70.00', '2024-05-03'],
            ['expense', $investment->id, '10.00', '2024-05-04'],
            ['income', null, '100.00', '2024-06-02'],
            ['expense', $spending->id, '10.00', '2024-06-03'],
            ['expense', $investment->id, '20.00', '2024-06-04'],
            ['expense', $saving->id, '15.00', '2024-06-05'],
        ] as [$type, $categoryId, $amount, $date]) {
            $user->transactions()->create([
                'type' => $type, 'category_id' => $categoryId, 'amount' => $amount, 'date' => $date,
            ]);
        }

        Livewire::actingAs($user)->test(Dashboard::class)
            ->assertViewHas('savings', '70.00')
            ->assertViewHas('invested', '20.00')
            ->assertViewHas('trend', fn (array $trend): bool => $trend['savings'][3] === 0.0
                && $trend['savings'][4] === -30.0 && $trend['savings'][5] === 70.0)
            ->assertViewHas('recentTransactions', fn ($items): bool => $items->contains('category_id', $saving->id))
            ->assertSee('Expected remainder')
            ->assertDontSee('Estimated month-end remainder');

        Livewire::actingAs($user)->test(Dashboard::class)
            ->dispatch('period-changed', month: 5, year: 2024)
            ->assertViewHas('savings', '-30.00')
            ->assertSee('−£30.00');
    }

    public function test_investment_goal_shortfall_is_highlighted_only_after_month_end(): void
    {
        Carbon::setTestNow('2024-05-15');
        $user = User::factory()->create(['selected_month' => 5, 'selected_year' => 2024]);
        $investment = Category::factory()->for($user)->expense()->create([
            'name' => 'Investments', 'expense_treatment' => Category::TREATMENT_INVESTMENT,
        ]);
        Budget::factory()->for($user)->for($investment, 'category')->create([
            'month' => 5, 'year' => 2024, 'amount' => '100.00',
        ]);
        $user->transactions()->create([
            'type' => 'expense', 'category_id' => $investment->id,
            'amount' => '40.00', 'date' => '2024-05-02',
        ]);

        Livewire::actingAs($user)->test(Dashboard::class)
            ->assertViewHas('budgetHighlights', fn ($rows): bool => $rows->isEmpty());

        Carbon::setTestNow('2024-06-01');
        Livewire::actingAs($user)->test(Dashboard::class)
            ->assertViewHas('budgetHighlights', fn ($rows): bool => $rows->sole()['remaining'] === '60.00')
            ->assertSee('£60.00 short of goal');

        $user->transactions()->create([
            'type' => 'expense', 'category_id' => $investment->id,
            'amount' => '60.00', 'date' => '2024-05-20',
        ]);
        Livewire::actingAs($user)->test(Dashboard::class)
            ->assertViewHas('budgetHighlights', fn ($rows): bool => $rows->isEmpty());
    }

    public function test_budget_usage_counts_recurring_occurrences_only_through_today(): void
    {
        Carbon::setTestNow('2024-05-10');
        $user = User::factory()->create(['selected_month' => 5, 'selected_year' => 2024]);
        $food = Category::factory()->for($user)->expense()->create(['name' => 'Food']);
        Budget::factory()->for($user)->for($food)->create(['month' => 5, 'year' => 2024, 'amount' => '600.00']);

        $user->transactions()->createMany([
            ['type' => Transaction::TYPE_INCOME, 'amount' => '1000.00', 'date' => '2024-05-02'],
            ['type' => Transaction::TYPE_INCOME, 'amount' => '500.00', 'date' => '2024-05-01', 'is_recurring' => true, 'frequency' => 'monthly'],
            ['type' => Transaction::TYPE_EXPENSE, 'category_id' => $food->id, 'amount' => '200.00', 'date' => '2024-05-03'],
            ['type' => Transaction::TYPE_EXPENSE, 'category_id' => $food->id, 'amount' => '300.00', 'date' => '2024-05-09', 'is_recurring' => true, 'frequency' => 'weekly'],
        ]);

        Livewire::actingAs($user)->test(Dashboard::class)
            ->assertViewHas('income', '1500.00')
            ->assertViewHas('spending', '1400.00')
            ->assertViewHas('savings', '100.00')
            ->assertViewHas('budgetHighlights', fn ($rows): bool => $rows->sole()['actual'] === '500.00'
                && $rows->sole()['status'] === 'near')
            ->assertDontSee('Recurring schedules through today:');
    }

    public function test_budget_statuses_use_exact_limits_and_remain_distinct_from_investment_goals(): void
    {
        Carbon::setTestNow('2024-06-01');
        $user = User::factory()->create(['selected_month' => 5, 'selected_year' => 2024]);

        foreach (['Safe' => ['79.99', '100.00'], 'Near' => ['80.00', '100.00'], 'Almost full' => ['99.99', '100.00'], 'At limit' => ['100.00', '100.00'], 'Over' => ['120.00', '100.00'], 'No limit' => ['1.00', '0.00'], 'Unused zero limit' => ['0.00', '0.00']] as $name => [$spent, $limit]) {
            $category = Category::factory()->for($user)->expense()->create(['name' => $name]);
            Budget::factory()->for($user)->for($category)->create(['month' => 5, 'year' => 2024, 'amount' => $limit]);
            if ($spent !== '0.00') {
                $user->transactions()->create(['type' => Transaction::TYPE_EXPENSE, 'category_id' => $category->id, 'amount' => $spent, 'date' => '2024-05-05']);
            }
        }

        $investment = Category::factory()->for($user)->expense()->create(['name' => 'Investments', 'expense_treatment' => Category::TREATMENT_INVESTMENT]);
        Budget::factory()->for($user)->for($investment)->create(['month' => 5, 'year' => 2024, 'amount' => '100.00']);
        $user->transactions()->create(['type' => Transaction::TYPE_EXPENSE, 'category_id' => $investment->id, 'amount' => '80.00', 'date' => '2024-05-05']);

        $assertStatuses = function ($rows): bool {
            $statuses = $rows->pluck('status', 'category')->all();

            return $statuses === [
                'Safe' => 'safe', 'Near' => 'near', 'Almost full' => 'near', 'At limit' => 'limit',
                'Over' => 'over', 'No limit' => 'over', 'Unused zero limit' => 'safe', 'Investments' => 'goal-short',
            ];
        };

        Livewire::actingAs($user)->test(Dashboard::class)
            ->assertViewHas('budgetSummaries', $assertStatuses)
            ->assertSeeHtml('bg-finance-negative');

        Livewire::actingAs($user)->test(BudgetManager::class)
            ->assertViewHas('budgetSummaries', $assertStatuses)
            ->assertSee('80% used · £20.00 left')
            ->assertSee('Just under 80% used')
            ->assertSee('Just under 100% used · £0.01 left')
            ->assertSee('100% used · £0.00 left')
            ->assertSeeHtml('bg-finance-warning')
            ->assertSeeHtml('bg-finance-negative')
            ->assertSeeHtml('bg-finance-investment');
    }

    public function test_next_payments_keep_their_today_scope_when_selected_month_changes(): void
    {
        Carbon::setTestNow('2024-05-10');
        $user = User::factory()->create(['selected_month' => 5, 'selected_year' => 2024]);
        PlannedPayment::create(['user_id' => $user->id, 'name' => 'Overdue bill', 'amount' => '20.00', 'first_due_on' => '2024-05-01', 'frequency' => 'once']);

        Livewire::actingAs($user)->test(Dashboard::class)
            ->assertSee('May 2024 overview')
            ->assertSee('Next payments from today')
            ->assertSee('Based on today, including overdue plans. Not filtered by month or included in recorded spending.')
            ->assertSee('Overdue bill')
            ->dispatch('period-changed', month: 4, year: 2024)
            ->assertSee('April 2024 overview')
            ->assertSee('Overdue bill');
    }
}
