<div class="space-y-5">
    <x-page-header eyebrow="Insights" title="Reports" description="See where your money changed, how plans held up, and how your net worth moved." />

    <div class="app-card flex flex-wrap items-end gap-4 p-4 sm:p-5" aria-label="Report controls">
        <div>
            <label for="report-range" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide app-muted">Time range</label>
            <select id="report-range" wire:model.live="range" class="app-field text-sm">
                @foreach ($rangeOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="report-mode" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide app-muted">Transaction data</label>
            <select id="report-mode" wire:model.live="transactionMode" class="app-field text-sm">
                <option value="projected">Include recurring schedule</option>
                <option value="recorded">Recorded entries only</option>
            </select>
        </div>
    </div>

    <section aria-labelledby="report-summary-heading">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2"><h2 id="report-summary-heading" class="text-lg font-semibold">At a glance</h2><p class="text-xs app-muted">{{ $rangeOptions[$range] }} · {{ $transactionMode === 'projected' ? 'Includes recurring schedule' : 'Recorded entries only' }}</p></div>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="app-card p-4"><p class="app-eyebrow">Income</p><p class="mt-2 text-2xl font-semibold tabular-nums text-[#126e51] dark:text-[#75ddb2]">£{{ \App\Support\Money::formatPennies($insights['income']) }}</p></div>
            <div class="app-card p-4"><p class="app-eyebrow">Spending</p><p class="mt-2 text-2xl font-semibold tabular-nums text-[#bd5b52] dark:text-[#f19b91]">£{{ \App\Support\Money::formatPennies($insights['spending']) }}</p></div>
            <div class="app-card p-4"><p class="app-eyebrow">Invested</p><p class="mt-2 text-2xl font-semibold tabular-nums text-[#4d6f96] dark:text-[#a7c9ec]">£{{ \App\Support\Money::formatPennies($insights['invested']) }}</p><p class="mt-1 text-xs app-muted">{{ $insights['investmentRate'] === null ? 'No income for a rate' : $insights['investmentRate'] . '% of income' }}</p></div>
            <div class="app-card p-4"><p class="app-eyebrow">Savings</p><p class="mt-2 text-2xl font-semibold tabular-nums {{ $insights['savings'] < 0 ? 'text-[#bd5b52] dark:text-[#f19b91]' : 'text-[#126e51] dark:text-[#75ddb2]' }}">{{ $insights['savings'] < 0 ? '−' : '' }}£{{ \App\Support\Money::formatPennies(abs($insights['savings'])) }}</p><p class="mt-1 text-xs app-muted">{{ $insights['savingsRate'] === null ? 'No income for a rate' : $insights['savingsRate'] . '% of income' }} · Income less spending and investing</p></div>
        </div>
    </section>

    <section class="app-card p-4 sm:p-6" aria-labelledby="cash-flow-heading">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div><h2 id="cash-flow-heading" class="text-lg font-semibold">Cash flow over time</h2><p class="mt-1 text-sm app-muted">Income, spending, investing, and calculated savings for each month.</p></div>
            @if (count($chartData['labels']) > 1)<div class="rounded-xl bg-zinc-50 px-4 py-2 text-sm dark:bg-zinc-800"><span class="block text-xs app-muted">Change in savings from previous month</span><strong class="tabular-nums {{ $insights['monthChange'] < 0 ? 'text-rose-700 dark:text-rose-400' : 'text-emerald-700 dark:text-emerald-400' }}">{{ $insights['monthChange'] > 0 ? '+' : ($insights['monthChange'] < 0 ? '−' : '') }}£{{ \App\Support\Money::formatPennies(abs($insights['monthChange'])) }}</strong></div>@endif
        </div>
        @if ($insights['income'] !== 0 || $insights['spending'] !== 0 || $insights['invested'] !== 0 || $insights['savings'] !== 0)
            <div class="mt-6 h-72 sm:h-80"><canvas id="incomeVsExpensesChart" wire:ignore data-chart-data='@json($chartData)' role="img" aria-label="Monthly income, spending, investing, and savings chart" aria-describedby="cash-flow-data"></canvas></div>
        @else
            <div class="app-empty mt-6">No transactions in this range. <a href="{{ route('transactions') }}" class="app-link">Add a transaction</a> to start your reports.</div>
        @endif
        <details id="cash-flow-data" class="mt-4 text-sm"><summary class="cursor-pointer font-medium">View monthly cash flow data</summary><div class="mt-2 overflow-x-auto"><table class="min-w-full divide-y divide-app-border"><thead><tr><th class="py-2 text-left">Month</th><th class="py-2 text-right">Income</th><th class="py-2 text-right">Spending</th><th class="py-2 text-right">Invested</th><th class="py-2 text-right">Savings</th></tr></thead><tbody class="divide-y divide-app-border">@foreach ($chartData['labels'] as $index => $label)<tr><td class="py-2">{{ $label }}</td><td class="py-2 text-right tabular-nums">{{ \App\Support\Money::format($chartData['income'][$index]) }}</td><td class="py-2 text-right tabular-nums">{{ \App\Support\Money::format($chartData['spending'][$index]) }}</td><td class="py-2 text-right tabular-nums">{{ \App\Support\Money::format($chartData['invested'][$index]) }}</td><td class="py-2 text-right tabular-nums">{{ \App\Support\Money::format($chartData['savings'][$index]) }}</td></tr>@endforeach</tbody></table></div></details>
    </section>

    <div class="grid gap-5 xl:grid-cols-2">
        <section class="app-card min-w-0 p-4 sm:p-6" aria-labelledby="category-changes-heading">
            <h2 id="category-changes-heading" class="text-lg font-semibold">Where spending changed</h2>
            <p class="mt-1 text-sm app-muted">{{ $chartData['labels'][count($chartData['labels']) - 1] }} compared with {{ $comparisonMonthLabel }}. Top six spending categories by activity. {{ $transactionMode === 'projected' ? 'The current month includes scheduled recurring occurrences.' : 'Only saved entries are counted.' }}</p>
            @if ($categoryChanges)
                <div class="mt-4 divide-y divide-app-border">
                    @foreach ($categoryChanges as $row)
                        <div class="flex flex-wrap items-center justify-between gap-2 py-3"><div class="min-w-0"><p class="font-medium">@if ($row['category_id'])<a class="app-link" href="{{ route('transactions', ['scope' => 'all', 'type' => 'expense', 'category' => $row['category_id']]) }}" wire:navigate>{{ $row['category'] }}</a>@else{{ $row['category'] }}@endif</p><p class="text-xs app-muted">Was £{{ \App\Support\Money::formatPennies($row['previous']) }}</p></div><div class="text-right"><p class="font-semibold tabular-nums">£{{ \App\Support\Money::formatPennies($row['current']) }}</p><p class="text-xs tabular-nums {{ $row['change'] > 0 ? 'text-rose-700 dark:text-rose-400' : 'text-emerald-700 dark:text-emerald-400' }}">{{ $row['change'] > 0 ? '+' : ($row['change'] < 0 ? '−' : '') }}£{{ \App\Support\Money::formatPennies(abs($row['change'])) }}</p></div></div>
                    @endforeach
                </div>
            @else
                <p class="app-empty mt-4">No spending in the last two months of this range.</p>
            @endif
        </section>

        <section class="app-card min-w-0 p-4 sm:p-6" aria-labelledby="budgets-heading">
            <h2 id="budgets-heading" class="text-lg font-semibold">Spending budget performance</h2>
            <p class="mt-1 text-sm app-muted">Planned limits and actuals for spending categories. Current month spending is through today.</p>
            @if ($budgetData['hasBudgets'])
                <div class="mt-5 h-64"><canvas id="budgetPerformanceChart" wire:ignore data-chart-data='@json($budgetData)' role="img" aria-label="Monthly planned budget and spending chart" aria-describedby="budget-data"></canvas></div>
                <details id="budget-data" class="mt-4 text-sm"><summary class="cursor-pointer font-medium">View monthly budget data</summary><div class="mt-2 overflow-x-auto"><table class="min-w-full divide-y divide-app-border"><thead><tr><th class="py-2 text-left">Month</th><th class="py-2 text-right">Planned</th><th class="py-2 text-right">Spent</th></tr></thead><tbody class="divide-y divide-app-border">@foreach ($budgetData['labels'] as $index => $label)<tr><td class="py-2">{{ $label }}</td><td class="py-2 text-right tabular-nums">{{ \App\Support\Money::format($budgetData['planned'][$index]) }}</td><td class="py-2 text-right tabular-nums">{{ \App\Support\Money::format($budgetData['spent'][$index]) }}</td></tr>@endforeach</tbody></table></div></details>
            @else
                <div class="app-empty mt-4">No spending budgets in this range. <a href="{{ route('budgets') }}" class="app-link">Set a budget</a> to compare plans with spending.</div>
            @endif
        </section>
    </div>

    <section class="app-card p-4 sm:p-6" aria-labelledby="net-worth-heading">
        <h2 id="net-worth-heading" class="text-lg font-semibold">Net worth over time</h2>
        <p class="mt-1 text-sm app-muted">Assets, liabilities, and net worth from snapshots in the selected time range.</p>
        @if ($netWorthChartData['labels'])
            <div class="mt-6 h-72 sm:h-80"><canvas id="netWorthChart" wire:ignore data-chart-data='@json($netWorthChartData)' role="img" aria-label="Assets, liabilities, and net worth over time" aria-describedby="net-worth-data"></canvas></div>
            <details id="net-worth-data" class="mt-4 text-sm"><summary class="cursor-pointer font-medium">View net worth data</summary><div class="mt-2 overflow-x-auto"><table class="min-w-full divide-y divide-app-border"><thead><tr><th class="py-2 text-left">Date</th><th class="py-2 text-right">Assets</th><th class="py-2 text-right">Liabilities</th><th class="py-2 text-right">Net worth</th></tr></thead><tbody class="divide-y divide-app-border">@foreach ($netWorthChartData['labels'] as $index => $label)<tr><td class="py-2">{{ $label }}</td><td class="py-2 text-right tabular-nums">{{ \App\Support\Money::format($netWorthChartData['assets'][$index]) }}</td><td class="py-2 text-right tabular-nums">{{ \App\Support\Money::format($netWorthChartData['liabilities'][$index]) }}</td><td class="py-2 text-right tabular-nums">{{ $netWorthChartData['netWorth'][$index] < 0 ? '−' : '' }}{{ \App\Support\Money::format(abs($netWorthChartData['netWorth'][$index])) }}</td></tr>@endforeach</tbody></table></div></details>
        @else
            <div class="app-empty mt-6">No snapshots in this range. <a href="{{ route('net-worth') }}" class="app-link">Add a snapshot</a> to start tracking.</div>
        @endif
    </section>
</div>
