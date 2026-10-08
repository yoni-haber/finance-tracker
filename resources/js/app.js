import { installUiFeedback } from './ui-feedback';

let chartsPromise;

const loadCharts = () => chartsPromise ??= import('./charts.js');

const hydrateChartsIfPresent = () => {
    if (!chartsPromise && !document.querySelector('canvas[data-chart-data]')) return;

    void loadCharts().then(({ hydrateCharts }) => hydrateCharts());
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', hydrateChartsIfPresent, { once: true });
} else {
    hydrateChartsIfPresent();
}

document.addEventListener('livewire:navigated', hydrateChartsIfPresent);

document.addEventListener('livewire:init', () => {
    installUiFeedback({ document, Livewire: window.Livewire, MutationObserver, requestAnimationFrame });

    window.Livewire.on('dashboard-trend-updated', async (payload) => {
        const { renderDashboardTrend } = await loadCharts();
        renderDashboardTrend(payload.chartData ?? payload);
    });

    window.Livewire.on('reports-chart-data', async (payload) => {
        const { renderReportsChart, renderBudgetChart, renderNetWorthChart } = await loadCharts();
        renderReportsChart(payload.chartData ?? payload);
        renderBudgetChart(payload.budgetData);
        renderNetWorthChart(payload.netWorthData);
    });
});
