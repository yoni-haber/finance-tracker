<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\Budget;
use App\Models\Category;
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
            ->assertViewHas('savedAndInvested', '300.00')
            ->assertViewHas('netCashFlow', '1950.00')
            ->assertViewHas('periodLabel', 'May 2024')
            ->assertSee('Net cash flow');

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
            && $trend['savedAndInvested'][5] === 300.0);
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
        $this->assertEquals(0, $view->getData()['savedAndInvested']);
        $this->assertSame('0.00', $view->getData()['netCashFlow']);
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

    public function test_net_cash_flow_can_be_negative(): void
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

        Livewire::actingAs($user)->test(Dashboard::class)->assertViewHas('netCashFlow', '-15.00');
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

        foreach (['Low' => 10, 'High' => 90, 'Over' => 120, 'Unused' => 0] as $name => $spent) {
            $category = Category::factory()->for($user)->expense()->create(['name' => $name]);
            Budget::factory()->for($user)->for($category)->create([
                'month' => 5, 'year' => 2024, 'amount' => 100,
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
            ->assertViewHas('budgetHighlights', fn ($highlights): bool => $highlights->pluck('category')->all() === ['Over', 'High']);
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
}
