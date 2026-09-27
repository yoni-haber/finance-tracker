<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Category;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class CashFlowSeries
{
    /**
     * @return array{labels: list<string>, income: list<float>, spending: list<float>, savedAndInvested: list<float>}
     */
    public static function endingAt(int $userId, int $month, int $year, int $monthsCount, bool $projected = true): array
    {
        $endMonth = CarbonImmutable::create($year, $month, 1);
        assert($endMonth instanceof CarbonImmutable);

        $months = collect(range($monthsCount - 1, 0))
            ->map(fn (int $offset): CarbonImmutable => $endMonth->subMonths($offset));
        $firstMonth = $months->first();
        assert($firstMonth instanceof CarbonImmutable);

        $transactions = $projected
            ? TransactionReport::projectedForRange($userId, $firstMonth->startOfMonth(), $endMonth->endOfMonth())
            : TransactionReport::recordedForRange($userId, $firstMonth->startOfMonth(), $endMonth->endOfMonth());
        $transactionsByMonth = $transactions->groupBy(fn (Transaction $transaction): string => $transaction->date->format('Y-m'));

        $series = [
            'labels' => [],
            'income' => [],
            'spending' => [],
            'savedAndInvested' => [],
        ];

        foreach ($months as $period) {
            $transactions = $transactionsByMonth->get($period->format('Y-m'), collect());
            $expenses = $transactions->filter(fn (Transaction $transaction): bool => TransactionImpact::expensePennies($transaction) !== 0);

            $series['labels'][] = $period->format('M Y');
            $series['income'][] = (float) ($transactions->sum(TransactionImpact::incomePennies(...)) / 100);
            $series['spending'][] = self::expenseTotal($expenses->filter(
                fn (Transaction $transaction): bool => self::expenseTreatment($transaction) === Category::TREATMENT_SPENDING,
            ));
            $series['savedAndInvested'][] = self::expenseTotal($expenses->reject(
                fn (Transaction $transaction): bool => self::expenseTreatment($transaction) === Category::TREATMENT_SPENDING,
            ));
        }

        return $series;
    }

    /** @param Collection<int, Transaction> $transactions */
    private static function expenseTotal(Collection $transactions): float
    {
        return $transactions->sum(TransactionImpact::expensePennies(...)) / 100;
    }

    private static function expenseTreatment(Transaction $transaction): string
    {
        return $transaction->category?->effectiveExpenseTreatment() ?? Category::TREATMENT_SPENDING;
    }
}
