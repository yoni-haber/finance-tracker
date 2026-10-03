<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class UpcomingPayments
{
    /**
     * @param int $months A validated horizon of 3, 6 or 12 calendar months, including this month.
     * @return array{start: Carbon, end: Carbon, payments: Collection<int, Transaction>, months: Collection<int, array{label: string, payments: Collection<int, Transaction>, total: string}>, total: string}
     */
    public static function forecast(int $userId, int $months = 6, bool $includeRegular = false, string $minimum = ''): array
    {
        $start = today();
        $end = $start->copy()->startOfMonth()->addMonths($months - 1)->endOfMonth();
        $minimumPennies = $minimum === '' ? 0 : Money::normalize($minimum);

        $payments = TransactionReport::projectedForRange($userId, $start, $end)
            ->filter(fn (Transaction $transaction): bool => $transaction->type === Transaction::TYPE_EXPENSE
                && ($transaction->category?->effectiveExpenseTreatment() ?? Category::TREATMENT_SPENDING) === Category::TREATMENT_SPENDING
                && ($includeRegular || !$transaction->is_recurring || in_array($transaction->frequency, ['quarterly', 'yearly'], true))
                && Money::normalize($transaction->amount) >= $minimumPennies)
            ->sort(fn (Transaction $a, Transaction $b): int => ($a->date <=> $b->date) ?: $a->id <=> $b->id)
            ->values();

        $grouped = $payments->groupBy(fn (Transaction $transaction): string => $transaction->date->format('Y-m'));
        $monthRows = collect();

        for ($index = 0; $index < $months; $index++) {
            $month = $start->copy()->startOfMonth()->addMonths($index);
            $items = $grouped->get($month->format('Y-m'), collect());
            $monthRows->push([
                'label' => $month->format('F Y'),
                'payments' => $items,
                'total' => Money::fromPennies($items->sum(TransactionImpact::expensePennies(...))),
            ]);
        }

        return [
            'start' => $start,
            'end' => $end,
            'payments' => $payments,
            'months' => $monthRows,
            'total' => Money::fromPennies($payments->sum(TransactionImpact::expensePennies(...))),
        ];
    }
}
