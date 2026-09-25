<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Budget;
use App\Models\Transaction;
use Illuminate\Support\Collection;

class BudgetProgress
{
    /**
     * @param Collection<int, Budget> $budgets
     * @param Collection<int, Transaction> $transactions Projected occurrences for the selected month.
     * @return Collection<int, array{category: string, budget: string, actual: string, remaining: string, over: string, overspent: bool, percent: int|null, barPercent: int}>
     */
    public static function forPeriod(Collection $budgets, Collection $transactions, int $month, int $year): Collection
    {
        $periodEnd = SelectedPeriod::clamp($month, $year)->startOfMonth()->endOfMonth();

        $today = now()->endOfDay();
        $cutoff = $periodEnd->lessThan($today) ? $periodEnd : $today;

        $expenses = $transactions->filter(
            fn (Transaction $transaction): bool => $transaction->type === Transaction::TYPE_EXPENSE
                && $transaction->date->lessThanOrEqualTo($cutoff),
        );

        return $budgets->map(function (Budget $budget) use ($expenses): array {
            $limitPennies = Money::normalize($budget->amount);
            $categoryIds = collect([$budget->category_id])
                ->merge($budget->category->children->pluck('id'))
                ->all();

            $spentPennies = $expenses
                ->filter(fn (Transaction $transaction): bool => in_array($transaction->category_id, $categoryIds, true))
                ->sum(fn (Transaction $transaction): int => Money::normalize($transaction->amount));

            $percent = $limitPennies > 0
                ? (int) round($spentPennies * 100 / $limitPennies)
                : ($spentPennies === 0 ? 0 : null);

            return [
                'category' => $budget->category->name,
                'budget' => Money::fromPennies($limitPennies),
                'actual' => Money::fromPennies($spentPennies),
                'remaining' => Money::fromPennies(max(0, $limitPennies - $spentPennies)),
                'over' => Money::fromPennies(max(0, $spentPennies - $limitPennies)),
                'overspent' => $spentPennies > $limitPennies,
                'percent' => $percent,
                'barPercent' => $percent === null ? 100 : min(100, max(0, $percent)),
            ];
        });
    }
}
