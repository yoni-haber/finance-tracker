<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Reports\ReportsHub;
use App\Models\NetWorthEntry;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class ReportsHubTest extends TestCase
{
    use RefreshDatabase;

    public function test_mount_sets_default_range_to_12_months(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ReportsHub::class)
            ->assertSet('range', '12_months');
    }

    public function test_mount_initializes_chart_data_with_required_keys(): void
    {
        $user = User::factory()->create();

        $testable = Livewire::actingAs($user)->test(ReportsHub::class);

        $chartData = $testable->get('chartData');
        $this->assertArrayHasKey('labels', $chartData);
        $this->assertArrayHasKey('income', $chartData);
        $this->assertArrayHasKey('expenses', $chartData);
    }

    public function test_mount_initializes_net_worth_chart_data_with_required_keys(): void
    {
        $user = User::factory()->create();

        $testable = Livewire::actingAs($user)->test(ReportsHub::class);

        $netWorthData = $testable->get('netWorthChartData');
        $this->assertArrayHasKey('labels', $netWorthData);
        $this->assertArrayHasKey('netWorth', $netWorthData);
    }

    public function test_render_passes_range_options_to_view(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ReportsHub::class)
            ->assertViewHas('rangeOptions', [
                '3_months' => 'Last 3 Months',
                '6_months' => 'Last 6 Months',
                '12_months' => 'Last 12 Months',
                'ytd' => 'Year to Date',
            ]);
    }

    public function test_render_passes_chart_data_and_net_worth_chart_data_to_view(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ReportsHub::class)
            ->assertViewHas('chartData')
            ->assertViewHas('netWorthChartData');
    }

    public function test_chart_data_for_3_months_produces_3_labels(): void
    {
        $user = User::factory()->create();

        $testable = Livewire::actingAs($user)
            ->test(ReportsHub::class)
            ->set('range', '3_months'); // triggers updatedRange automatically

        $this->assertCount(3, $testable->get('chartData')['labels']);
    }

    public function test_chart_data_for_6_months_produces_6_labels(): void
    {
        $user = User::factory()->create();

        $testable = Livewire::actingAs($user)
            ->test(ReportsHub::class)
            ->set('range', '6_months');

        $this->assertCount(6, $testable->get('chartData')['labels']);
    }

    public function test_chart_data_for_12_months_produces_12_labels(): void
    {
        $user = User::factory()->create();

        $testable = Livewire::actingAs($user)
            ->test(ReportsHub::class)
            ->set('range', '12_months');

        $this->assertCount(12, $testable->get('chartData')['labels']);
    }

    public function test_chart_data_for_ytd_produces_labels_equal_to_current_month_number(): void
    {
        Carbon::setTestNow('2024-04-15');

        $user = User::factory()->create();

        $testable = Livewire::actingAs($user)
            ->test(ReportsHub::class)
            ->set('range', 'ytd');

        // April is month 4, so YTD covers Jan–Apr = 4 labels
        $this->assertCount(4, $testable->get('chartData')['labels']);
    }

    public function test_chart_data_sums_income_and_expenses_correctly_for_current_month(): void
    {
        Carbon::setTestNow('2024-06-15');

        $user = User::factory()->create();

        Transaction::factory()->for($user)->create([
            'category_id' => null,
            'type' => Transaction::TYPE_INCOME,
            'amount' => '1000.00',
            'date' => '2024-06-10',
            'is_recurring' => false,
            'frequency' => null,
        ]);

        Transaction::factory()->for($user)->create([
            'category_id' => null,
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => '350.00',
            'date' => '2024-06-05',
            'is_recurring' => false,
            'frequency' => null,
        ]);

        $testable = Livewire::actingAs($user)
            ->test(ReportsHub::class)
            ->set('range', '12_months'); // re-set to trigger updatedRange

        $chartData = $testable->get('chartData');
        // June 2024 is the last label in a 12-month window ending today
        $lastIndex = count($chartData['labels']) - 1;
        $this->assertStringContainsString('Jun 2024', (string) $chartData['labels'][$lastIndex]);
        $this->assertEqualsWithDelta(1000.0, $chartData['income'][$lastIndex], PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(350.0, $chartData['expenses'][$lastIndex], PHP_FLOAT_EPSILON);
    }

    public function test_chart_data_does_not_include_other_users_transactions(): void
    {
        Carbon::setTestNow('2024-06-15');

        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Transaction::factory()->for($otherUser)->create([
            'category_id' => null,
            'type' => Transaction::TYPE_INCOME,
            'amount' => '9999.00',
            'date' => '2024-06-10',
            'is_recurring' => false,
            'frequency' => null,
        ]);

        $testable = Livewire::actingAs($user)
            ->test(ReportsHub::class)
            ->set('range', '12_months'); // re-set to trigger updatedRange

        $chartData = $testable->get('chartData');
        $lastIndex = count($chartData['labels']) - 1;

        $this->assertEqualsWithDelta(0.0, $chartData['income'][$lastIndex], PHP_FLOAT_EPSILON);
    }

    public function test_updated_range_resets_to_12_months_when_given_invalid_value(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ReportsHub::class)
            ->set('range', 'not_a_valid_range')
            ->assertSet('range', '12_months');
    }

    public function test_updated_range_dispatches_reports_chart_data_event(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ReportsHub::class)
            ->set('range', '6_months')
            ->assertDispatched('reports-chart-data');
    }

    public function test_updated_range_replaces_chart_data_with_new_range_label_count(): void
    {
        $user = User::factory()->create();

        // Start at 12_months (12 labels), switch to 3_months (3 labels)
        $testable = Livewire::actingAs($user)
            ->test(ReportsHub::class)
            ->set('range', '3_months');

        $this->assertCount(3, $testable->get('chartData')['labels']);
        $this->assertCount(3, $testable->get('chartData')['income']);
        $this->assertCount(3, $testable->get('chartData')['expenses']);
    }

    public function test_net_worth_chart_data_includes_entries_within_last_12_months(): void
    {
        Carbon::setTestNow('2024-06-15');

        $user = User::factory()->create();

        NetWorthEntry::factory()->for($user)->create([
            'date' => '2024-01-10',
            'assets' => '10000.00',
            'liabilities' => '2000.00',
            'net_worth' => '8000.00',
        ]);

        $testable = Livewire::actingAs($user)->test(ReportsHub::class);

        $netWorthData = $testable->get('netWorthChartData');
        $this->assertCount(1, $netWorthData['labels']);
        $this->assertEqualsWithDelta(8000.0, $netWorthData['netWorth'][0], PHP_FLOAT_EPSILON);
    }

    public function test_net_worth_chart_data_excludes_entries_older_than_12_months(): void
    {
        Carbon::setTestNow('2024-06-15');

        $user = User::factory()->create();

        // Clearly outside the 12-month window
        NetWorthEntry::factory()->for($user)->create([
            'date' => '2022-01-01',
            'assets' => '1000.00',
            'liabilities' => '0.00',
            'net_worth' => '1000.00',
        ]);

        // Within the 12-month window
        NetWorthEntry::factory()->for($user)->create([
            'date' => '2024-05-01',
            'assets' => '9000.00',
            'liabilities' => '0.00',
            'net_worth' => '9000.00',
        ]);

        $testable = Livewire::actingAs($user)->test(ReportsHub::class);

        $netWorthData = $testable->get('netWorthChartData');
        $this->assertCount(1, $netWorthData['labels']);
        $this->assertEqualsWithDelta(9000.0, $netWorthData['netWorth'][0], PHP_FLOAT_EPSILON);
        $this->assertNotContains(1000.0, $netWorthData['netWorth']);
    }

    public function test_net_worth_chart_data_does_not_include_other_users_entries(): void
    {
        Carbon::setTestNow('2024-06-15');

        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        NetWorthEntry::factory()->for($otherUser)->create([
            'date' => '2024-05-01',
            'net_worth' => '99999.00',
        ]);

        $testable = Livewire::actingAs($user)->test(ReportsHub::class);

        $netWorthData = $testable->get('netWorthChartData');
        $this->assertEmpty($netWorthData['labels']);
        $this->assertEmpty($netWorthData['netWorth']);
    }

    public function test_net_worth_chart_data_is_ordered_by_date_ascending(): void
    {
        Carbon::setTestNow('2024-06-15');

        $user = User::factory()->create();

        NetWorthEntry::factory()->for($user)->create([
            'date' => '2024-05-01',
            'assets' => '5000.00',
            'liabilities' => '0.00',
            'net_worth' => '5000.00',
        ]);

        NetWorthEntry::factory()->for($user)->create([
            'date' => '2024-02-01',
            'assets' => '3000.00',
            'liabilities' => '0.00',
            'net_worth' => '3000.00',
        ]);

        $testable = Livewire::actingAs($user)->test(ReportsHub::class);

        $netWorthData = $testable->get('netWorthChartData');
        $this->assertEqualsWithDelta(3000.0, $netWorthData['netWorth'][0], PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(5000.0, $netWorthData['netWorth'][1], PHP_FLOAT_EPSILON);
    }

    public function test_net_worth_chart_data_label_format_is_month_day_year(): void
    {
        Carbon::setTestNow('2024-06-15');

        $user = User::factory()->create();

        NetWorthEntry::factory()->for($user)->create([
            'date' => '2024-03-22',
            'assets' => '4000.00',
            'liabilities' => '0.00',
            'net_worth' => '4000.00',
        ]);

        $testable = Livewire::actingAs($user)->test(ReportsHub::class);

        $netWorthData = $testable->get('netWorthChartData');
        $this->assertSame('Mar 22, 2024', $netWorthData['labels'][0]);
    }
}
