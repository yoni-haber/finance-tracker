import Chart from 'chart.js/auto';

const instances = new Map();
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

const renderDashboardTrend = (chartData = parseData(document.getElementById('dashboardTrendChart'))) => {
    if (!chartData?.labels?.length || !document.getElementById('dashboardTrendChart')) {
        destroyChart('dashboardTrendChart');
        return;
    }

    const dark = document.documentElement.classList.contains('dark');
    const axisColour = dark ? '#a8b8ad' : '#65736a';
    const gridColour = dark ? '#304038' : '#e4e9e1';

    replaceChart('dashboardTrendChart', {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [
                { label: 'Income', data: chartData.income, borderColor: '#46ad80', backgroundColor: '#46ad80', tension: 0.35 },
                { label: 'Spending', data: chartData.spending, borderColor: '#d5746a', backgroundColor: '#d5746a', tension: 0.35 },
                { label: 'Invested', data: chartData.invested, borderColor: '#729aca', backgroundColor: '#729aca', tension: 0.35 },
                { label: 'Savings', data: chartData.savings, borderColor: '#ac8fcb', backgroundColor: '#ac8fcb', tension: 0.35 },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            scales: {
                x: { ticks: { color: axisColour }, grid: { display: false } },
                y: { beginAtZero: true, ticks: { color: axisColour, callback: (value) => value < 0 ? `−£${Math.abs(value)}` : `£${value}` }, grid: { color: gridColour } },
            },
            plugins: { legend: { position: 'bottom', labels: { color: axisColour, usePointStyle: true, boxWidth: 8 } } },
        },
    });
};

const renderReportsChart = (chartData = parseData(document.getElementById('incomeVsExpensesChart'))) => {
    if (!chartData?.labels?.length || !document.getElementById('incomeVsExpensesChart')) {
        destroyChart('incomeVsExpensesChart');
        return;
    }

    const dark = document.documentElement.classList.contains('dark');
    const axisColour = dark ? '#a8b8ad' : '#65736a';
    const gridColour = dark ? '#304038' : '#e4e9e1';

    replaceChart('incomeVsExpensesChart', {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [
                { label: 'Income', data: chartData.income, borderColor: '#46ad80', backgroundColor: '#46ad80', tension: 0.3 },
                { label: 'Spending', data: chartData.spending, borderColor: '#d5746a', backgroundColor: '#d5746a', tension: 0.3 },
                { label: 'Invested', data: chartData.invested, borderColor: '#729aca', backgroundColor: '#729aca', tension: 0.3 },
                { label: 'Savings', data: chartData.savings, borderColor: '#ac8fcb', backgroundColor: '#ac8fcb', tension: 0.3 },
            ],
        },
        options: {
            responsive: true,
            interaction: { intersect: false, mode: 'index' },
            maintainAspectRatio: false,
            scales: {
                x: { ticks: { color: axisColour }, grid: { display: false } },
                y: { beginAtZero: true, ticks: { color: axisColour, callback: (value) => value < 0 ? `−£${Math.abs(value)}` : `£${value}` }, grid: { color: gridColour } },
            },
            plugins: { legend: { position: 'bottom', labels: { color: axisColour, usePointStyle: true } } },
        },
    });
};

const renderBudgetChart = (chartData = parseData(document.getElementById('budgetPerformanceChart'))) => {
    if (!chartData?.hasBudgets || !document.getElementById('budgetPerformanceChart')) {
        destroyChart('budgetPerformanceChart');
        return;
    }

    const dark = document.documentElement.classList.contains('dark');
    const axisColour = dark ? '#a8b8ad' : '#65736a';
    replaceChart('budgetPerformanceChart', {
        type: 'bar',
        data: {
            labels: chartData.labels,
            datasets: [
                { label: 'Planned', data: chartData.planned, backgroundColor: '#729aca', borderRadius: 5 },
                { label: 'Spent', data: chartData.spent, backgroundColor: '#d5746a', borderRadius: 5 },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { ticks: { color: axisColour }, grid: { display: false } },
                y: { beginAtZero: true, ticks: { color: axisColour, callback: (value) => `£${value}` }, grid: { display: false } },
            },
            plugins: { legend: { position: 'bottom', labels: { color: axisColour, usePointStyle: true } } },
        },
    });
};

const renderNetWorthChart = (chartData = parseData(document.getElementById('netWorthChart'))) => {
    if (!chartData?.labels?.length || !document.getElementById('netWorthChart')) {
        destroyChart('netWorthChart');
        return;
    }

    const dark = document.documentElement.classList.contains('dark');
    const axisColour = dark ? '#a8b8ad' : '#65736a';
    const gridColour = dark ? '#304038' : '#e4e9e1';

    replaceChart('netWorthChart', {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [
                { label: 'Assets', data: chartData.assets, borderColor: '#46ad80', backgroundColor: '#46ad80', tension: 0.3 },
                { label: 'Liabilities', data: chartData.liabilities, borderColor: '#d5746a', backgroundColor: '#d5746a', tension: 0.3 },
                { label: 'Net worth', data: chartData.netWorth, borderColor: '#729aca', backgroundColor: '#729aca', tension: 0.3 },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            scales: {
                x: { ticks: { color: axisColour }, grid: { display: false } },
                y: { ticks: { color: axisColour, callback: (value) => `£${value}` }, grid: { color: gridColour } },
            },
            plugins: { legend: { position: 'bottom', labels: { color: axisColour, usePointStyle: true } } },
        },
    });
};

const hydrateCharts = () => {
    for (const id of instances.keys()) {
        if (!document.getElementById(id)) destroyChart(id);
    }

    renderDashboardTrend();
    renderReportsChart();
    renderBudgetChart();
    renderNetWorthChart();
};

document.addEventListener('DOMContentLoaded', hydrateCharts);
document.addEventListener('livewire:navigated', hydrateCharts);
document.addEventListener('livewire:init', () => {
    window.Livewire.on('dashboard-trend-updated', (payload) => renderDashboardTrend(payload.chartData ?? payload));
    window.Livewire.on('reports-chart-data', (payload) => {
        renderReportsChart(payload.chartData ?? payload);
        renderBudgetChart(payload.budgetData);
        renderNetWorthChart(payload.netWorthData);
    });
});
