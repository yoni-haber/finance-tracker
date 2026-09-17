<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Models\Category;
use App\Models\NetWorthEntry;
use App\Models\Transaction;
use App\Support\TransactionReport;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Reports')]
class ReportsHub extends Component
{
    public string $range = '12_months';

    /** @var array<string, list<(float|int|string)>> */
    public array $chartData = [];

    /** @var array<string, mixed> */
    public array $netWorthChartData = [];

    public function mount(): void
    {
        $userId = (int) (Auth::id() ?? abort(401));
        $this->chartData = $this->chartDataForRange($this->range, $userId);
        $this->netWorthChartData = $this->buildNetWorthChartData($userId);
    }

    public function render(): View
    {
        return view('livewire.reports.hub', [
            'chartData' => $this->chartData,
            'netWorthChartData' => $this->netWorthChartData,
            'rangeOptions' => $this->rangeOptions(),
        ]);
    }

    public function updatedRange(): void
    {
        $userId = (int) (Auth::id() ?? abort(401));

        if (!array_key_exists($this->range, $this->rangeOptions())) {
            $this->range = '12_months';
        }

        $this->chartData = $this->chartDataForRange($this->range, $userId);

        $this->dispatch('reports-chart-data', chartData: $this->chartData);
    }

    /**
     * @return array<string, list<(float|int|string)>>
     */
    private function chartDataForRange(string $range, int $userId): array
    {
        $labels = [];
        $income = [];
        $spending = [];
        $savedAndInvested = [];

        $start = now()->startOfMonth();

        $monthsCount = match ($range) {
            '3_months' => 3,
            '6_months' => 6,
            'ytd' => $start->month,
            default => 12,
        };

        for ($i = $monthsCount - 1; $i >= 0; $i--) {
            $monthDate = $start->copy()->subMonths($i);
            $labels[] = $monthDate->format('M Y');
            $transactions = TransactionReport::projectedForMonth($userId, $monthDate->month, $monthDate->year);
            $income[] = (float) $transactions->where('type', Transaction::TYPE_INCOME)->sum('amount');
            $expenseTransactions = $transactions->where('type', Transaction::TYPE_EXPENSE);
            $spending[] = $expenseTransactions
                ->filter(fn (Transaction $transaction): bool => $this->expenseTreatment($transaction) === Category::TREATMENT_SPENDING)
                ->sum('amount');
            $savedAndInvested[] = $expenseTransactions
                ->reject(fn (Transaction $transaction): bool => $this->expenseTreatment($transaction) === Category::TREATMENT_SPENDING)
                ->sum('amount');
        }

        return [
            'labels' => $labels,
            'income' => $income,
            'spending' => $spending,
            'savedAndInvested' => $savedAndInvested,
        ];
    }

    private function expenseTreatment(Transaction $transaction): string
    {
        return $transaction->category?->effectiveExpenseTreatment() ?? Category::TREATMENT_SPENDING;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildNetWorthChartData(int $userId): array
    {
        $entries = NetWorthEntry::where('user_id', $userId)
            ->where('date', '>=', now()->subMonths(12)->startOfDay())
            ->orderBy('date')
            ->get();

        return [
            'labels' => $entries->pluck('date')->map(fn ($date) => $date->format('M d, Y'))->all(),
            'netWorth' => $entries->pluck('net_worth')->map(fn ($value): float => (float) $value)->all(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function rangeOptions(): array
    {
        return [
            '3_months' => 'Last 3 Months',
            '6_months' => 'Last 6 Months',
            '12_months' => 'Last 12 Months',
            'ytd' => 'Year to Date',
        ];
    }
}
