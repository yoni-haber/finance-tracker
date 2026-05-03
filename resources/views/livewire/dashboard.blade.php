<div class="space-y-6">
    <div class="flex flex-wrap gap-3 items-center">
        <div class="flex items-center gap-2">
            <label class="text-sm text-gray-600 dark:text-gray-300">Month</label>
            <select wire:model.live="month"
                    class="rounded-md border-gray-300 dark:bg-zinc-800 dark:border-zinc-700 text-sm">
                @foreach (range(1, 12) as $month)
                    <option value="{{ $month }}">{{ now()->startOfYear()->month($month)->format('F') }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-center gap-2">
            <label class="text-sm text-gray-600 dark:text-gray-300">Year</label>
            <input type="number" wire:model.live="year"
                   class="rounded-md border-gray-300 dark:bg-zinc-800 dark:border-zinc-700 text-sm" min="2000"
                   max="2100">
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <p class="text-sm text-gray-500">Income</p>
            <p class="text-2xl font-semibold text-emerald-600">£{{ number_format($income, 2) }}</p>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <p class="text-sm text-gray-500">Expenses</p>
            <p class="text-2xl font-semibold text-rose-600">£{{ number_format($expenses, 2) }}</p>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <p class="text-sm text-gray-500">Net Balance</p>
            <p class="text-2xl font-semibold {{ $net >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                £{{ number_format($net, 2) }}</p>
        </div>
    </div>

    <div class="flex flex-row gap-6">
        <div class="flex-1 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold">Income by Category</h3>
            </div>
            <div class="mt-4">
                <canvas id="incomeCategoryChart" wire:ignore class="w-full"></canvas>
            </div>
        </div>
        <div class="flex-1 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold">Expenses by Category</h3>
            </div>
            <div class="mt-4">
                <canvas id="expenseCategoryChart" wire:ignore class="w-full"></canvas>
            </div>
        </div>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold">Budgets vs Actuals</h3>
        </div>
        <div class="mt-4 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            @forelse ($budgetSummaries as $summary)
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <p class="text-sm font-medium">{{ $summary['category'] }}</p>
                    <p class="text-xs text-gray-500">Budget £{{ number_format($summary['budget'], 2) }}</p>
                    <p class="text-xs text-gray-500">Actual £{{ number_format($summary['actual'], 2) }}</p>
                    <div class="mt-2 h-2 rounded-full bg-zinc-200 dark:bg-zinc-800">
                        @php
                            $ratio = $summary['budget'] > 0 ? min(1, $summary['actual'] / $summary['budget']) : 0;
                        @endphp
                        <div class="h-2 rounded-full {{ $summary['overspent'] ? 'bg-rose-500' : 'bg-emerald-500' }}"
                             style="width: {{ $ratio * 100 }}%"></div>
                    </div>
                    <p class="mt-2 text-sm {{ $summary['overspent'] ? 'text-rose-600' : 'text-emerald-600' }}">
                        {{ $summary['overspent'] ? 'Overspent' : 'Remaining' }}
                        £{{ number_format($summary['remaining'], 2) }}
                    </p>
                </div>
            @empty
                <p class="text-sm text-gray-500">No budgets defined.</p>
            @endforelse
        </div>
    </div>

    <div id="dashboardChartPayload" class="hidden"
         data-income-breakdown='@json($incomeCategoryBreakdown->toArray())'
         data-expense-breakdown='@json($expenseCategoryBreakdown->toArray())'></div>

    <script>
        function renderCharts(payload) {
            const incomeCategoryCtx = document.getElementById('incomeCategoryChart');
            const expenseCategoryCtx = document.getElementById('expenseCategoryChart');

            if (!incomeCategoryCtx || !expenseCategoryCtx || !payload) return;

            if (window._incomeCategoryChart) window._incomeCategoryChart.destroy();
            if (window._expenseCategoryChart) window._expenseCategoryChart.destroy();

            const colors = ['#1d4ed8', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#0ea5e9'];

            window._incomeCategoryChart = new Chart(incomeCategoryCtx, {
                type: 'pie',
                data: {
                    labels: payload.incomeCategoryBreakdown.map(item => item.category),
                    datasets: [{ data: payload.incomeCategoryBreakdown.map(item => item.total), backgroundColor: colors }]
                }
            });

            window._expenseCategoryChart = new Chart(expenseCategoryCtx, {
                type: 'pie',
                data: {
                    labels: payload.expenseCategoryBreakdown.map(item => item.category),
                    datasets: [{ data: payload.expenseCategoryBreakdown.map(item => item.total), backgroundColor: colors }]
                }
            });
        }

        function getPayloadFromDom() {
            const payloadNode = document.getElementById('dashboardChartPayload');
            if (!payloadNode) return null;

            try {
                return {
                    incomeCategoryBreakdown: JSON.parse(payloadNode.dataset.incomeBreakdown ?? '[]'),
                    expenseCategoryBreakdown: JSON.parse(payloadNode.dataset.expenseBreakdown ?? '[]'),
                };
            } catch (error) {
                console.error('Unable to parse dashboard chart payload', error);
                return null;
            }
        }

        const initializeCharts = () => renderCharts(getPayloadFromDom());

        // Register document-level listeners only once across SPA navigations.
        if (!window._dashboardChartsListenersRegistered) {
            window._dashboardChartsListenersRegistered = true;

            document.addEventListener('DOMContentLoaded', initializeCharts);
            document.addEventListener('livewire:navigated', initializeCharts);

            // livewire:init fires once on the initial full-page load. When navigating
            // to this page via wire:navigate, Livewire is already initialised so we
            // register the listener immediately instead of waiting for the event.
            const registerLivewireListener = () => {
                Livewire.on('dashboard-charts-updated', (payload) => {
                    renderCharts(payload);
                });
            };

            if (typeof Livewire !== 'undefined') {
                registerLivewireListener();
            } else {
                document.addEventListener('livewire:init', registerLivewireListener);
            }
        }

        // Always attempt an immediate render — covers SPA navigation where the DOM
        // data is already present when this script is (re-)evaluated.
        initializeCharts();
    </script>
</div>
