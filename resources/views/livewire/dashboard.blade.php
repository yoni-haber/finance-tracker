<div class="flex flex-col gap-5 sm:gap-7">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-[#147d5a] dark:text-[#75ddb2]">Your money at a glance</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight sm:text-4xl">{{ $periodLabel }} overview</h1>
            <p class="mt-2 text-sm app-muted">Monthly totals include projected recurring transactions.</p>
        </div>
    </header>

    <section aria-label="Monthly summary" class="grid grid-cols-2 gap-2 sm:gap-3 xl:grid-cols-4">
        @foreach ([
            ['label' => 'Income', 'value' => $income, 'accent' => 'text-[#126e51] dark:text-[#75ddb2]'],
            ['label' => 'Spending', 'value' => $spending, 'accent' => 'text-[#bd5b52] dark:text-[#f19b91]'],
            ['label' => 'Invested', 'value' => $invested, 'accent' => 'text-[#4d6f96] dark:text-[#a7c9ec]'],
            ['label' => 'Savings', 'value' => $savings, 'accent' => (float) $savings < 0 ? 'text-[#bd5b52] dark:text-[#f19b91]' : 'text-[#126e51] dark:text-[#75ddb2]'],
        ] as $metric)
            <div class="app-card min-w-0 p-3 sm:p-6">
                <p class="text-sm font-medium app-muted">{{ $metric['label'] }}</p>
                <p class="mt-2 break-words text-xl font-semibold tracking-tight tabular-nums {{ $metric['accent'] }} sm:mt-3 sm:text-3xl">{{ (float) $metric['value'] < 0 ? '−' : '' }}{{ \App\Support\Money::format(ltrim((string) $metric['value'], '-')) }}</p>
                @if ($metric['label'] === 'Savings')
                    <p class="mt-2 text-xs app-muted">{{ $savingsIsEstimate ? 'Estimated month-end remainder' : 'Income less spending and investing' }}</p>
                @endif
            </div>
        @endforeach
    </section>

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="app-card p-5 sm:p-6" aria-labelledby="dashboard-budgets-heading">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 id="dashboard-budgets-heading" class="text-lg font-semibold tracking-tight">Budgets needing attention</h2>
                    <p class="mt-1 text-sm app-muted">Spending limits near or over plan. Investment goals appear here after month-end.</p>
                </div>
                <a class="app-link shrink-0 text-sm" href="{{ route('budgets') }}" wire:navigate>All budgets</a>
            </div>
            @forelse ($budgetHighlights as $summary)
                <div class="mt-5 border-t border-[#e4e9e1] pt-4 dark:border-[#304038]">
                    <div class="flex justify-between gap-3 text-sm">
                        <a class="app-link font-medium" href="{{ route('transactions', ['type' => 'expense', 'category' => $summary['category_id']]) }}" wire:navigate>{{ $summary['category'] }}</a>
                        <span class="font-semibold tabular-nums {{ $summary['overspent'] || $summary['isInvestment'] ? 'text-[#bd5b52] dark:text-[#f19b91]' : 'text-[#126e51] dark:text-[#75ddb2]' }}">{{ \App\Support\Money::format($summary['overspent'] ? $summary['over'] : $summary['remaining']) }} {{ $summary['isInvestment'] ? 'short of goal' : ($summary['overspent'] ? 'over' : 'left') }}</span>
                    </div>
                    <p class="mt-1 text-xs app-muted">{{ \App\Support\Money::format($summary['actual']) }} of {{ \App\Support\Money::format($summary['budget']) }} {{ $summary['isInvestment'] ? 'invested' : 'used' }}</p>
                    <div class="mt-3 h-2 rounded-full bg-[#e8eee6] dark:bg-[#314539]" role="progressbar" aria-label="{{ $summary['category'] }} {{ $summary['isInvestment'] ? 'goal progress' : 'budget used' }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $summary['barPercent'] }}" aria-valuetext="{{ $summary['isInvestment'] ? $summary['percent'] . '% of goal reached' : ($summary['percent'] === null ? 'Over budget with no limit' : $summary['percent'] . '% used') }}">
                        <div class="h-2 rounded-full {{ $summary['overspent'] ? 'bg-[#bd5b52]' : 'bg-[#54a886]' }}" style="width: {{ $summary['barPercent'] }}%"></div>
                    </div>
                </div>
            @empty
                <p class="mt-6 rounded-xl bg-[#f4f7f2] px-5 py-8 text-center text-sm app-muted dark:bg-[#26362b]">{{ $hasBudgets ? 'No budgets need attention for this period.' : 'No budgets for this period.' }} <a class="app-link" href="{{ route('budgets') }}" wire:navigate>{{ $hasBudgets ? 'View all budgets' : 'Create a budget' }}</a>.</p>
            @endforelse
        </section>

        <section class="app-card p-5 sm:p-6" aria-labelledby="dashboard-activity-heading">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 id="dashboard-activity-heading" class="text-lg font-semibold tracking-tight">Recent activity</h2>
                    <p class="mt-1 text-sm app-muted">Recorded entries in {{ $periodLabel }} through today</p>
                </div>
                <a class="app-link shrink-0 text-sm" href="{{ route('transactions') }}" wire:navigate>All activity</a>
            </div>
            @forelse ($recentTransactions as $transaction)
                <div class="flex items-center justify-between gap-3 border-b border-[#e4e9e1] py-3 last:border-b-0 dark:border-[#304038]">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">{{ $transaction->description ?: ($transaction->category?->name ?? 'Transaction') }}</p>
                        <p class="mt-0.5 text-xs app-muted">{{ $transaction->date->format('j M') }} · {{ $transaction->category?->name ?? 'Uncategorised' }}</p>
                    </div>
                    <span class="shrink-0 text-sm font-semibold tabular-nums {{ $transaction->type === \App\Models\Transaction::TYPE_INCOME ? 'text-[#126e51] dark:text-[#75ddb2]' : 'text-[#bd5b52] dark:text-[#f19b91]' }}">{{ $transaction->type === \App\Models\Transaction::TYPE_INCOME ? '+' : '−' }}{{ \App\Support\Money::format($transaction->amount) }}</span>
                </div>
            @empty
                <p class="mt-6 rounded-xl bg-[#f4f7f2] px-5 py-8 text-center text-sm app-muted dark:bg-[#26362b]">No recorded transactions for this period yet. <a class="app-link" href="{{ route('transactions') }}" wire:navigate>Add one</a> to get started.</p>
            @endforelse
        </section>
    </div>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1.6fr)_minmax(320px,1fr)]">
        <section class="app-card min-w-0 p-5 sm:p-6" aria-labelledby="dashboard-trend-heading">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0 flex-1">
                    <h2 id="dashboard-trend-heading" class="text-lg font-semibold tracking-tight">Cash flow trend</h2>
                    <p class="mt-1 text-sm app-muted">Six months ending {{ $periodLabel }}, including projected recurring transactions.</p>
                </div>
                <a class="app-link shrink-0 whitespace-nowrap text-sm" href="{{ route('reports') }}" wire:navigate>Explore reports</a>
            </div>
            @if (collect($trend['income'])->merge($trend['spending'])->merge($trend['invested'])->merge($trend['savings'])->contains(fn ($value) => (float) $value !== 0.0))
                <div class="mt-6 h-64">
                    <canvas id="dashboardTrendChart" wire:ignore data-chart-data='@json($trend)' role="img" aria-label="Monthly income, spending, investing, and savings trend" aria-describedby="dashboard-trend-data"></canvas>
                </div>
            @else
                <p class="mt-6 rounded-xl bg-[#f4f7f2] px-5 py-10 text-center text-sm app-muted dark:bg-[#26362b]">
                    No cash flow data for these months yet. <a class="app-link" href="{{ route('transactions') }}" wire:navigate>Add a transaction</a> to get started.
                </p>
            @endif
            <details id="dashboard-trend-data" class="mt-5 text-sm">
                <summary class="cursor-pointer font-medium app-link">View chart data</summary>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-sm tabular-nums">
                        <thead><tr class="border-b border-[#e4e9e1] dark:border-[#304038]"><th scope="col" class="py-2 text-left">Month</th><th scope="col" class="py-2 text-right">Income</th><th scope="col" class="py-2 text-right">Spending</th><th scope="col" class="py-2 text-right">Invested</th><th scope="col" class="py-2 text-right">Savings</th></tr></thead>
                        <tbody class="divide-y divide-[#edf0ea] dark:divide-[#304038]">
                            @foreach ($trend['labels'] as $index => $label)
                                <tr><th scope="row" class="py-2 text-left font-medium">{{ $label }}</th><td class="py-2 text-right">{{ \App\Support\Money::format($trend['income'][$index]) }}</td><td class="py-2 text-right">{{ \App\Support\Money::format($trend['spending'][$index]) }}</td><td class="py-2 text-right">{{ \App\Support\Money::format($trend['invested'][$index]) }}</td><td class="py-2 text-right">{{ \App\Support\Money::format($trend['savings'][$index]) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        </section>

        <section class="app-card p-5 sm:p-6" aria-labelledby="dashboard-spending-heading">
            <h2 id="dashboard-spending-heading" class="text-lg font-semibold tracking-tight">Where spending went</h2>
            <p class="mt-1 text-sm app-muted">Top categories for {{ $periodLabel }}</p>
            @if ($spendingCategoryBreakdown->isEmpty())
                <p class="mt-6 rounded-xl bg-[#f4f7f2] px-5 py-10 text-center text-sm app-muted dark:bg-[#26362b]">No spending recorded for this period.</p>
            @else
                <ol class="mt-6 space-y-5">
                    @foreach ($spendingCategoryBreakdown->take(5) as $category)
                        <li>
                            <div class="flex items-baseline justify-between gap-3 text-sm">
                                @if ($category['category_id'] !== null)
                                    <a class="app-link min-w-0 truncate" href="{{ route('transactions', ['type' => 'expense', 'category' => $category['category_id']]) }}" wire:navigate>{{ $category['category'] }}</a>
                                @else
                                    <span class="min-w-0 truncate font-medium">{{ $category['category'] }}</span>
                                @endif
                                <span class="shrink-0 font-semibold tabular-nums">{{ \App\Support\Money::format($category['total']) }}</span>
                            </div>
                            <div class="mt-2 h-2 rounded-full bg-[#e8eee6] dark:bg-[#314539]" aria-hidden="true">
                                <div class="h-2 rounded-full bg-[#54a886] dark:bg-[#67c99e]" style="width: {{ min(100, max(3, round((float) $category['total'] / (float) $spending * 100))) }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ol>
                <details class="mt-6 border-t border-[#e4e9e1] pt-4 text-sm dark:border-[#304038]">
                    <summary class="cursor-pointer app-link">View all category data</summary>
                    <div class="mt-3 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead><tr><th scope="col" class="py-2 text-left">Category</th><th scope="col" class="py-2 text-right">Amount</th></tr></thead>
                            <tbody class="divide-y divide-[#edf0ea] dark:divide-[#304038]">
                                @foreach ($spendingCategoryBreakdown as $category)
                                    <tr><th scope="row" class="py-2 text-left font-medium">{{ $category['category'] }}</th><td class="py-2 text-right tabular-nums">{{ \App\Support\Money::format($category['total']) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @endif
        </section>
    </div>

    <section class="app-card w-full max-w-xl self-start p-4 sm:p-5" aria-labelledby="dashboard-bills-heading">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="dashboard-bills-heading" class="text-lg font-semibold tracking-tight">Planned bills</h2>
                <p class="mt-1 text-xs app-muted">Selected month and next two months. Estimates only.</p>
            </div>
            <a class="app-link shrink-0 text-sm" href="{{ route('bills') }}" wire:navigate>Manage bills</a>
        </div>
        <div class="mt-4 space-y-3">
            @foreach ($billMonths as $month)
                <div>
                    <h3 class="border-b border-app-border pb-2 text-xs font-semibold">{{ $month['label'] }}</h3>
                    <ul class="divide-y divide-app-border">
                        @forelse ($month['rows'] as $row)
                            <li class="flex items-start justify-between gap-3 py-2 text-sm">
                                <div class="min-w-0">
                                    <p class="break-words font-medium">{{ $row['bill']->name }}</p>
                                    <p class="mt-0.5 text-xs app-muted">{{ $row['date']->format('j M') }}@if ($row['payment']) · Paid {{ $row['payment']->transaction->date->format('j M') }}@endif</p>
                                </div>
                                <span class="shrink-0 font-semibold tabular-nums">{{ \App\Support\Money::format($row['payment']?->transaction->amount ?? $row['bill']->estimated_amount) }}</span>
                            </li>
                        @empty
                            <li class="py-2 text-xs app-muted">No bills this month.</li>
                        @endforelse
                    </ul>
                </div>
            @endforeach
        </div>
    </section>
</div>
