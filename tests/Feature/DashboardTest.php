<?php

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

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertStatus(200);
    }

    public function test_mount_sets_current_month_and_year(): void
    {
        Carbon::setTestNow('2024-05-15');

        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertSet('month', 5)
            ->assertSet('year', 2024);
    }

    public function test_render_calculates_dashboard_metrics(): void
    {
        Carbon::setTestNow('2024-05-15');

        $user = User::factory()->create();
        $salaryCategory = Category::factory()->create(['user_id' => $user->id, 'name' => 'Salary']);
        $groceriesCategory = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);

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
        ]);

        $component = Livewire::actingAs($user)->test(Dashboard::class);

        $component
            ->assertViewHas('income', '2500.00')
            ->assertViewHas('expenses', '250.00')
            ->assertViewHas('net', '2250.00');

        $component->assertViewHas('budgetSummaries', function ($summaries) {
            $groceries = $summaries->firstWhere('category', 'Groceries');

            return $groceries['budget'] === '500.00'
                && $groceries['actual'] === '200.00'
                && $groceries['remaining'] === '300.00'
                && $groceries['overspent'] === false;
        });

        $component->assertViewHas('incomeCategoryBreakdown', function ($breakdown) {
            $salary = collect($breakdown)->firstWhere('category', 'Salary');
            $uncategorised = collect($breakdown)->firstWhere('category', 'Uncategorised');

            return $salary['total'] === '2000.00'
                && $uncategorised['total'] === '500.00';
        });

        $component->assertViewHas('expenseCategoryBreakdown', function ($breakdown) {
            $groceries = collect($breakdown)->firstWhere('category', 'Groceries');
            $uncategorised = collect($breakdown)->firstWhere('category', 'Uncategorised');

            return $groceries['total'] === '200.00'
                && $uncategorised['total'] === '50.00';
        });
    }

    public function test_budget_actuals_ignore_future_projected_recurring_transactions(): void
    {
        Carbon::setTestNow('2024-05-10');

        $user = User::factory()->create();
        $groceriesCategory = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);

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
            ->assertViewHas('budgetSummaries', function ($summaries) {
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
            ->assertViewHas('budgetSummaries', function ($summaries) {
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
            ->assertViewHas('expenseCategoryBreakdown', function ($breakdown) {
                $food = collect($breakdown)->firstWhere('category', 'Food');
                $groceries = collect($breakdown)->firstWhere('category', 'Groceries');

                // Both parent and subcategory transactions should be grouped under "Food".
                return $food !== null
                    && $food['total'] === '100.00'
                    && $groceries === null; // Groceries should not appear as its own entry.
            });
    }
}
