<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BankStatementImport;
use App\Models\Budget;
use App\Models\ImportedTransaction;
use App\Models\NetWorthEntry;
use App\Models\NetWorthLineItem;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BankStatementConfig;
use App\Support\BudgetProgress;
use App\Support\TransactionReport;
use App\Support\UpcomingPayments;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserAndFinanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_planning_examples_have_future_first_payments_and_reseeding_is_scoped_and_idempotent(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 2));
        $other = Transaction::factory()->create(['description' => 'Other user entry', 'amount' => '42.00']);
        $this->seed(UserAndFinanceSeeder::class);
        $user = User::where('email', 'alex@example.com')->firstOrFail();
        $insurance = Transaction::forUser($user->id)->where('description', 'Car insurance renewal')->sole();
        $water = Transaction::forUser($user->id)->where('description', 'Quarterly water bill')->sole();
        $service = Transaction::forUser($user->id)->where('description', 'Planned car service')->sole();
        $this->assertSame('2026-12-15', $insurance->date->toDateString());
        $this->assertSame('yearly', $insurance->frequency);
        $this->assertTrue($insurance->is_recurring);
        $this->assertNull($insurance->recurring_until);
        $this->assertSame('650.00', $insurance->amount);
        $this->assertSame('2026-11-15', $water->date->toDateString());
        $this->assertSame('quarterly', $water->frequency);
        $this->assertTrue($water->is_recurring);
        $this->assertNull($water->recurring_until);
        $this->assertSame('180.00', $water->amount);
        $this->assertSame('2026-11-25', $service->date->toDateString());
        $this->assertFalse($service->is_recurring);
        $this->assertNull($service->frequency);
        $this->assertNull($service->recurring_until);
        $this->assertSame('240.00', $service->amount);
        $this->assertSame('expense', $insurance->type);
        $this->assertSame('expense', $water->type);
        $this->assertSame('expense', $service->type);
        $forecast = UpcomingPayments::forecast($user->id);
        $this->assertSame(['Quarterly water bill', 'Planned car service', 'Car insurance renewal', 'Quarterly water bill'], $forecast['payments']->pluck('description')->all());
        $this->assertSame(['2026-11-15', '2026-11-25', '2026-12-15', '2027-02-15'], $forecast['payments']->map(fn (Transaction $transaction): string => $transaction->date->toDateString())->all());
        $this->assertSame('1250.00', $forecast['total']);
        $this->assertFalse(TransactionReport::projectedForMonth($user->id, 10, 2026)->contains('description', 'Car insurance renewal'));
        $count = Transaction::forUser($user->id)->count();
        $this->seed(UserAndFinanceSeeder::class);
        $this->assertSame($count, Transaction::forUser($user->id)->count());
        $this->assertSame('42.00', $other->refresh()->amount);
        $this->assertSame('Other user entry', $other->description);

        $this->travelTo(now()->setDate(2026, 11, 2));
        $this->seed(UserAndFinanceSeeder::class);
        $this->assertSame('2026-12-15', $water->refresh()->date->toDateString());
        $this->assertSame('2026-12-25', $service->refresh()->date->toDateString());
        $this->assertSame('2026-12-15', $insurance->refresh()->date->toDateString());
        $this->travelTo(now()->setDate(2026, 12, 16));
        $this->seed(UserAndFinanceSeeder::class);
        $this->assertSame('2027-12-15', $insurance->refresh()->date->toDateString());
        $this->assertSame(3, Transaction::forUser($user->id)->whereIn('description', ['Car insurance renewal', 'Quarterly water bill', 'Planned car service'])->count());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function planningSeedDates(): iterable
    {
        yield 'before December' => ['2026-01-20', '2026-12-15', '2026-02-15'];

        yield 'due today' => ['2026-12-15', '2026-12-15', '2027-01-15'];

        yield 'past December due date' => ['2026-12-16', '2027-12-15', '2027-01-15'];

        yield 'year end' => ['2026-12-31', '2027-12-15', '2027-01-15'];

        yield 'short next month' => ['2028-01-31', '2028-12-15', '2028-02-15'];
    }

    #[DataProvider('planningSeedDates')]
    public function test_planning_seeds_choose_the_next_december_due_date_and_do_not_overflow_months(string $today, string $insuranceDate, string $waterDate): void
    {
        $this->travelTo(Carbon::parse($today . ' 12:00:00'));
        $this->seed(UserAndFinanceSeeder::class);
        $insurance = Transaction::where('description', 'Car insurance renewal')->sole();
        $water = Transaction::where('description', 'Quarterly water bill')->sole();
        $this->assertSame($insuranceDate, $insurance->date->toDateString());
        $this->assertSame($waterDate, $water->date->toDateString());
        $this->assertSame(substr($waterDate, 0, 8) . '25', Transaction::where('description', 'Planned car service')->sole()->date->toDateString());
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
