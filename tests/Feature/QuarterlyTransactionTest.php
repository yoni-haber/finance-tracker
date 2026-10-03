<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\Reports\ReportsHub;
use App\Livewire\Transactions\TransactionManager;
use App\Models\Transaction;
use App\Models\User;
use App\Support\TransactionReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class QuarterlyTransactionTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{string, string, string, list<string>}> */
    public static function ranges(): iterable
    {
        yield 'clamp and restore' => ['2023-08-31', '2023-08-01', '2024-08-31', ['2023-08-31', '2023-11-30', '2024-02-29', '2024-05-31', '2024-08-31']];

        yield 'non-leap February' => ['2024-08-31', '2024-08-31', '2025-05-31', ['2024-08-31', '2024-11-30', '2025-02-28', '2025-05-31']];

        yield 'before anchor day' => ['2024-01-31', '2024-07-01', '2024-07-31', ['2024-07-31']];

        yield 'on anchor day' => ['2024-01-31', '2024-07-31', '2024-07-31', ['2024-07-31']];

        yield 'after anchor day' => ['2024-01-31', '2024-08-01', '2024-10-31', ['2024-10-31']];

        yield 'between quarter months' => ['2024-01-31', '2024-05-01', '2024-06-30', []];

        yield 'distant range' => ['2000-01-31', '2099-02-01', '2099-10-31', ['2099-04-30', '2099-07-31', '2099-10-31']];
    }

    /** @param list<string> $expected */
    #[DataProvider('ranges')]
    public function test_quarterly_projection_is_anchored_and_range_bounded(string $anchor, string $start, string $end, array $expected): void
    {
        $transaction = Transaction::factory()->recurring('quarterly')->create(['date' => $anchor]);
        $dates = $transaction->projectOccurrencesForRange(Carbon::parse($start), Carbon::parse($end))
            ->map(fn (Transaction $transaction): string => $transaction->date->toDateString())->all();
        $this->assertSame($expected, $dates);
        $this->assertSame($anchor, $transaction->date->toDateString());
    }

    public function test_quarterly_end_is_inclusive_and_skips_only_exact_occurrences(): void
    {
        $transaction = Transaction::factory()->recurring('quarterly')->create([
            'date' => '2024-01-31', 'recurring_until' => '2024-10-31',
        ]);
        $transaction->occurrenceExceptions()->create(['date' => '2024-04-30']);
        $transaction->occurrenceExceptions()->create(['date' => '2024-07-30']);
        $dates = $transaction->projectOccurrencesForRange(Carbon::parse('2024-01-01'), Carbon::parse('2025-01-31'))
            ->map(fn (Transaction $transaction): string => $transaction->date->toDateString())->all();
        $this->assertSame(['2024-01-31', '2024-07-31', '2024-10-31'], $dates);
        $transaction->setAttribute('recurring_until', '2024-10-30');
        $this->assertCount(2, $transaction->projectOccurrencesForRange(Carbon::parse('2024-01-01'), Carbon::parse('2025-01-31')));
    }

    public function test_quarterly_form_saves_edits_and_previews_exactly_three_dates(): void
    {
        $user = User::factory()->create();
        $testable = Livewire::actingAs($user)->test(TransactionManager::class)
            ->set('date', '2023-08-31')->set('amount', '123.45')->set('is_recurring', true)->set('frequency', 'quarterly')
            ->assertSee('31 Aug 2023')->assertSee('30 Nov 2023')->assertSee('29 Feb 2024')->assertDontSee('31 May 2024');
        $component = $testable->instance();
        $this->assertInstanceOf(TransactionManager::class, $component);
        $this->assertSame(['31 Aug 2023', '30 Nov 2023', '29 Feb 2024'], $component->recurringPreview());
        $testable->set('recurring_until', '2023-11-30')->assertSee('30 Nov 2023')->assertDontSee('29 Feb 2024')
            ->call('save')->assertHasNoErrors();
        $transaction = Transaction::sole();
        $this->assertSame('quarterly', $transaction->frequency);
        $testable->call('edit', $transaction->id)->assertSet('frequency', 'quarterly')
            ->set('amount', '456.78')->call('save')->assertHasNoErrors();
        $this->assertSame('456.78', $transaction->refresh()->amount);
    }

    public function test_quarterly_occurrences_flow_into_existing_monthly_dashboard_and_reports(): void
    {
        $this->travelTo(now()->setDate(2026, 12, 31));
        $user = User::factory()->create(['selected_month' => 12, 'selected_year' => 2026]);
        Transaction::factory()->for($user)->recurring('quarterly')->create([
            'type' => 'expense', 'category_id' => null, 'amount' => '123.45', 'date' => '2026-09-30',
        ]);
        $this->assertSame('123.45', (string) TransactionReport::projectedForMonth($user->id, 12, 2026)->sole()->amount);
        $this->assertTrue(TransactionReport::projectedForMonth($user->id, 11, 2026)->isEmpty());
        Livewire::actingAs($user)->test(Dashboard::class)->assertViewHas('spending', '123.45');
        Livewire::test(ReportsHub::class)->assertSee('£123.45');
    }
}
