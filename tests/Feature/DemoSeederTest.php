<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BankStatementImport;
use App\Models\Budget;
use App\Models\ImportedTransaction;
use App\Models\NetWorthEntry;
use App\Models\NetWorthLineItem;
use App\Models\PlannedPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BankStatementConfig;
use App\Support\BudgetProgress;
use App\Support\PlannedPayments;
use App\Support\TransactionReport;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserAndFinanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_planning_examples_are_independent_of_transactions_and_reseeding_is_idempotent(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 2)->startOfDay());
        $other = Transaction::factory()->create(['description' => 'Other user entry', 'amount' => '42.00']);
        $this->seed(UserAndFinanceSeeder::class);
        $user = User::where('email', 'alex@example.com')->firstOrFail();
        $this->assertSame(['Car insurance renewal', 'Quarterly water bill', 'Planned car service'], PlannedPayments::outstanding($user->id)->pluck('name')->all());
        $insurance = PlannedPayment::where('user_id', $user->id)->where('name', 'Car insurance renewal')->sole();
        $this->assertSame('2026-11-13', $insurance->nextDueDate()?->toDateString());
        $this->assertSame('yearly', $insurance->frequency);
        $this->assertSame('650.00', $insurance->amount);
        $this->assertSame(0, Transaction::where('user_id', $user->id)->whereIn('description', ['Car insurance renewal', 'Quarterly water bill', 'Planned car service'])->count());
        $count = Transaction::forUser($user->id)->count();
        $insurance->update(['completed_occurrences' => 1]);
        $this->seed(UserAndFinanceSeeder::class);
        $this->assertSame(3, PlannedPayment::where('user_id', $user->id)->count());
        $this->assertSame(1, $insurance->refresh()->completed_occurrences);
        $this->assertSame($count, Transaction::forUser($user->id)->count());
        $this->assertSame('42.00', $other->refresh()->amount);
    }

    public function test_demo_data_covers_six_months_and_import_review_states_without_duplicates_on_reseed(): void
    {
        Carbon::setTestNow('2026-01-20 12:00:00');

        try {
            $this->seed(DatabaseSeeder::class);
            $user = User::where('email', 'alex@example.com')->firstOrFail();
            $this->assertSame(36, Budget::where('user_id', $user->id)->count());
            $this->assertSame(6, NetWorthEntry::where('user_id', $user->id)->count());
            $this->assertSame(36, NetWorthLineItem::where('user_id', $user->id)->count());

            for ($monthsAgo = 5; $monthsAgo >= 0; $monthsAgo--) {
                $month = Carbon::now()->startOfMonth()->subMonths($monthsAgo);
                $this->assertSame(6, Budget::where('user_id', $user->id)->where('month', $month->month)->where('year', $month->year)->count());
                $projected = TransactionReport::projectedForMonth($user->id, $month->month, $month->year);
                $this->assertTrue($projected->contains('description', 'Monthly salary'));
                $this->assertTrue($projected->contains('description', 'Weekly groceries'));
                $this->assertTrue(TransactionReport::recordedForRange($user->id, $month, $month->copy()->endOfMonth())
                    ->contains('type', Transaction::TYPE_INCOME));
            }

            $skippedRentMonth = Carbon::now()->startOfMonth()->subMonths(3);
            $this->assertFalse(TransactionReport::projectedForMonth($user->id, $skippedRentMonth->month, $skippedRentMonth->year)->contains('description', 'Apartment rent'));
            $this->assertSame(1, Transaction::where('user_id', $user->id)->whereNull('category_id')->count());

            $overBudgetMonth = Carbon::now()->startOfMonth()->subMonths(4);
            $budgets = Budget::with('category.children')->where('user_id', $user->id)
                ->where('month', $overBudgetMonth->month)->where('year', $overBudgetMonth->year)->get();
            $progress = BudgetProgress::forPeriod(
                $budgets,
                TransactionReport::projectedForMonth($user->id, $overBudgetMonth->month, $overBudgetMonth->year),
                $overBudgetMonth->month,
                $overBudgetMonth->year,
            );
            $foodProgress = $progress->firstWhere('category', 'Food');
            $housingProgress = $progress->firstWhere('category', 'Housing');
            $this->assertNotNull($foodProgress);
            $this->assertNotNull($housingProgress);
            $this->assertTrue($foodProgress['overspent']);
            $this->assertFalse($housingProgress['overspent']);

            $review = BankStatementImport::where('user_id', $user->id)->where('status', BankStatementConfig::STATUS_PARSED)->firstOrFail();
            $committed = BankStatementImport::where('user_id', $user->id)->where('status', BankStatementConfig::STATUS_COMMITTED)->firstOrFail();
            $this->assertSame(6, $review->importedTransactions()->count());
            $this->assertSame(1, $review->importedTransactions()->where('is_duplicate', true)->count());
            $this->assertSame(3, $review->importedTransactions()->whereNull('category_id')->where('is_duplicate', false)->count());
            $this->assertSame(1, Transaction::where('source_import_id', $committed->id)->count());

            $counts = [
                Transaction::where('user_id', $user->id)->count(),
                Budget::where('user_id', $user->id)->count(),
                NetWorthEntry::where('user_id', $user->id)->count(),
                ImportedTransaction::count(),
            ];
            $this->seed(DatabaseSeeder::class);
            $this->assertSame($counts, [
                Transaction::where('user_id', $user->id)->count(),
                Budget::where('user_id', $user->id)->count(),
                NetWorthEntry::where('user_id', $user->id)->count(),
                ImportedTransaction::count(),
            ]);

            Carbon::setTestNow('2026-01-25 12:00:00');
            $this->seed(DatabaseSeeder::class);
            $this->assertSame($counts[0] + 1, Transaction::where('user_id', $user->id)->count());
            $this->assertSame(6, NetWorthEntry::where('user_id', $user->id)->count());
            $this->assertSame(1, Transaction::where('user_id', $user->id)->where('date', '2026-01-21')->where('description', 'Cinema and events')->count());
        } finally {
            Carbon::setTestNow();
        }
    }
}
