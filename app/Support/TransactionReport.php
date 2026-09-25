<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Transaction;
use Carbon\Carbon;
use DateTimeInterface;
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
        $monthStart = Carbon::create($year, $month, 1);
        assert($monthStart instanceof Carbon);

        return self::projectedForRange(
            $userId,
            $monthStart->startOfDay(),
            $monthStart->copy()->endOfMonth(),
            $categoryId,
        );
    }

    /**
     * Retrieve and expand transactions for an inclusive date range in one query.
     *
     * @param array<int, int>|int|null $categoryId
     * @return Collection<int, Transaction>
     */
    public static function projectedForRange(
        int $userId,
        DateTimeInterface $rangeStart,
        DateTimeInterface $rangeEnd,
        int|array|null $categoryId = null,
    ): Collection {
        $start = Carbon::instance($rangeStart)->copy()->startOfDay();
        $end = Carbon::instance($rangeEnd)->copy()->endOfDay();

        $transactions = Transaction::forUser($userId)
            ->forCategory($categoryId)
            ->with(['category.parent', 'occurrenceExceptions'])
            ->where(function ($query) use ($start, $end): void {
                $query->where(function ($recurring) use ($start, $end): void {
                    $recurring->where('is_recurring', true)
                        ->whereDate('date', '<=', $end)
                        ->where(function ($active) use ($start): void {
                            $active->whereNull('recurring_until')
                                ->orWhereDate('recurring_until', '>=', $start);
                        });
                })->orWhere(function ($oneOff) use ($start, $end): void {
                    $oneOff
                        ->where('is_recurring', false)
                        ->where('date', '>=', $start->toDateString())
                        ->where('date', '<', $end->copy()->addDay()->toDateString());
                });
            })
            ->get();

        return $transactions->flatMap(
            fn (Transaction $transaction): Collection => $transaction->projectOccurrencesForRange($start, $end),
        );
    }
}
