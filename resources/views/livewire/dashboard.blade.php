<div class="space-y-6">
    <div class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <p class="text-sm text-gray-500">Income</p>
            <p class="text-2xl font-semibold text-emerald-600">£{{ number_format($income, 2) }}</p>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <p class="text-sm text-gray-500">Spending</p>
            <p class="text-2xl font-semibold text-rose-600">£{{ number_format($spending, 2) }}</p>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <p class="text-sm text-gray-500">Saved &amp; Invested</p>
            <p class="text-2xl font-semibold text-blue-600">£{{ number_format($savedAndInvested, 2) }}</p>
        </div>
    </div>

    @php
        $categoryCharts = [
            ['id' => 'incomeCategoryChart', 'title' => 'Income by Category', 'empty' => 'No income recorded for this period.', 'items' => $incomeCategoryBreakdown],
            ['id' => 'spendingCategoryChart', 'title' => 'Spending by Category', 'empty' => 'No spending recorded for this period.', 'items' => $spendingCategoryBreakdown],
            ['id' => 'savingInvestmentCategoryChart', 'title' => 'Savings & Investments by Category', 'empty' => 'No saving or investment transactions recorded for this period.', 'items' => $savingInvestmentCategoryBreakdown],
        ];
    @endphp

    <div class="grid gap-6 lg:grid-cols-3">
        @foreach ($categoryCharts as $chart)
            <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" aria-labelledby="{{ $chart['id'] }}-title">
                <h3 id="{{ $chart['id'] }}-title" class="text-lg font-semibold">{{ $chart['title'] }}</h3>

                @if ($chart['items']->isEmpty())
                    <p class="mt-6 rounded-lg bg-zinc-50 px-4 py-8 text-center text-sm text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                        {{ $chart['empty'] }}
                    </p>
                @else
                    <div class="mt-4">
                        <canvas id="{{ $chart['id'] }}" wire:ignore role="img"
                                aria-label="{{ $chart['title'] }} chart. Select a segment to filter transactions."
                                aria-describedby="{{ $chart['id'] }}-data" class="w-full"></canvas>
                    </div>
                    <details id="{{ $chart['id'] }}-data" class="mt-4 text-sm">
                        <summary class="cursor-pointer font-medium text-zinc-600 dark:text-zinc-300">View chart data</summary>
                        <div class="mt-2 overflow-x-auto">
                            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                                <thead><tr><th class="py-2 text-left">Category</th><th class="py-2 text-right">Amount</th></tr></thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @foreach ($chart['items'] as $item)
                                        <tr><td class="py-2">{{ $item['category'] }}</td><td class="py-2 text-right">£{{ number_format($item['total'], 2) }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                @endif
            </section>
        @endforeach
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <h3 class="text-lg font-semibold">Budgets vs Actuals</h3>
        <div class="mt-4 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            @forelse ($budgetSummaries as $summary)
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <p class="text-sm font-medium">{{ $summary['category'] }}</p>
                    <p class="text-xs text-gray-500">Budget {{ \App\Support\Money::format($summary['budget']) }}</p>
                    <p class="text-xs text-gray-500">Actual {{ \App\Support\Money::format($summary['actual']) }}</p>
                    <div class="mt-2 h-2 rounded-full bg-zinc-200 dark:bg-zinc-800" role="progressbar"
                         aria-label="{{ $summary['category'] }} budget used" aria-valuemin="0" aria-valuemax="100"
                         aria-valuenow="{{ $summary['barPercent'] }}"
                         aria-valuetext="{{ $summary['percent'] === null ? 'Over budget with no limit' : $summary['percent'] . '% used' }}, {{ \App\Support\Money::format($summary['overspent'] ? $summary['over'] : $summary['remaining']) }} {{ $summary['overspent'] ? 'over' : 'remaining' }}">
                        <div class="h-2 rounded-full {{ $summary['overspent'] ? 'bg-rose-500' : 'bg-emerald-500' }}" style="width: {{ $summary['barPercent'] }}%"></div>
                    </div>
                    <p class="mt-2 text-sm {{ $summary['overspent'] ? 'text-rose-600' : 'text-emerald-600' }}">
                        {{ \App\Support\Money::format($summary['overspent'] ? $summary['over'] : $summary['remaining']) }} {{ $summary['overspent'] ? 'over' : 'remaining' }} · {{ $summary['percent'] === null ? 'Over budget' : $summary['percent'] . '% used' }}
                    </p>
                </div>
            @empty
                <p class="text-sm text-gray-500">No budgets defined for this period.</p>
            @endforelse
        </div>
    </div>

    <div id="dashboardChartPayload" class="hidden"
         data-income-breakdown='@json($incomeCategoryBreakdown->toArray())'
         data-spending-breakdown='@json($spendingCategoryBreakdown->toArray())'
         data-saving-investment-breakdown='@json($savingInvestmentCategoryBreakdown->toArray())'
         data-transactions-url="{{ route('transactions', absolute: false) }}"></div>
</div>
