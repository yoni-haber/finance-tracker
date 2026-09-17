<div class="space-y-6">
    <div>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
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
        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <p class="text-sm text-gray-500">Remaining after outflows</p>
            <p class="text-2xl font-semibold {{ $remainingAfterOutflows >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                £{{ number_format($remainingAfterOutflows, 2) }}</p>
        </div>
        </div>
        <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">
            Retained this month: <strong class="text-zinc-800 dark:text-zinc-200">£{{ number_format($retained, 2) }}</strong>
            (income minus spending, including money saved or invested). Totals include projected recurring transactions for the selected month.
        </p>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
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
                <h3 class="text-lg font-semibold">Spending by Category</h3>
            </div>
            <div class="mt-4">
                <canvas id="spendingCategoryChart" wire:ignore class="w-full"></canvas>
            </div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold">Savings &amp; Investments by Category</h3>
            </div>
            <div class="mt-4">
                <canvas id="savingInvestmentCategoryChart" wire:ignore class="w-full"></canvas>
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
         data-spending-breakdown='@json($spendingCategoryBreakdown->toArray())'
         data-saving-investment-breakdown='@json($savingInvestmentCategoryBreakdown->toArray())'
         data-transactions-url="{{ route('transactions', absolute: false) }}"></div>

    <script>
        function renderCharts(payload) {
            const incomeCategoryCtx = document.getElementById('incomeCategoryChart');
            const spendingCategoryCtx = document.getElementById('spendingCategoryChart');
            const savingInvestmentCategoryCtx = document.getElementById('savingInvestmentCategoryChart');

            if (!incomeCategoryCtx || !spendingCategoryCtx || !savingInvestmentCategoryCtx || !payload) return;

            if (window._incomeCategoryChart) window._incomeCategoryChart.destroy();
            if (window._spendingCategoryChart) window._spendingCategoryChart.destroy();
            if (window._savingInvestmentCategoryChart) window._savingInvestmentCategoryChart.destroy();

            const colours = [
                '#1d4ed8',
                '#10b981',
                '#f59e0b',
                '#ef4444',
                '#8b5cf6',
                '#0ea5e9',
                '#ec4899',
                '#14b8a6',
                '#f97316',
                '#db2777',
                '#84cc16',
                '#6366f1',
                '#06b6d4',
                '#eab308',
                '#f43f5e'
            ];

            const transactionsUrl = payload.transactionsUrl ?? getTransactionsUrlFromDom();

            const navigateToTransactions = (item) => {
                if (!item?.type || !transactionsUrl) return;

                const url = new URL(transactionsUrl, window.location.origin);
                url.searchParams.set('type', item.type);

                if (item.category_id !== null && item.category_id !== undefined && item.category_id !== '') {
                    url.searchParams.set('category', item.category_id);
                }

                if (window.Livewire?.navigate) {
                    window.Livewire.navigate(url.toString());

                    return;
                }

                window.location.assign(url.toString());
            };

            const clickableChartOptions = (items) => ({
                onClick: (event, elements) => {
                    const firstElement = elements[0];
                    if (!firstElement) return;

                    navigateToTransactions(items[firstElement.index]);
                },
                onHover: (event, elements, chart) => {
                    chart.canvas.style.cursor = elements.length ? 'pointer' : 'default';
                },
            });

            window._incomeCategoryChart = new Chart(incomeCategoryCtx, {
                type: 'pie',
                data: {
                    labels: payload.incomeCategoryBreakdown.map(item => item.category),
                    datasets: [{ data: payload.incomeCategoryBreakdown.map(item => item.total), backgroundColor: colours }]
                },
                options: clickableChartOptions(payload.incomeCategoryBreakdown),
            });

            window._spendingCategoryChart = new Chart(spendingCategoryCtx, {
                type: 'pie',
                data: {
                    labels: payload.spendingCategoryBreakdown.map(item => item.category),
                    datasets: [{ data: payload.spendingCategoryBreakdown.map(item => item.total), backgroundColor: colours }]
                },
                options: clickableChartOptions(payload.spendingCategoryBreakdown),
            });

            window._savingInvestmentCategoryChart = new Chart(savingInvestmentCategoryCtx, {
                type: 'pie',
                data: {
                    labels: payload.savingInvestmentCategoryBreakdown.map(item => item.category),
                    datasets: [{ data: payload.savingInvestmentCategoryBreakdown.map(item => item.total), backgroundColor: colours }]
                },
                options: clickableChartOptions(payload.savingInvestmentCategoryBreakdown),
            });
        }

        function getTransactionsUrlFromDom() {
            const payloadNode = document.getElementById('dashboardChartPayload');

            return payloadNode?.dataset.transactionsUrl ?? null;
        }

        function getPayloadFromDom() {
            const payloadNode = document.getElementById('dashboardChartPayload');
            if (!payloadNode) return null;

            try {
                return {
                    incomeCategoryBreakdown: JSON.parse(payloadNode.dataset.incomeBreakdown ?? '[]'),
                    spendingCategoryBreakdown: JSON.parse(payloadNode.dataset.spendingBreakdown ?? '[]'),
                    savingInvestmentCategoryBreakdown: JSON.parse(payloadNode.dataset.savingInvestmentBreakdown ?? '[]'),
                    transactionsUrl: payloadNode.dataset.transactionsUrl,
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
