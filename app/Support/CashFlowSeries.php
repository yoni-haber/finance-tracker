<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Transaction;
use Carbon\CarbonImmutable;

final class CashFlowSeries
{
    /**
     * @return array{labels: list<string>, income: list<float>, spending: list<float>, invested: list<float>, savings: list<float>}
     */
    public static function endingAt(int $userId, int $month, int $year, int $monthsCount, bool $projected = true): array
    {
        $endMonth = CarbonImmutable::createFromFormat('!Y-n', $year . '-' . $month);
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
            'invested' => [],
            'savings' => [],
        ];

        foreach ($months as $period) {
            $transactions = $transactionsByMonth->get($period->format('Y-m'), collect());
            $totals = MonthlyFlow::totals($transactions);
            $series['labels'][] = $period->format('M Y');
            $series['income'][] = (float) ($totals['income'] / 100);
            $series['spending'][] = (float) ($totals['spending'] / 100);
            $series['invested'][] = (float) ($totals['invested'] / 100);
            $series['savings'][] = (float) ($totals['savings'] / 100);
        }

        return $series;
    }
}
