<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Transaction;
use Illuminate\Support\Collection;

class TransactionReport
{
    /**
     * Retrieves all transactions (including recurring ones expanded into their occurrences)
     * for a given user, month, and year, optionally filtered by category.
     *
     * @param array<int, int>|int|null $categoryId
     * @return Collection<int, Transaction>
     */
    public static function projectedForMonth(int $userId, int $month, int $year, int|array|null $categoryId = null): Collection
    {
        // Build the base query for the users transactions, optionally filtered by category
        $builder = Transaction::forUser($userId)
            ->forCategory($categoryId)
            // Eager load category and occurrenceExceptions relationships to avoid N+1 issues
            ->with(['category', 'occurrenceExceptions'])
            // Filter transactions to include non-recurring ones in the specified month/year and all recurring ones
            ->where(function ($q) use ($month, $year): void {
                $q->where('is_recurring', true)
                    ->orWhere(fn ($q) => $q
                        ->where('is_recurring', false)
                        ->forMonthYear($month, $year),
                    );
            });

        // Fetch the transactions and expand recurring ones into their projected occurrences for the specified month/year
        return $builder->get()->flatMap(
            fn (Transaction $transaction): Collection => $transaction->projectOccurrencesForMonth($month, $year),
        );
    }
}
