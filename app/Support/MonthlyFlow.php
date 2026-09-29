<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Collection;

final class MonthlyFlow
{
    /**
     * @param Collection<int, Transaction> $transactions
     * @return array{income: int, spending: int, invested: int, savings: int}
     */
    public static function totals(Collection $transactions): array
    {
        $income = 0;
        $spending = 0;
        $invested = 0;

        foreach ($transactions as $transaction) {
            $income += TransactionImpact::incomePennies($transaction);
            $expense = TransactionImpact::expensePennies($transaction);

            if ($expense === 0) {
                continue;
            }

            $treatment = $transaction->category?->effectiveExpenseTreatment() ?? Category::TREATMENT_SPENDING;
            if ($treatment === Category::TREATMENT_SPENDING) {
                $spending += $expense;
            } elseif ($treatment === Category::TREATMENT_INVESTMENT) {
                $invested += $expense;
            }
            // Saving transactions document transfers; the remainder already includes them.
        }

        return [
            'income' => $income,
            'spending' => $spending,
            'invested' => $invested,
            'savings' => $income - $spending - $invested,
        ];
    }
}
