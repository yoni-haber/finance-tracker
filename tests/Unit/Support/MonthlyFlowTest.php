<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\Category;
use App\Models\Transaction;
use App\Support\MonthlyFlow;
use PHPUnit\Framework\TestCase;

final class MonthlyFlowTest extends TestCase
{
    public function test_totals_add_multiple_investments_and_treat_a_one_penny_refund_as_spending(): void
    {
        $spending = new Category(['type' => Category::TYPE_EXPENSE, 'expense_treatment' => Category::TREATMENT_SPENDING]);
        $investment = new Category(['type' => Category::TYPE_EXPENSE, 'expense_treatment' => Category::TREATMENT_INVESTMENT]);
        $saving = new Category(['type' => Category::TYPE_EXPENSE, 'expense_treatment' => Category::TREATMENT_SAVING]);

        $transaction = static function (string $type, string $amount, ?Category $category = null): Transaction {
            $transaction = new Transaction(['type' => $type, 'amount' => $amount]);
            $transaction->setRelation('category', $category);

            return $transaction;
        };

        $this->assertSame([
            'income' => 10000,
            'spending' => -1,
            'invested' => 5000,
            'savings' => 5001,
        ], MonthlyFlow::totals(collect([
            $transaction(Transaction::TYPE_INCOME, '100.00'),
            $transaction(Transaction::TYPE_EXPENSE, '-0.01', $spending),
            $transaction(Transaction::TYPE_EXPENSE, '20.00', $investment),
            $transaction(Transaction::TYPE_EXPENSE, '30.00', $investment),
            $transaction(Transaction::TYPE_EXPENSE, '10.00', $saving),
        ])));
    }
}
