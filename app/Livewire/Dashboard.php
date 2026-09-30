<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Livewire\Concerns\InteractsWithSelectedPeriod;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\BudgetProgress;
use App\Support\CashFlowSeries;
use App\Support\Money;
use App\Support\MonthlyFlow;
use App\Support\PlannedBillReport;
use App\Support\SelectedPeriod;
use App\Support\TransactionImpact;
use App\Support\TransactionReport;
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
    use InteractsWithSelectedPeriod;

    public function render(): View
    {
        $userId = (int) Auth::id();

        $transactions = TransactionReport::projectedForMonth($userId, $this->periodMonth, $this->periodYear);

        $totals = MonthlyFlow::totals($transactions);
        $income = Money::fromPennies($totals['income']);

        $spendingTransactions = $transactions->filter(
            fn (Transaction $transaction): bool => $this->expenseTreatment($transaction) === Category::TREATMENT_SPENDING,
        );
        $spending = Money::fromPennies($totals['spending']);
        $invested = Money::fromPennies($totals['invested']);
        $savings = Money::fromPennies($totals['savings']);

        $budgets = Budget::with('category.children')
            ->where('user_id', $userId)
            ->where('month', $this->periodMonth)
            ->where('year', $this->periodYear)
            ->get();

        $budgetSummaries = BudgetProgress::forPeriod($budgets, $transactions, $this->periodMonth, $this->periodYear);

        // Build a category-id -> parent identity map for the spending rollup.
        // Subcategory amounts are grouped under their parent's name.
        $categoryParents = Category::forUser($userId)
            ->with('parent:id,name')
            ->get()
            ->mapWithKeys(fn (Category $category): array => [
                $category->id => [
                    'id' => $category->parent ? $category->parent->id : $category->id,
                    'name' => $category->parent ? $category->parent->name : $category->name,
                ],
            ]);

        $enumerable = $this->categoryTotals($spendingTransactions, Transaction::TYPE_EXPENSE, $categoryParents)
            ->filter(fn (array $item): bool => Money::normalize($item['total']) > 0)
            ->sortByDesc(fn (array $item): int => Money::normalize($item['total']))
            ->values();

        $trend = CashFlowSeries::endingAt($userId, $this->periodMonth, $this->periodYear, 6);
        $budgetHighlights = $budgetSummaries
            ->filter(fn (array $row): bool => $row['isInvestment']
                ? $this->selectedPeriod()->startOfMonth()->endOfMonth()->isPast() && !$row['goalMet']
                : $row['overspent'] || ($row['percent'] !== null && $row['percent'] >= 80))
            ->sort(fn (array $a, array $b): int => ($b['overspent'] <=> $a['overspent'])
                ?: (($b['percent'] === null) <=> ($a['percent'] === null))
                ?: ($b['percent'] <=> $a['percent'])
                ?: strcmp($a['category'], $b['category']))
            ->take(3)
            ->values();

        $recentTransactions = Transaction::forUser($userId)
            ->with('category')
            ->whereYear('date', $this->periodYear)
            ->whereMonth('date', $this->periodMonth)
            ->whereDate('date', '<=', today())
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $selectedMonth = $this->selectedPeriod()->startOfMonth();
        $followingMonth = $selectedMonth->addMonth();
        $thirdMonth = $selectedMonth->addMonths(2);
        $billRows = PlannedBillReport::forRange($userId, $selectedMonth, $thirdMonth->endOfMonth());

        $this->dispatch('dashboard-trend-updated', chartData: $trend);

        return view('livewire.dashboard', [
            'periodLabel' => SelectedPeriod::clamp($this->periodMonth, $this->periodYear)->label(),
            'income' => $income,
            'spending' => $spending,
            'invested' => $invested,
            'savings' => $savings,
            'savingsIsEstimate' => $this->selectedPeriod()->isCurrentMonth(),
            'trend' => $trend,
            'budgetHighlights' => $budgetHighlights,
            'budgetSummaries' => $budgetSummaries,
            'hasBudgets' => $budgets->isNotEmpty(),
            'spendingCategoryBreakdown' => $enumerable,
            'recentTransactions' => $recentTransactions,
            'billMonths' => [
                ['label' => $selectedMonth->format('F Y'), 'rows' => $billRows->filter(fn (array $row): bool => $row['date']->format('Y-m') === $selectedMonth->format('Y-m'))],
                ['label' => $followingMonth->format('F Y'), 'rows' => $billRows->filter(fn (array $row): bool => $row['date']->format('Y-m') === $followingMonth->format('Y-m'))],
                ['label' => $thirdMonth->format('F Y'), 'rows' => $billRows->filter(fn (array $row): bool => $row['date']->format('Y-m') === $thirdMonth->format('Y-m'))],
            ],
        ]);
    }

    private function expenseTreatment(Transaction $transaction): string
    {
        return $transaction->category?->effectiveExpenseTreatment() ?? Category::TREATMENT_SPENDING;
    }

    /**
     * @param Collection<int, Transaction> $transactions
     * @param Collection<int, array{id: int, name: string}> $categoryParents
     * @return Enumerable<int, array{category: string, category_id: int|null, type: string, total: string}>
     */
    private function categoryTotals(Collection $transactions, string $type, Collection $categoryParents): Enumerable
    {
        return $transactions
            ->groupBy(function (Transaction $transaction) use ($categoryParents): int|string {
                if (!$transaction->category_id) {
                    return 'Uncategorised';
                }

                // Roll subcategory amounts up to the parent category ID.
                return $categoryParents->get($transaction->category_id)['id'] ?? $transaction->category_id;
            })
            ->map(function (Collection $items, int|string $category) use ($type, $categoryParents): array {
                $firstTransaction = $items->first();
                assert($firstTransaction instanceof Transaction);

                $categoryDetails = $firstTransaction->category_id
                    ? $categoryParents->get($firstTransaction->category_id)
                    : null;

                return [
                    'category' => $categoryDetails['name'] ?? 'Uncategorised',
                    'category_id' => is_int($category) ? $category : null,
                    'type' => $type,
                    'total' => Money::fromPennies($items->sum(TransactionImpact::expensePennies(...))),
                ];
            })->values();
    }
}
