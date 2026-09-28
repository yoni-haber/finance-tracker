<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class ReportInsights
{
    /**
     * @param array{labels: list<string>, income: list<float>, spending: list<float>, savedAndInvested: list<float>} $series
     * @return array{income: int, spending: int, savedAndInvested: int, netCashFlow: int, savingsRate: int|null, lastMonthNet: int, priorMonthNet: int, monthChange: int}
     */
    public static function summary(array $series): array
    {
        $income = self::sumSeries($series['income']);
        $spending = self::sumSeries($series['spending']);
        $saved = self::sumSeries($series['savedAndInvested']);
        $last = count($series['labels']) - 1;
        $lastMonthNet = self::netForIndex($series, $last);
        $priorMonthNet = self::netForIndex($series, $last - 1);

        return [
            'income' => $income,
            'spending' => $spending,
            'savedAndInvested' => $saved,
            'netCashFlow' => $income - $spending - $saved,
            'savingsRate' => $income > 0 ? (int) round($saved * 100 / $income) : null,
            'lastMonthNet' => $lastMonthNet,
            'priorMonthNet' => $priorMonthNet,
            'monthChange' => $lastMonthNet - $priorMonthNet,
        ];
    }

    /**
     * @param Collection<int, Transaction> $transactions
     * @return list<array{category: string, current: int, previous: int, change: int}>
     */
    public static function categoryChanges(Collection $transactions, CarbonImmutable $currentMonth): array
    {
        $previousMonth = $currentMonth->subMonth();
        $months = [$previousMonth->format('Y-m'), $currentMonth->format('Y-m')];

        $totals = $transactions
            ->filter(fn (Transaction $transaction): bool => TransactionImpact::expensePennies($transaction) !== 0
                && ($transaction->category?->effectiveExpenseTreatment() ?? Category::TREATMENT_SPENDING) === Category::TREATMENT_SPENDING
                && in_array($transaction->date->format('Y-m'), $months, true))
            ->groupBy(fn (Transaction $transaction): string => self::categoryName($transaction))
            ->map(function (Collection $items) use ($months): array {
                $first = $items->first();
                assert($first instanceof Transaction);

                return [
                    'previous' => $items->filter(fn (Transaction $transaction): bool => $transaction->date->format('Y-m') === $months[0])
                        ->sum(TransactionImpact::expensePennies(...)),
                    'current' => $items->filter(fn (Transaction $transaction): bool => $transaction->date->format('Y-m') === $months[1])
                        ->sum(TransactionImpact::expensePennies(...)),
                    'category_id' => $first->category->parent_id ?? $first->category_id,
                ];
            });

        return array_values($totals->map(fn (array $amounts, string $category): array => [
            'category' => $category,
            'category_id' => $amounts['category_id'],
            'current' => $amounts['current'],
            'previous' => $amounts['previous'],
            'change' => $amounts['current'] - $amounts['previous'],
        ])->sort(fn (array $a, array $b): int => max($b['current'], $b['previous']) <=> max($a['current'], $a['previous']))
            ->take(6)
            ->all());
    }

    /**
     * @param Collection<int, Transaction> $transactions
     * @return array{labels: list<string>, planned: list<float>, spent: list<float>, hasBudgets: bool}
     */
    public static function budgets(int $userId, Collection $transactions, CarbonImmutable $endMonth, int $monthsCount): array
    {
        $firstMonth = $endMonth->subMonths($monthsCount - 1);
        $budgetsByMonth = Budget::with('category.children')
            ->where('user_id', $userId)
            ->whereRaw('year * 12 + month between ? and ?', [
                $firstMonth->year * 12 + $firstMonth->month,
                $endMonth->year * 12 + $endMonth->month,
            ])
            ->get()
            ->groupBy(fn (Budget $budget): string => sprintf('%04d-%02d', $budget->year, $budget->month));
        $transactionsByMonth = $transactions->groupBy(fn (Transaction $transaction): string => $transaction->date->format('Y-m'));

        $result = ['labels' => [], 'planned' => [], 'spent' => [], 'hasBudgets' => false];
        for ($offset = $monthsCount - 1; $offset >= 0; $offset--) {
            $month = $endMonth->subMonths($offset);
            $key = $month->format('Y-m');
            $budgets = $budgetsByMonth->get($key, collect());
            $progress = BudgetProgress::forPeriod($budgets, $transactionsByMonth->get($key, collect()), $month->month, $month->year);

            $result['labels'][] = $month->format('M Y');
            $result['planned'][] = (float) ($progress->sum(fn (array $row): int => Money::normalize($row['budget'])) / 100);
            $result['spent'][] = (float) ($progress->sum(fn (array $row): int => Money::normalize($row['actual'])) / 100);
            $result['hasBudgets'] = $result['hasBudgets'] || $budgets->isNotEmpty();
        }

        return $result;
    }

    /** @param list<float> $values */
    private static function sumSeries(array $values): int
    {
        return array_sum(array_map(Money::normalize(...), $values));
    }

    /** @param array{labels: list<string>, income: list<float>, spending: list<float>, savedAndInvested: list<float>} $series */
    private static function netForIndex(array $series, int $index): int
    {
        if ($index < 0) {
            return 0;
        }

        return Money::normalize($series['income'][$index])
            - Money::normalize($series['spending'][$index])
            - Money::normalize($series['savedAndInvested'][$index]);
    }

    private static function categoryName(Transaction $transaction): string
    {
        $category = $transaction->category;
        if ($category === null) {
            return 'Uncategorised';
        }

        return $category->parent ? $category->parent->name : $category->name;
    }
}
