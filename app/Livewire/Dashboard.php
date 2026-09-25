<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Livewire\Concerns\InteractsWithSelectedPeriod;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\BudgetProgress;
use App\Support\Money;
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

        $income = Money::fromPennies(
            $this->sumPennies($transactions->where('type', Transaction::TYPE_INCOME)),
        );

        $expenseTransactions = $transactions->where('type', Transaction::TYPE_EXPENSE);
        $spendingTransactions = $expenseTransactions->filter(
            fn (Transaction $transaction): bool => $this->expenseTreatment($transaction) === Category::TREATMENT_SPENDING,
        );
        $savingInvestmentTransactions = $expenseTransactions->reject(
            fn (Transaction $transaction): bool => $this->expenseTreatment($transaction) === Category::TREATMENT_SPENDING,
        );

        $spending = Money::fromPennies($this->sumPennies($spendingTransactions));
        $savedAndInvested = Money::fromPennies($this->sumPennies($savingInvestmentTransactions));

        $budgets = Budget::with('category.children')
            ->where('user_id', $userId)
            ->where('month', $this->periodMonth)
            ->where('year', $this->periodYear)
            ->get();

        $budgetSummaries = BudgetProgress::forPeriod($budgets, $transactions, $this->periodMonth, $this->periodYear);

        // Build a category-id -> parent identity map for the pie chart rollup.
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

        $enumerable = $this->categoryTotals($transactions, Transaction::TYPE_INCOME, $categoryParents);
        $categorySpending = $this->categoryTotals($spendingTransactions, Transaction::TYPE_EXPENSE, $categoryParents);
        $categorySavingInvestment = $this->categoryTotals($savingInvestmentTransactions, Transaction::TYPE_EXPENSE, $categoryParents);

        $this->dispatch('dashboard-charts-updated',
            incomeCategoryBreakdown: $enumerable->all(),
            spendingCategoryBreakdown: $categorySpending->all(),
            savingInvestmentCategoryBreakdown: $categorySavingInvestment->all(),
        );

        return view('livewire.dashboard', [
            'income' => $income,
            'spending' => $spending,
            'savedAndInvested' => $savedAndInvested,
            'budgetSummaries' => $budgetSummaries,
            'incomeCategoryBreakdown' => $enumerable,
            'spendingCategoryBreakdown' => $categorySpending,
            'savingInvestmentCategoryBreakdown' => $categorySavingInvestment,
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
            ->where('type', $type)
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
                    'total' => Money::fromPennies($this->sumPennies($items)),
                ];
            })->values();
    }

    /**
     * @param Collection<int, Transaction> $transactions
     */
    private function sumPennies(Collection $transactions): int
    {
        return $transactions->sum(
            fn (Transaction $transaction): int => Money::normalize((string) $transaction->amount),
        );
    }
}
