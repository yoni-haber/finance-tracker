<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Budget;
use App\Models\Category;
use App\Models\PlannedBill;
use App\Models\Transaction;
use App\Models\TransactionException;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class UserAndFinanceSeeder extends Seeder
{
    /**
     * @throws Throwable
     */
    public function run(): void
    {
        DB::transaction(function () {
            $user = $this->seedUser();
            $categories = $this->seedCategories($user);
            $this->seedBudgets($user, $categories);
            $this->seedTransactions($user, $categories);
            $this->seedPlannedBills($user, $categories);
        });
    }

    private function seedUser(): User
    {
        return User::updateOrCreate(
            ['email' => 'alex@example.com'],
            [
                'name' => 'Alex Financier',
                'password' => 'password',
                'email_verified_at' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'remember_token' => Str::random(10),
            ],
        );
    }

    /** @return array<string, Category> */
    private function seedCategories(User $user): array
    {
        return $this->createHierarchy($user, [
            'income' => [
                'Employment' => [
                    'children' => ['Salary', 'Bonus'],
                    'expense_treatment' => null,
                ],
                'Self Employment' => [
                    'children' => ['Freelance'],
                    'expense_treatment' => null,
                ],
            ],
            'expense' => [
                'Housing' => [
                    'children' => ['Rent'],
                    'expense_treatment' => Category::TREATMENT_SPENDING,
                ],
                'Food' => [
                    'children' => ['Groceries', 'Restaurants'],
                    'expense_treatment' => Category::TREATMENT_SPENDING,
                ],
                'Transport' => [
                    'children' => ['Fuel', 'Travel'],
                    'expense_treatment' => Category::TREATMENT_SPENDING,
                ],
                'Bills' => [
                    'children' => ['Utilities'],
                    'expense_treatment' => Category::TREATMENT_SPENDING,
                ],
                'Lifestyle' => [
                    'children' => ['Entertainment'],
                    'expense_treatment' => Category::TREATMENT_SPENDING,
                ],
                'Savings' => [
                    'children' => [],
                    'expense_treatment' => Category::TREATMENT_SAVING,
                ],
                'Investments' => [
                    'children' => [],
                    'expense_treatment' => Category::TREATMENT_INVESTMENT,
                ],
            ],
        ]);
    }

    /**
     * Create a typed parent/subcategory hierarchy for a user.
     * Returns a flat map keyed as "Parent" for parents and "Parent.Child" for subcategories.
     *
     * @param array{
     *     income: array<string, array{children: list<string>, expense_treatment: null}>,
     *     expense: array<string, array{children: list<string>, expense_treatment: string}>
     * } $hierarchy
     * @return array<string, Category>
     */
    private function createHierarchy(User $user, array $hierarchy): array
    {
        $map = [];

        foreach ($hierarchy as $type => $definitions) {
            foreach ($definitions as $parentName => $definition) {
                $parent = Category::updateOrCreate(
                    ['user_id' => $user->id, 'parent_id' => null, 'name' => $parentName],
                    [
                        'type' => $type,
                        'expense_treatment' => $definition['expense_treatment'],
                    ],
                );
                $map[$parentName] = $parent;

                foreach ($definition['children'] as $childName) {
                    $child = Category::updateOrCreate(
                        ['user_id' => $user->id, 'parent_id' => $parent->id, 'name' => $childName],
                        ['type' => $type, 'expense_treatment' => null],
                    );
                    $map["$parentName.$childName"] = $child;
                }
            }
        }

        return $map;
    }

    /**
     * @param array<string, Category> $categories
     */
    private function seedBudgets(User $user, array $categories): void
    {
        $currentMonth = Carbon::now()->startOfMonth();

        for ($monthsAgo = 5; $monthsAgo >= 0; $monthsAgo--) {
            $month = $currentMonth->copy()->subMonths($monthsAgo);
            $index = 5 - $monthsAgo;

            // The lower Food limits and the larger travel month show both over- and under-budget states.
            $amounts = [
                'Housing' => 1800,
                'Food' => [600, 480, 650, 500, 620, 550][$index],
                'Bills' => 220,
                'Transport' => [200, 200, 650, 200, 200, 200][$index],
                'Lifestyle' => [150, 150, 150, 90, 150, 150][$index],
                'Investments' => [400, 400, 400, 500, 400, 400][$index],
            ];

            foreach ($amounts as $category => $amount) {
                Budget::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'category_id' => $categories[$category]->id,
                        'month' => $month->month,
                        'year' => $month->year,
                    ],
                    ['amount' => $amount],
                );
            }
        }
    }

    /** @param array<string, Category> $categories */
    private function seedTransactions(User $user, array $categories): void
    {
        $today = Carbon::now();
        $firstMonth = $today->copy()->startOfMonth()->subMonths(5);
        $end = $today->copy()->addMonth()->endOfMonth();

        $recurring = [
            ['Employment.Salary', Transaction::TYPE_INCOME, 5500, 1, 'Monthly salary', 'monthly'],
            ['Housing.Rent', Transaction::TYPE_EXPENSE, 1750, 2, 'Apartment rent', 'monthly'],
            ['Savings', Transaction::TYPE_EXPENSE, 300, 3, 'Automatic savings transfer', 'monthly'],
            ['Investments', Transaction::TYPE_EXPENSE, 350, 4, 'Index fund contribution', 'monthly'],
            ['Food.Groceries', Transaction::TYPE_EXPENSE, 112.50, 7, 'Weekly groceries', 'weekly'],
            ['Bills.Utilities', Transaction::TYPE_EXPENSE, 14.99, 10, 'Streaming subscription', 'monthly'],
            ['Bills.Utilities', Transaction::TYPE_EXPENSE, 120, 12, 'Annual home insurance', 'yearly'],
        ];

        foreach ($recurring as [$category, $type, $amount, $day, $description, $frequency]) {
            $date = $firstMonth->copy()->addDays($day - 1);
            $transaction = $this->storeTransaction($user, $categories, $category, $type, $amount, $date, $description, $frequency, $end);

            if ($description === 'Apartment rent') {
                TransactionException::firstOrCreate([
                    'transaction_id' => $transaction->id,
                    'date' => $date->copy()->addMonths(2)->toDateString(),
                ]);
            }

            if ($description === 'Weekly groceries') {
                TransactionException::firstOrCreate([
                    'transaction_id' => $transaction->id,
                    'date' => $date->copy()->addWeeks(3)->toDateString(),
                ]);
            }
        }

        // One-off activity changes the chart shapes and gives the budgets distinct outcomes.
        for ($monthsAgo = 5; $monthsAgo >= 0; $monthsAgo--) {
            $month = $today->copy()->startOfMonth()->subMonths($monthsAgo);
            $index = 5 - $monthsAgo;
            $rows = [
                ['Bills.Utilities', Transaction::TYPE_EXPENSE, [98.40, 132.10, 105.60, 188.50, 92.75, 116.20][$index], 6, 'Electricity and gas'],
                ['Food.Restaurants', Transaction::TYPE_EXPENSE, [62, 118, 47, 135, 82, 76][$index], 14, 'Dinner out'],
                ['Transport.Fuel', Transaction::TYPE_EXPENSE, [58, 72, 64, 81, 55, 69][$index], 18, 'Fuel station'],
                ['Lifestyle.Entertainment', Transaction::TYPE_EXPENSE, [45, 80, 35, 120, 64, 55][$index], 21, 'Cinema and events'],
                ['Self Employment.Freelance', Transaction::TYPE_INCOME, [300, 125, 950, 420, 210, 780][$index], 20, 'Freelance project'],
                ['Investments', Transaction::TYPE_EXPENSE, [0, 75, 0, 175, 100, 0][$index], 23, 'Extra investment'],
            ];

            if ($index === 2) {
                $rows[] = ['Transport.Travel', Transaction::TYPE_EXPENSE, 540, 16, 'Conference train and hotel'];
                $rows[] = ['Employment.Bonus', Transaction::TYPE_INCOME, 1200, 25, 'Quarterly bonus'];
            }

            if ($index === 4) {
                $rows[] = ['Food.Groceries', Transaction::TYPE_EXPENSE, 36.50, 9, 'Extra grocery shop'];
            }

            if ($index === 5) {
                $rows[] = [null, Transaction::TYPE_EXPENSE, 42, 15, 'Uncategorised cash purchase'];
            }

            foreach ($rows as [$category, $type, $amount, $day, $description]) {
                if ($amount === 0) {
                    continue;
                }

                if ($monthsAgo === 0 && $day > $today->day) {
                    continue;
                }

                $date = $month->copy()->addDays($day - 1);
                $this->storeTransaction($user, $categories, $category, $type, $amount, $date, $description);
            }
        }
    }

    /** @param array<string, Category> $categories */
    private function storeTransaction(User $user, array $categories, ?string $category, string $type, float $amount, Carbon $date, string $description, ?string $frequency = null, ?Carbon $recurringUntil = null): Transaction
    {
        return Transaction::updateOrCreate(
            ['user_id' => $user->id, 'date' => $date->toDateString(), 'description' => $description],
            [
                'category_id' => $category === null ? null : $categories[$category]->id,
                'type' => $type,
                'amount' => $amount,
                'is_recurring' => $frequency !== null,
                'frequency' => $frequency,
                'recurring_until' => $recurringUntil?->toDateString(),
            ],
        );
    }

    /** @param array<string, Category> $categories */
    private function seedPlannedBills(User $user, array $categories): void
    {
        $priorRenewalDate = Carbon::now()->startOfMonth()->addMonth()->day(12)->subYear();
        $priorRenewal = $this->storeTransaction(
            $user, $categories, 'Transport', Transaction::TYPE_EXPENSE, 640,
            $priorRenewalDate, 'Car insurance renewal',
        );

        $annual = PlannedBill::updateOrCreate(
            ['user_id' => $user->id, 'name' => 'Car insurance'],
            [
                'category_id' => $categories['Transport']->id,
                'estimated_amount' => '640.00',
                'note' => 'Compare renewal quotes before the policy ends.',
                'next_due_date' => $priorRenewalDate->copy()->addYear()->toDateString(),
                'anchor_day' => 12,
                'frequency' => 'yearly',
            ],
        );
        $annual->payments()->firstOrCreate(
            ['expected_date' => $priorRenewalDate->toDateString()],
            ['user_id' => $user->id, 'transaction_id' => $priorRenewal->id],
        );

        PlannedBill::updateOrCreate(
            ['user_id' => $user->id, 'name' => 'Quarterly water rates'],
            [
                'category_id' => $categories['Bills']->id,
                'estimated_amount' => '135.00',
                'note' => 'Estimate based on the previous quarterly statement.',
                'next_due_date' => Carbon::now()->startOfMonth()->day(25)->toDateString(),
                'anchor_day' => 25,
                'frequency' => 'quarterly',
            ],
        );
    }
}
