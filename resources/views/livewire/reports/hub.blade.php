<div class="space-y-6">
    <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h3 class="text-lg font-semibold">Income vs Expenditure</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Compare totals for your selected range.</p>
            </div>
            <label class="flex items-center gap-3 text-sm font-medium text-gray-700 dark:text-gray-200">
                <select
                    aria-label="Chart range"
                    wire:model.live="range"
                    class="rounded-xl border border-zinc-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow transition focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-300 dark:border-indigo-500/60 dark:bg-gradient-to-b dark:from-zinc-800 dark:to-zinc-900 dark:text-gray-100 dark:shadow-[0_0_0_1px_rgba(99,102,241,0.4)]"
                >
                    @foreach ($rangeOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <canvas
            id="incomeVsExpensesChart"
            wire:ignore
            data-chart-data='@json($chartData)'
            class="mt-6"
        ></canvas>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h3 class="text-lg font-semibold">Net Worth Over Time</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Track how your overall financial position changes.</p>
            </div>
        </div>

        <canvas
            id="netWorthChart"
            wire:ignore
            data-chart-data='@json($netWorthChartData)'
            class="mt-6"
        ></canvas>
    </div>

    <script>
        (() => {
            const renderIncomeVsExpensesChart = (chartData) => {
                const chartElement = document.getElementById('incomeVsExpensesChart');
                if (!chartElement) return;

                if (window._incomeExpenseChart) window._incomeExpenseChart.destroy();

                window._incomeExpenseChart = new Chart(chartElement, {
                    type: 'line',
                    data: {
                        labels: chartData.labels,
                        datasets: [
                            {
                                label: 'Income',
                                data: chartData.income,
                                borderColor: '#10b981',
                                backgroundColor: 'rgba(16, 185, 129, 0.2)',
                                tension: 0.3,
                                fill: true,
                            },
                            {
                                label: 'Expenses',
                                data: chartData.expenses,
                                borderColor: '#ef4444',
                                backgroundColor: 'rgba(239, 68, 68, 0.2)',
                                tension: 0.3,
                                fill: true,
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        interaction: { intersect: false, mode: 'index' },
                        scales: { y: { beginAtZero: true, ticks: { callback: (value) => `£${value}` } } },
                        plugins: { legend: { labels: { usePointStyle: true } } },
                    },
                });
            };

            const renderNetWorthChart = (chartData) => {
                const chartElement = document.getElementById('netWorthChart');
                if (!chartElement) return;

                if (window._netWorthChart) window._netWorthChart.destroy();

                window._netWorthChart = new Chart(chartElement, {
                    type: 'line',
                    data: {
                        labels: chartData.labels,
                        datasets: [
                            {
                                label: 'Net Worth',
                                data: chartData.netWorth,
                                borderColor: '#6366f1',
                                backgroundColor: 'rgba(99, 102, 241, 0.12)',
                                tension: 0.3,
                                fill: true,
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        interaction: { intersect: false, mode: 'index' },
                        scales: { y: { beginAtZero: true, ticks: { callback: (value) => `£${value}` } } },
                        plugins: { legend: { labels: { usePointStyle: true } } },
                    },
                });
            };

            const hydrateFromElement = () => {
                const chartElement = document.getElementById('incomeVsExpensesChart');
                const netWorthElement = document.getElementById('netWorthChart');

                if (chartElement?.dataset.chartData) {
                    try {
                        renderIncomeVsExpensesChart(JSON.parse(chartElement.dataset.chartData));
                    } catch (error) {
                        console.error('Unable to parse chart data', error);
                    }
                }

                if (netWorthElement?.dataset.chartData) {
                    try {
                        renderNetWorthChart(JSON.parse(netWorthElement.dataset.chartData));
                    } catch (error) {
                        console.error('Unable to parse net worth chart data', error);
                    }
                }
            };

            // Register document-level listeners only once across SPA navigations.
            if (!window._reportsChartsListenersRegistered) {
                window._reportsChartsListenersRegistered = true;

                document.addEventListener('DOMContentLoaded', hydrateFromElement);
                document.addEventListener('livewire:navigated', hydrateFromElement);

                // livewire:init fires once on the initial full-page load. When navigating
                // to this page via wire:navigate, Livewire is already initialised so we
                // register the listener immediately instead of waiting for the event.
                const registerLivewireListener = () => {
                    Livewire.on('reports-chart-data', (payload) => {
                        const chartData = payload.chartData ?? payload;
                        renderIncomeVsExpensesChart(chartData);
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
            hydrateFromElement();
        })();
    </script>
</div>
