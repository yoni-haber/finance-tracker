<div class="space-y-6">
    @php
        $hasCashFlowData = collect($chartData['income'])
            ->merge($chartData['spending'])
            ->merge($chartData['savedAndInvested'])
            ->contains(fn ($value) => (float) $value !== 0.0);
    @endphp

    <section class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900" aria-labelledby="cash-flow-heading">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h3 id="cash-flow-heading" class="text-lg font-semibold">Income, Spending &amp; Saving</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Compare monthly totals for your selected range, including projected recurring transactions.</p>
            </div>
            <label class="flex items-center gap-3 text-sm font-medium text-gray-700 dark:text-gray-200">
                <span class="sr-only">Chart range</span>
                <select aria-label="Chart range" wire:model.live="range"
                        class="rounded-xl border border-zinc-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow transition focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-300 dark:border-zinc-700 dark:bg-zinc-900 dark:text-gray-200 dark:focus:border-indigo-500 dark:focus:ring-indigo-300">
                    @foreach ($rangeOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        @if ($hasCashFlowData)
            <canvas id="incomeVsExpensesChart" wire:ignore data-chart-data='@json($chartData)'
                    role="img" aria-label="Monthly income, spending, and saving chart"
                    aria-describedby="cash-flow-data" class="mt-6"></canvas>
        @else
            <p class="mt-6 rounded-lg bg-zinc-50 px-4 py-8 text-center text-sm text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                No income or outflow data is available for this range.
            </p>
        @endif

        <details id="cash-flow-data" class="mt-4 text-sm">
            <summary class="cursor-pointer font-medium text-zinc-600 dark:text-zinc-300">View chart data</summary>
            <div class="mt-2 overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead><tr><th class="py-2 text-left">Month</th><th class="py-2 text-right">Income</th><th class="py-2 text-right">Spending</th><th class="py-2 text-right">Saved &amp; invested</th></tr></thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($chartData['labels'] as $index => $label)
                            <tr>
                                <td class="py-2">{{ $label }}</td>
                                <td class="py-2 text-right">£{{ number_format($chartData['income'][$index], 2) }}</td>
                                <td class="py-2 text-right">£{{ number_format($chartData['spending'][$index], 2) }}</td>
                                <td class="py-2 text-right">£{{ number_format($chartData['savedAndInvested'][$index], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    </section>

    <section class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900" aria-labelledby="net-worth-heading">
        <h3 id="net-worth-heading" class="text-lg font-semibold">Net Worth Over Time</h3>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Track how your overall financial position changes.</p>

        @if ($netWorthChartData['labels'])
            <canvas id="netWorthChart" wire:ignore data-chart-data='@json($netWorthChartData)'
                    role="img" aria-label="Net worth over time chart" aria-describedby="net-worth-data"
                    class="mt-6"></canvas>
            <details id="net-worth-data" class="mt-4 text-sm">
                <summary class="cursor-pointer font-medium text-zinc-600 dark:text-zinc-300">View chart data</summary>
                <div class="mt-2 overflow-x-auto">
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead><tr><th class="py-2 text-left">Date</th><th class="py-2 text-right">Net worth</th></tr></thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($netWorthChartData['labels'] as $index => $label)
                                <tr><td class="py-2">{{ $label }}</td><td class="py-2 text-right">£{{ number_format($netWorthChartData['netWorth'][$index], 2) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @else
            <p class="mt-6 rounded-lg bg-zinc-50 px-4 py-8 text-center text-sm text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                No net worth snapshots are available for the last 12 months.
            </p>
        @endif
    </section>
</div>
