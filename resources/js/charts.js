import Chart from 'chart.js/auto';

const instances = new Map();
const themeColours = () => {
    const styles = getComputedStyle(document.documentElement);
    return {
        axis: styles.getPropertyValue('--color-app-muted').trim(),
        grid: styles.getPropertyValue('--color-app-border').trim(),
        income: styles.getPropertyValue('--color-finance-positive').trim(),
        spending: styles.getPropertyValue('--color-finance-negative').trim(),
        invested: styles.getPropertyValue('--color-finance-investment').trim(),
        savings: styles.getPropertyValue('--color-finance-savings').trim(),
    };
};

const seriesColour = (label, colours) => ({
    Income: colours.income,
    Assets: colours.income,
    Spending: colours.spending,
    Spent: colours.spending,
    Liabilities: colours.spending,
    Invested: colours.invested,
    Planned: colours.invested,
    'Net worth': colours.invested,
    Savings: colours.savings,
})[label];

const recolourCharts = () => {
    const colours = themeColours();

    for (const chart of instances.values()) {
        chart.options.scales.x.ticks.color = colours.axis;
        chart.options.scales.y.ticks.color = colours.axis;
        if (chart.options.scales.y.grid.display !== false) chart.options.scales.y.grid.color = colours.grid;
        chart.options.plugins.legend.labels.color = colours.axis;

        for (const dataset of chart.data.datasets) {
            const colour = seriesColour(dataset.label, colours);
            if (!colour) continue;
            dataset.borderColor = colour;
            dataset.backgroundColor = colour;
        }

        chart.update('none');
    }
};

let darkTheme = document.documentElement.classList.contains('dark');
new MutationObserver(() => {
    const nextDarkTheme = document.documentElement.classList.contains('dark');
    if (nextDarkTheme === darkTheme) return;
    darkTheme = nextDarkTheme;
    recolourCharts();
}).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
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

export const renderDashboardTrend = (chartData = parseData(document.getElementById('dashboardTrendChart'))) => {
    if (!chartData?.labels?.length || !document.getElementById('dashboardTrendChart')) {
        destroyChart('dashboardTrendChart');
        return;
    }

    const colours = themeColours();
    const axisColour = colours.axis;
    const gridColour = colours.grid;

    replaceChart('dashboardTrendChart', {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [
                { label: 'Income', data: chartData.income, borderColor: colours.income, backgroundColor: colours.income, tension: 0.35 },
                { label: 'Spending', data: chartData.spending, borderColor: colours.spending, backgroundColor: colours.spending, tension: 0.35 },
                { label: 'Invested', data: chartData.invested, borderColor: colours.invested, backgroundColor: colours.invested, tension: 0.35 },
                { label: 'Savings', data: chartData.savings, borderColor: colours.savings, backgroundColor: colours.savings, tension: 0.35 },
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

export const renderReportsChart = (chartData = parseData(document.getElementById('incomeVsExpensesChart'))) => {
    if (!chartData?.labels?.length || !document.getElementById('incomeVsExpensesChart')) {
        destroyChart('incomeVsExpensesChart');
        return;
    }

    const colours = themeColours();
    const axisColour = colours.axis;
    const gridColour = colours.grid;

    replaceChart('incomeVsExpensesChart', {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [
                { label: 'Income', data: chartData.income, borderColor: colours.income, backgroundColor: colours.income, tension: 0.3 },
                { label: 'Spending', data: chartData.spending, borderColor: colours.spending, backgroundColor: colours.spending, tension: 0.3 },
                { label: 'Invested', data: chartData.invested, borderColor: colours.invested, backgroundColor: colours.invested, tension: 0.3 },
                { label: 'Savings', data: chartData.savings, borderColor: colours.savings, backgroundColor: colours.savings, tension: 0.3 },
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

export const renderBudgetChart = (chartData = parseData(document.getElementById('budgetPerformanceChart'))) => {
    if (!chartData?.hasBudgets || !document.getElementById('budgetPerformanceChart')) {
        destroyChart('budgetPerformanceChart');
        return;
    }

    const colours = themeColours();
    const axisColour = colours.axis;
    replaceChart('budgetPerformanceChart', {
        type: 'bar',
        data: {
            labels: chartData.labels,
            datasets: [
                { label: 'Planned', data: chartData.planned, backgroundColor: colours.invested, borderRadius: 5 },
                { label: 'Spent', data: chartData.spent, backgroundColor: colours.spending, borderRadius: 5 },
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

export const renderNetWorthChart = (chartData = parseData(document.getElementById('netWorthChart'))) => {
    if (!chartData?.labels?.length || !document.getElementById('netWorthChart')) {
        destroyChart('netWorthChart');
        return;
    }

    const colours = themeColours();
    const axisColour = colours.axis;
    const gridColour = colours.grid;

    replaceChart('netWorthChart', {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [
                { label: 'Assets', data: chartData.assets, borderColor: colours.income, backgroundColor: colours.income, tension: 0.3 },
                { label: 'Liabilities', data: chartData.liabilities, borderColor: colours.spending, backgroundColor: colours.spending, tension: 0.3 },
                { label: 'Net worth', data: chartData.netWorth, borderColor: colours.invested, backgroundColor: colours.invested, tension: 0.3 },
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

export const hydrateCharts = () => {
    for (const id of instances.keys()) {
        if (!document.getElementById(id)) destroyChart(id);
    }

    renderDashboardTrend();
    renderReportsChart();
    renderBudgetChart();
    renderNetWorthChart();
};
