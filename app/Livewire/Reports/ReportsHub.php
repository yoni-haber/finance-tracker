<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Models\NetWorthEntry;
use App\Support\CashFlowSeries;
use App\Support\ReportInsights;
use App\Support\TransactionReport;
use Carbon\CarbonImmutable;
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

    public string $transactionMode = 'projected';

    /** @var array<string, list<(float|int|string)>> */
    public array $chartData = [];

    /** @var array<string, mixed> */
    public array $netWorthChartData = [];

    /** @var array<string, int|null> */
    public array $insights = [];

    /** @var list<array{category: string, current: int, previous: int, change: int}> */
    public array $categoryChanges = [];

    /** @var array{labels: list<string>, planned: list<float>, spent: list<float>, hasBudgets: bool} */
    public array $budgetData = ['labels' => [], 'planned' => [], 'spent' => [], 'hasBudgets' => false];

    public function mount(): void
    {
        $userId = (int) (Auth::id() ?? abort(401));
        $this->refreshTransactionReports($userId);
    }

    public function render(): View
    {
        return view('livewire.reports.hub', [
            'comparisonMonthLabel' => CarbonImmutable::now()->startOfMonth()->subMonth()->format('M Y'),
            'rangeOptions' => $this->rangeOptions(),
        ]);
    }

    public function updatedRange(): void
    {
        $userId = (int) (Auth::id() ?? abort(401));

        if (!array_key_exists($this->range, $this->rangeOptions())) {
            $this->range = '12_months';
        }

        $this->refreshTransactionReports($userId);
    }

    public function updatedTransactionMode(): void
    {
        if (!in_array($this->transactionMode, ['projected', 'recorded'], true)) {
            $this->transactionMode = 'projected';
        }

        $this->refreshTransactionReports((int) (Auth::id() ?? abort(401)));
    }

    private function refreshTransactionReports(int $userId): void
    {
        $endMonth = CarbonImmutable::now()->startOfMonth();
        $monthsCount = match ($this->range) {
            '3_months' => 3,
            '6_months' => 6,
            'ytd' => $endMonth->month,
            default => 12,
        };
        $firstMonth = $endMonth->subMonths($monthsCount - 1);
        $projected = $this->transactionMode === 'projected';

        $this->chartData = CashFlowSeries::endingAt($userId, $endMonth->month, $endMonth->year, $monthsCount, $projected);
        $transactionStart = $monthsCount === 1 ? $firstMonth->subMonth() : $firstMonth;
        $transactions = $projected
            ? TransactionReport::projectedForRange($userId, $transactionStart->startOfMonth(), $endMonth->endOfMonth())
            : TransactionReport::recordedForRange($userId, $transactionStart->startOfMonth(), $endMonth->endOfMonth());

        $this->insights = ReportInsights::summary($this->chartData);
        $this->categoryChanges = ReportInsights::categoryChanges($transactions, $endMonth);
        $this->budgetData = ReportInsights::budgets($userId, $transactions, $endMonth, $monthsCount);
        $this->netWorthChartData = $this->buildNetWorthChartData($userId, $firstMonth, $endMonth);

        $this->dispatch('reports-chart-data', chartData: $this->chartData, budgetData: $this->budgetData, netWorthData: $this->netWorthChartData);
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildNetWorthChartData(int $userId, CarbonImmutable $firstMonth, CarbonImmutable $endMonth): array
    {
        $entries = NetWorthEntry::where('user_id', $userId)
            ->whereBetween('date', [$firstMonth->toDateString(), min($endMonth->endOfMonth()->toDateString(), today()->toDateString())])
            ->orderBy('date')
            ->get();

        return [
            'labels' => $entries->pluck('date')->map(fn ($date) => $date->format('M d, Y'))->all(),
            'netWorth' => $entries->pluck('net_worth')->map(fn ($value): float => (float) $value)->all(),
            'assets' => $entries->pluck('assets')->map(fn ($value): float => (float) $value)->all(),
            'liabilities' => $entries->pluck('liabilities')->map(fn ($value): float => (float) $value)->all(),
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
