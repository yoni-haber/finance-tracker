<?php

namespace Database\Seeders;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionException;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserAndFinanceSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $now = Carbon::now();

            $user = $this->seedUser();
            $categories = $this->seedCategories($user);

            $this->seedBudgets($user, $categories, [
                [
                    'category' => 'Housing',
                    'month' => $now->month,
                    'year' => $now->year,
                    'amount' => 1800.00,
                ],
                [
                    'category' => 'Food',
                    'month' => $now->month,
                    'year' => $now->year,
                    'amount' => 650.00,
                ],
                [
                    'category' => 'Bills',
                    'month' => $now->copy()->subMonth()->month,
                    'year' => $now->copy()->subMonth()->year,
                    'amount' => 220.00,
                ],
                [
                    'category' => 'Transport',
                    'month' => $now->copy()->addMonths(2)->month,
                    'year' => $now->copy()->addMonths(2)->year,
                    'amount' => 1200.00,
                ],
            ]);

            $this->seedTransactions($user, $categories);
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

    private function seedCategories(User $user): array
    {
        return $this->createHierarchy($user, [
            'income' => [
                'Employment' => ['Salary', 'Bonus'],
                'Self Employment' => ['Freelance'],
            ],
            'expense' => [
                'Housing' => ['Rent'],
                'Food' => ['Groceries', 'Restaurants'],
                'Transport' => ['Fuel', 'Travel'],
                'Bills' => ['Utilities'],
                'Lifestyle' => ['Entertainment'],
                'Savings' => [],
            ],
        ]);
    }

    /**
     * Create a typed parent/subcategory hierarchy for a user.
     * Returns a flat map keyed as "Parent" for parents and "Parent.Child" for subcategories.
     */
    private function createHierarchy(User $user, array $hierarchy): array
    {
        $map = [];

        foreach (['income', 'expense'] as $type) {
            foreach ($hierarchy[$type] ?? [] as $parentName => $children) {
                $parent = Category::updateOrCreate(
                    ['user_id' => $user->id, 'parent_id' => null, 'name' => $parentName],
                    ['type' => $type],
                );
                $map[$parentName] = $parent;

                foreach ($children as $childName) {
                    $child = Category::updateOrCreate(
                        ['user_id' => $user->id, 'parent_id' => $parent->id, 'name' => $childName],
                        ['type' => $type],
                    );
                    $map["{$parentName}.{$childName}"] = $child;
                }
            }
        }

        return $map;
    }

    private function seedBudgets(User $user, array $categories, array $budgetDefinitions): void
    {
        foreach ($budgetDefinitions as $definition) {
            Budget::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'category_id' => $categories[$definition['category']]->id,
                    'month' => $definition['month'],
                    'year' => $definition['year'],
                ],
                ['amount' => $definition['amount']],
            );
        }
    }

    private function seedTransactions(User $user, array $categories): void
    {
        $now = Carbon::now();

        $transactions = [
            // Income — recurring salary with exceptions.
            [
                'category' => $categories['Employment.Salary'],
                'type' => Transaction::TYPE_INCOME,
                'amount' => 5500.00,
                'date' => $now->copy()->subMonths(1)->startOfMonth(),
                'is_recurring' => true,
                'frequency' => 'monthly',
                'recurring_until' => $now->copy()->addMonths(6),
                'description' => 'Full-time salary (recurring)',
            ],
            [
                'category' => $categories['Self Employment.Freelance'],
                'type' => Transaction::TYPE_INCOME,
                'amount' => 850.00,
                'date' => $now->copy()->subWeeks(2),
                'is_recurring' => false,
                'frequency' => null,
                'recurring_until' => null,
                'description' => 'Freelance web design gig',
            ],

            // Expenses spread across subcategories.
            [
                'category' => $categories['Housing.Rent'],
                'type' => Transaction::TYPE_EXPENSE,
                'amount' => 1750.00,
                'date' => $now->copy()->startOfMonth(),
                'is_recurring' => true,
                'frequency' => 'monthly',
                'recurring_until' => $now->copy()->addMonths(11),
                'description' => 'Apartment rent recurring',
            ],
            [
                'category' => $categories['Bills.Utilities'],
                'type' => Transaction::TYPE_EXPENSE,
                'amount' => 95.40,
                'date' => $now->copy()->subMonth()->startOfMonth()->addDays(5),
                'is_recurring' => false,
                'frequency' => null,
                'recurring_until' => null,
                'description' => 'Electricity bill (prior month)',
            ],
            [
                'category' => $categories['Bills.Utilities'],
                'type' => Transaction::TYPE_EXPENSE,
                'amount' => 0.00,
                'date' => $now->copy()->startOfMonth()->addDays(5),
                'is_recurring' => false,
                'frequency' => null,
                'recurring_until' => null,
                'description' => 'Utility credit from provider',
            ],
            [
                'category' => $categories['Food.Groceries'],
                'type' => Transaction::TYPE_EXPENSE,
                'amount' => 125.80,
                'date' => $now->copy()->subDays(10),
                'is_recurring' => false,
                'frequency' => null,
                'recurring_until' => null,
                'description' => 'Weekly grocery run',
            ],
            [
                'category' => $categories['Lifestyle.Entertainment'],
                'type' => Transaction::TYPE_EXPENSE,
                'amount' => 64.99,
                'date' => $now->copy()->subDays(3),
                'is_recurring' => false,
                'frequency' => null,
                'recurring_until' => null,
                'description' => 'Concert ticket',
            ],
            [
                'category' => $categories['Transport.Travel'],
                'type' => Transaction::TYPE_EXPENSE,
                'amount' => 480.00,
                'date' => $now->copy()->addMonths(1)->startOfMonth(),
                'is_recurring' => false,
                'frequency' => null,
                'recurring_until' => null,
                'description' => 'Flight booking for conference',
            ],
            [
                'category' => $categories['Savings'],
                'type' => Transaction::TYPE_EXPENSE,
                'amount' => 300.00,
                'date' => $now->copy()->startOfMonth()->addDays(2),
                'is_recurring' => true,
                'frequency' => 'monthly',
                'recurring_until' => $now->copy()->addMonths(4),
                'description' => 'Automatic savings transfer',
            ],
        ];

        $recurringExceptionSeeds = [];

        foreach ($transactions as $data) {
            $transaction = Transaction::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'date' => $data['date']->toDateString(),
                    'amount' => $data['amount'],
                    'description' => $data['description'],
                ],
                [
                    'category_id' => $data['category']?->id,
                    'type' => $data['type'],
                    'is_recurring' => $data['is_recurring'],
                    'frequency' => $data['frequency'],
                    'recurring_until' => $data['recurring_until'],
                ],
            );

            if ($transaction->description === 'Apartment rent recurring') {
                $recurringExceptionSeeds[] = [
                    'transaction' => $transaction,
                    'dates' => [$data['date']->copy()->addMonths(2)],
                ];
            }

            if ($transaction->description === 'Automatic savings transfer') {
                $recurringExceptionSeeds[] = [
                    'transaction' => $transaction,
                    'dates' => [$data['date']->copy()->addMonths(3)->addDay()],
                ];
            }
        }

        foreach ($recurringExceptionSeeds as $exceptionSeed) {
            foreach ($exceptionSeed['dates'] as $exceptionDate) {
                TransactionException::updateOrCreate(
                    [
                        'transaction_id' => $exceptionSeed['transaction']->id,
                        'date' => $exceptionDate->toDateString(),
                    ],
                );
            }
        }
    }
}
