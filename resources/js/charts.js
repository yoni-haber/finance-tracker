import Chart from 'chart.js/auto';

const instances = new Map();
const colours = [
    '#1d4ed8', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6',
    '#0ea5e9', '#ec4899', '#14b8a6', '#f97316', '#db2777',
    '#84cc16', '#6366f1', '#06b6d4', '#eab308', '#f43f5e',
];

const destroyChart = (id) => {
    instances.get(id)?.destroy();
    instances.delete(id);
};

const replaceChart = (id, config) => {
    const canvas = document.getElementById(id);
    destroyChart(id);

    if (!canvas) return;

    instances.set(id, new Chart(canvas, config));
};

const parseData = (element) => {
    try {
        return JSON.parse(element?.dataset.chartData ?? '{}');
    } catch (error) {
        console.error('Unable to parse chart data', error);
        return null;
    }
};

const dashboardPayload = () => {
    const element = document.getElementById('dashboardChartPayload');
    if (!element) return null;

    try {
        return {
            incomeCategoryBreakdown: JSON.parse(element.dataset.incomeBreakdown ?? '[]'),
            spendingCategoryBreakdown: JSON.parse(element.dataset.spendingBreakdown ?? '[]'),
            savingInvestmentCategoryBreakdown: JSON.parse(element.dataset.savingInvestmentBreakdown ?? '[]'),
            transactionsUrl: element.dataset.transactionsUrl,
        };
    } catch (error) {
        console.error('Unable to parse dashboard chart data', error);
        return null;
    }
};

const navigateToTransactions = (item, transactionsUrl) => {
    if (!item?.type || !transactionsUrl) return;

    const url = new URL(transactionsUrl, window.location.origin);
    url.searchParams.set('type', item.type);

    if (item.category_id !== null && item.category_id !== undefined && item.category_id !== '') {
        url.searchParams.set('category', item.category_id);
    }

    if (window.Livewire?.navigate) {
        window.Livewire.navigate(url.toString());
    } else {
        window.location.assign(url.toString());
    }
};

const renderDashboardCharts = (payload = dashboardPayload()) => {
    if (!payload) return;

    const transactionsUrl = payload.transactionsUrl
        ?? document.getElementById('dashboardChartPayload')?.dataset.transactionsUrl;

    const pie = (id, items = []) => {
        if (items.length === 0) {
            destroyChart(id);
            return;
        }

        replaceChart(id, {
            type: 'pie',
            data: {
                labels: items.map((item) => item.category),
                datasets: [{
                    data: items.map((item) => item.total),
                    backgroundColor: colours,
                }],
            },
            options: {
                onClick: (_event, elements) => {
                    if (elements[0]) navigateToTransactions(items[elements[0].index], transactionsUrl);
                },
                onHover: (_event, elements, chart) => {
                    chart.canvas.style.cursor = elements.length ? 'pointer' : 'default';
                },
                plugins: { legend: { position: 'bottom' } },
            },
        });
    };

    pie('incomeCategoryChart', payload.incomeCategoryBreakdown);
    pie('spendingCategoryChart', payload.spendingCategoryBreakdown);
    pie('savingInvestmentCategoryChart', payload.savingInvestmentCategoryBreakdown);
};

const renderReportsChart = (chartData = parseData(document.getElementById('incomeVsExpensesChart'))) => {
    if (!chartData?.labels) return;

    replaceChart('incomeVsExpensesChart', {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [
                { label: 'Income', data: chartData.income, borderColor: '#10b981', backgroundColor: 'rgba(16, 185, 129, 0.2)', tension: 0.3, fill: true },
                { label: 'Spending', data: chartData.spending, borderColor: '#ef4444', backgroundColor: 'rgba(239, 68, 68, 0.2)', tension: 0.3, fill: true },
                { label: 'Saved & Invested', data: chartData.savedAndInvested, borderColor: '#3b82f6', backgroundColor: 'rgba(59, 130, 246, 0.2)', tension: 0.3, fill: true },
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

const renderNetWorthChart = () => {
    const chartData = parseData(document.getElementById('netWorthChart'));
    if (!chartData?.labels?.length) {
        destroyChart('netWorthChart');
        return;
    }

    replaceChart('netWorthChart', {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [{
                label: 'Net Worth',
                data: chartData.netWorth,
                borderColor: '#6366f1',
                backgroundColor: 'rgba(99, 102, 241, 0.12)',
                tension: 0.3,
                fill: true,
            }],
        },
        options: {
            responsive: true,
            interaction: { intersect: false, mode: 'index' },
            scales: { y: { ticks: { callback: (value) => `£${value}` } } },
            plugins: { legend: { labels: { usePointStyle: true } } },
        },
    });
};

const hydrateCharts = () => {
    for (const id of instances.keys()) {
        if (!document.getElementById(id)) destroyChart(id);
    }

    renderDashboardCharts();
    renderReportsChart();
    renderNetWorthChart();
};

document.addEventListener('DOMContentLoaded', hydrateCharts);
document.addEventListener('livewire:navigated', hydrateCharts);
document.addEventListener('livewire:init', () => {
    window.Livewire.on('dashboard-charts-updated', (payload) => renderDashboardCharts(payload));
    window.Livewire.on('reports-chart-data', (payload) => renderReportsChart(payload.chartData ?? payload));
});
