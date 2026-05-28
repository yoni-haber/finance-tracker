<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\Money;
use App\Support\TransactionReport;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    public int $month;

    public int $year;

    public function mount(): void
    {
        $now = now();
        $this->month = $now->month;
        $this->year = $now->year;
    }

    public function render(): View
    {
        $userId = Auth::id();

        if (!$userId) {
            return view('livewire.dashboard', [
                'income' => 0,
                'expenses' => 0,
                'net' => 0,
                'budgetSummaries' => collect(),
                'incomeCategoryBreakdown' => collect(),
                'expenseCategoryBreakdown' => collect(),
            ]);
        }

        $transactions = TransactionReport::projectedForMonth($userId, $this->month, $this->year);

        $income = Money::fromPennies(
            Money::normalize(
                $transactions->where('type', Transaction::TYPE_INCOME)->sum('amount'),
            ),
        );

        $expenses = Money::fromPennies(
            Money::normalize(
                $transactions->where('type', Transaction::TYPE_EXPENSE)->sum('amount'),
            ),
        );

        $net = Money::subtract($income, $expenses);

        $budgets = Budget::with('category.children')
            ->where('user_id', $userId)
            ->where('month', $this->month)
            ->where('year', $this->year)
            ->get();

        $now = now();
        $periodEnd = Carbon::create($this->year, $this->month)->endOfMonth();

        if ($now->isSameMonth($periodEnd)) {
            $periodEnd = $now->copy()->endOfDay();
        }

        $budgetSummaries = $budgets->map(function (Budget $budget) use ($transactions, $periodEnd): array {
            $budgetPennies = Money::normalize($budget->amount);

            // Include transactions assigned to the parent category AND all its subcategories.
            $categoryIds = collect([$budget->category_id])
                ->merge($budget->category->children->pluck('id'))
                ->all();

            $spentPennies = Money::normalize(
                $transactions
                    // Avoid counting projected recurring entries that fall later in the current month
                    // so "actual" reflects spending up to the present day.
                    ->filter(fn ($transaction) => $transaction->date->lessThanOrEqualTo($periodEnd))
                    ->filter(fn ($transaction): bool => in_array($transaction->category_id, $categoryIds))
                    ->where('type', Transaction::TYPE_EXPENSE)
                    ->sum('amount'),
            );

            return [
                'category' => $budget->category->name,
                'budget' => Money::fromPennies($budgetPennies),
                'actual' => Money::fromPennies($spentPennies),
                'remaining' => Money::fromPennies($budgetPennies - $spentPennies),
                'overspent' => $spentPennies > $budgetPennies,
            ];
        });

        // Build a category-id → parent-name map for the pie chart rollup.
        // Subcategory amounts are grouped under their parent's name.
        $categoryParentNames = Category::forUser($userId)
            ->with('parent:id,name')
            ->get()
            ->mapWithKeys(fn ($cat): array => [
                $cat->id => $cat->parent ? $cat->parent->name : $cat->name,
            ]);

        $enumerable = $this->categoryTotals($transactions, Transaction::TYPE_INCOME, $categoryParentNames);
        $categoryExpenses = $this->categoryTotals($transactions, Transaction::TYPE_EXPENSE, $categoryParentNames);

        $this->dispatch('dashboard-charts-updated',
            incomeCategoryBreakdown: $enumerable->all(),
            expenseCategoryBreakdown: $categoryExpenses->all(),
        );

        return view('livewire.dashboard', [
            'income' => $income,
            'expenses' => $expenses,
            'net' => $net,
            'budgetSummaries' => $budgetSummaries,
            'incomeCategoryBreakdown' => $enumerable,
            'expenseCategoryBreakdown' => $categoryExpenses,
        ]);
    }

    /**
     * @param Collection<int, Transaction> $transactions
     * @param Collection<int, Category> $categoryParentNames
     * @return Collection<int, array{category: int|string, total: string}>
     */
    private function categoryTotals(Collection $transactions, string $type, Collection $categoryParentNames): Enumerable
    {
        return $transactions
            ->where('type', $type)
            ->groupBy(function ($t) use ($categoryParentNames) {
                if (!$t->category_id) {
                    return 'Uncategorised';
                }

                // Roll subcategory amounts up to the parent name.
                return $categoryParentNames->get($t->category_id) ?? $t->category->name ?? 'Uncategorised';
            })
            ->map(fn ($items, $category): array => [
                'category' => $category,
                'total' => Money::fromPennies(
                    Money::normalize($items->sum('amount')),
                ),
            ])->values();
    }
}
