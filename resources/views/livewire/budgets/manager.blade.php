<div class="space-y-5">
    <x-page-header eyebrow="Plan" title="Budgets" description="Keep an eye on spending limits and investment goals for {{ $periodLabel }}.">
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="copyFromPreviousMonth" wire:loading.attr="disabled" wire:target="copyFromPreviousMonth" class="app-button-secondary" title="Copy budgets from {{ $previousPeriodLabel }} into {{ $periodLabel }}">
                <span wire:loading.remove wire:target="copyFromPreviousMonth">Copy previous month</span>
                <span wire:loading wire:target="copyFromPreviousMonth">Copying…</span>
            </button>
            <button type="button" wire:click="openModal" class="app-button-primary">+ New budget</button>
        </div>
    </x-page-header>
    <div class="grid gap-3 lg:grid-cols-2">
        <section class="app-card min-w-0 p-4 sm:p-5" aria-labelledby="spending-limits-summary-heading">
            <h2 id="spending-limits-summary-heading" class="mb-3 text-lg font-semibold">Spending limits</h2>
            <div class="grid gap-2 sm:grid-cols-3">
                <div class="min-w-0 rounded-xl border border-app-border bg-zinc-50 p-3 dark:bg-zinc-800/50"><p class="app-eyebrow">Planned</p><p class="mt-2 break-words text-lg font-semibold tabular-nums text-finance-investment xl:text-xl">£{{ \App\Support\Money::formatPennies($budgetTotals['planned']) }}</p></div>
                <div class="min-w-0 rounded-xl border border-app-border bg-zinc-50 p-3 dark:bg-zinc-800/50"><p class="app-eyebrow">Spent</p><p class="mt-2 break-words text-lg font-semibold tabular-nums text-finance-negative xl:text-xl">£{{ \App\Support\Money::formatPennies($budgetTotals['spent']) }}</p></div>
                <div class="min-w-0 rounded-xl border border-app-border bg-zinc-50 p-3 dark:bg-zinc-800/50"><p class="app-eyebrow">{{ $budgetTotals['remaining'] < 0 ? 'Over plan' : 'Remaining' }}</p><p class="mt-2 break-words text-lg font-semibold tabular-nums xl:text-xl {{ $budgetTotals['remaining'] < 0 ? 'text-finance-negative' : 'text-finance-positive' }}">£{{ \App\Support\Money::formatPennies(abs($budgetTotals['remaining'])) }}</p></div>
            </div>
        </section>
        <section class="app-card min-w-0 p-4 sm:p-5" aria-labelledby="investment-goals-summary-heading">
            <h2 id="investment-goals-summary-heading" class="mb-3 text-lg font-semibold">Investment goals</h2>
            <div class="grid gap-2 sm:grid-cols-3">
                <div class="min-w-0 rounded-xl border border-app-border bg-zinc-50 p-3 dark:bg-zinc-800/50"><p class="app-eyebrow">Target</p><p class="mt-2 break-words text-lg font-semibold tabular-nums text-finance-investment xl:text-xl">£{{ \App\Support\Money::formatPennies($investmentTotals['target']) }}</p></div>
                <div class="min-w-0 rounded-xl border border-app-border bg-zinc-50 p-3 dark:bg-zinc-800/50"><p class="app-eyebrow">Invested</p><p class="mt-2 break-words text-lg font-semibold tabular-nums text-finance-investment xl:text-xl">£{{ \App\Support\Money::formatPennies($investmentTotals['invested']) }}</p></div>
                <div class="min-w-0 rounded-xl border border-app-border bg-zinc-50 p-3 dark:bg-zinc-800/50"><p class="app-eyebrow">To goal</p><p class="mt-2 break-words text-lg font-semibold tabular-nums xl:text-xl {{ $investmentTotals['toGoal'] > 0 ? 'text-amber-700 dark:text-amber-300' : 'text-finance-positive' }}">£{{ \App\Support\Money::formatPennies($investmentTotals['toGoal']) }}</p></div>
            </div>
        </section>
    </div>
    {{-- Status messages --}}
    @if (session()->has('status'))
        <div class="rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 dark:bg-emerald-900/20 dark:border-emerald-800" data-action-feedback role="status">
            <p class="text-sm font-medium text-emerald-800 dark:text-emerald-300">{{ session('status') }}</p>
        </div>
    @endif
    @if (session()->has('copy_status'))
        <div class="rounded-md bg-blue-50 border border-blue-200 px-4 py-3 dark:bg-blue-900/20 dark:border-blue-800" data-action-feedback role="status">
            <p class="text-sm font-medium text-blue-800 dark:text-blue-300">{{ session('copy_status') }}</p>
        </div>
    @endif

    {{-- Main budgets card --}}
    <div class="app-card overflow-hidden">

        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-app-border p-4 sm:p-5">
            <div><h2 class="font-semibold">Budget progress</h2><p class="mt-1 text-sm app-muted">Spending limits and investment goals include projected recurring transactions through today.</p></div>
            <select wire:model.live="filterCategory" aria-label="Filter budgets by category" class="app-field app-filter-select text-sm">
                <option value="">All categories</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}{{ $category->expense_treatment === \App\Models\Category::TREATMENT_INVESTMENT ? ' · Investment goal' : '' }}</option>
                @endforeach
            </select>
        </div>

        {{-- Budgets table --}}
        <div class="hidden overflow-x-auto md:block" role="region" aria-label="Budget progress for {{ $periodLabel }}" tabindex="0">
            <table class="w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700" style="min-width: 52rem">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Category</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Limit / target</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Spent / invested</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Status</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400" style="min-width: 12rem">Progress</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($budgetRows as $row)
                        @php($budget = $row['budget'])
                        @php($summary = $row['summary'])
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <td class="px-3 py-2 font-medium text-zinc-900 dark:text-white"><a class="app-link" href="{{ route('transactions', ['type' => 'expense', 'category' => $budget->category_id]) }}" wire:navigate>{{ $budget->category->name }}</a>@if ($summary['isInvestment']) <span class="app-badge ml-1">Investment goal</span>@endif</td>
                            <td class="px-3 py-2 text-right font-medium tabular-nums text-zinc-900 dark:text-white">{{ \App\Support\Money::format($summary['budget']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-zinc-700 dark:text-zinc-300">{{ \App\Support\Money::format($summary['actual']) }}</td>
                            <td class="px-3 py-2 text-right whitespace-nowrap tabular-nums font-medium {{ $summary['overspent'] ? 'text-rose-700 dark:text-rose-400' : 'text-emerald-700 dark:text-emerald-400' }}">
                                @if ($summary['isInvestment'])
                                    {{ $summary['goalMet'] ? 'Goal met' : \App\Support\Money::format($summary['remaining']) . ' to goal' }}
                                @else
                                    {{ \App\Support\Money::format($summary['overspent'] ? $summary['over'] : $summary['remaining']) }} {{ $summary['overspent'] ? 'over' : 'remaining' }}
                                @endif
                            </td>
                            <td class="px-3 py-2">
                                <div class="h-2 rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar"
                                     aria-label="{{ $summary['category'] }} {{ $summary['isInvestment'] ? 'goal progress' : 'budget used' }}" aria-valuemin="0" aria-valuemax="100"
                                     aria-valuenow="{{ $summary['barPercent'] }}"
                                     aria-valuetext="{{ $summary['isInvestment'] ? $summary['percent'] . '% of goal reached' : ($summary['percent'] === null ? 'Over budget with no limit' : $summary['percent'] . '% used') }}">
                                    <div class="h-2 rounded-full {{ $summary['overspent'] ? 'bg-rose-500' : 'bg-emerald-500' }}" style="width: {{ $summary['barPercent'] }}%"></div>
                                </div>
                                <span class="mt-1 block text-xs {{ $summary['overspent'] ? 'text-rose-700 dark:text-rose-400' : 'text-zinc-600 dark:text-zinc-400' }}">
                                    {{ $summary['isInvestment'] ? $summary['percent'] . '% of goal' : ($summary['percent'] === null ? 'Over budget' : $summary['percent'] . '% used') }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right whitespace-nowrap space-x-3">
                                <button type="button" wire:click="edit({{ $budget->id }})" class="text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400">Edit</button>
                                <button type="button" wire:click="confirmDelete({{ $budget->id }})"
                                        wire:loading.attr="disabled" wire:target="confirmDelete"
                                        class="text-xs font-medium text-rose-600 hover:text-rose-800 dark:text-rose-400">Delete</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-sm text-zinc-600 dark:text-zinc-400">No budgets defined for this period.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="divide-y divide-app-border md:hidden">
            @forelse ($budgetRows as $row)
                @php($budget = $row['budget'])
                @php($summary = $row['summary'])
                <article class="p-4">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div><h3 class="font-semibold"><a class="app-link" href="{{ route('transactions', ['type' => 'expense', 'category' => $budget->category_id]) }}" wire:navigate>{{ $summary['category'] }}</a></h3><p class="mt-1 text-sm app-muted">{{ \App\Support\Money::format($summary['actual']) }} of {{ \App\Support\Money::format($summary['budget']) }} {{ $summary['isInvestment'] ? 'invested' : 'spent' }}</p></div>
                        <span class="app-badge {{ $summary['overspent'] ? 'bg-rose-100 text-rose-800 dark:bg-rose-900/30 dark:text-rose-300' : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300' }}">{{ $summary['isInvestment'] ? ($summary['goalMet'] ? 'Goal met' : \App\Support\Money::format($summary['remaining']) . ' to goal') : ($summary['overspent'] ? \App\Support\Money::format($summary['over']) . ' over' : \App\Support\Money::format($summary['remaining']) . ' left') }}</span>
                    </div>
                    <div class="mt-3 h-2 rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" aria-label="{{ $summary['category'] }} {{ $summary['isInvestment'] ? 'goal progress' : 'budget used' }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $summary['barPercent'] }}" aria-valuetext="{{ $summary['isInvestment'] ? $summary['percent'] . '% of goal reached' : ($summary['percent'] === null ? 'Over budget' : $summary['percent'] . '% used') }}">
                        <div class="h-2 rounded-full {{ $summary['overspent'] ? 'bg-rose-500' : 'bg-emerald-500' }}" style="width: {{ $summary['barPercent'] }}%"></div>
                    </div>
                    <div class="mt-3 flex gap-4 text-sm"><button type="button" wire:click="edit({{ $budget->id }})" class="app-link">Edit</button><button type="button" wire:click="confirmDelete({{ $budget->id }})" class="font-medium text-rose-700 dark:text-rose-400">Delete</button></div>
                </article>
            @empty
                <p class="app-empty m-4">No budgets for this month. Add a budget or copy one from last month to get started.</p>
            @endforelse
        </div>
    </div>

    {{-- Budget form modal --}}
    <flux:modal
        name="budget-form"
        x-on:open-budget-modal.window="$flux.modal('budget-form').show()"
        x-on:close-budget-modal.window="$flux.modal('budget-form').close()"
        focusable
        class="min-w-0 w-[calc(100vw-2rem)] max-w-xl"
    >
        <div class="space-y-5">
            @php($selectedBudgetIsInvestment = $categories->firstWhere('id', $category_id)?->expense_treatment === \App\Models\Category::TREATMENT_INVESTMENT)
            <div><flux:heading size="lg">{{ $budgetId ? 'Edit budget' : 'New budget' }}</flux:heading><p class="mt-1 text-sm app-muted">Set a spending limit or investment goal for one category.</p></div>
            @if ($editingBudgetSummary && (int) $category_id === $editingBudgetCategoryId && $month === $periodMonth && $year === $periodYear)
                @php($newRemaining = is_numeric($amount) ? \App\Support\Money::normalize($amount) - \App\Support\Money::normalize($editingBudgetSummary['actual']) : null)
                <div class="rounded-xl border border-app-border bg-zinc-50 p-4 text-sm dark:bg-zinc-800/50">
                    <p class="font-semibold">{{ $editingBudgetSummary['category'] }} this month</p>
                    <div class="mt-2 flex flex-wrap gap-x-6 gap-y-1"><span>{{ $selectedBudgetIsInvestment ? 'Invested' : 'Spent' }} through today: <strong>{{ \App\Support\Money::format($editingBudgetSummary['actual']) }}</strong></span>@if ($newRemaining !== null)<span>{{ $selectedBudgetIsInvestment ? ($newRemaining <= 0 ? 'Goal met:' : 'To new goal:') : ($newRemaining < 0 ? 'Over new limit:' : 'Left with new limit:') }} <strong class="{{ !$selectedBudgetIsInvestment && $newRemaining < 0 ? 'text-rose-700 dark:text-rose-400' : '' }}">£{{ \App\Support\Money::formatPennies($selectedBudgetIsInvestment ? max(0, $newRemaining) : abs($newRemaining)) }}</strong></span>@endif</div>
                </div>
            @endif
            <form wire:submit.prevent="save" class="space-y-4">
                <div>
                    <label for="budget-category" class="app-form-label">Category</label>
                    <select id="budget-category" wire:model.live="category_id"
                            class="app-field mt-1.5 w-full">
                        <option value="">Select category</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}{{ $category->expense_treatment === \App\Models\Category::TREATMENT_INVESTMENT ? ' · Investment goal' : '' }}</option>
                        @endforeach
                    </select>
                    @error('category_id') <p class="app-form-error" data-action-error role="alert">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="budget-month" class="app-form-label">Month</label>
                        <select id="budget-month" wire:model.live="month"
                                class="app-field mt-1.5 w-full">
                            @foreach (range(1, 12) as $m)
                                <option value="{{ $m }}">{{ now()->startOfYear()->month($m)->format('F') }}</option>
                            @endforeach
                        </select>
                        @error('month') <p class="app-form-error" data-action-error role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="budget-year" class="app-form-label">Year</label>
                        <input id="budget-year" type="number" wire:model.live="year" min="2000" max="2100"
                               class="app-field mt-1.5 w-full"/>
                        @error('year') <p class="app-form-error" data-action-error role="alert">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label for="budget-amount" class="app-form-label">{{ $selectedBudgetIsInvestment ? 'Monthly target (£)' : 'Monthly limit (£)' }}</label>
                    <input id="budget-amount" type="number" min="{{ $selectedBudgetIsInvestment ? '0.01' : '0' }}" step="0.01" inputmode="decimal" wire:model.live="amount" placeholder="0.00"
                           class="app-field mt-1.5 w-full"/>
                    @error('amount') <p class="app-form-error" data-action-error role="alert">{{ $message }}</p> @enderror
                </div>

                @error('save') <p class="text-sm text-rose-600" data-action-error role="alert">{{ $message }}</p> @enderror

                <div class="app-form-actions">
                    <flux:modal.close>
                        <button type="button" class="app-button-secondary">
                            Cancel
                        </button>
                    </flux:modal.close>
                    <button type="submit" wire:loading.attr="disabled" wire:target="save"
                            class="app-button-primary">
                        <span wire:loading.remove wire:target="save">{{ $budgetId ? 'Save changes' : 'Add budget' }}</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                </div>
            </form>
        </div>
    </flux:modal>

    <flux:modal
        name="delete-budget"
        x-on:open-delete-budget-modal.window="$flux.modal('delete-budget').show()"
        x-on:close-delete-budget-modal.window="$flux.modal('delete-budget').close()"
        focusable
        class="max-w-lg"
    >
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Delete budget?</flux:heading>
                <flux:subheading class="mt-2">
                    This permanently deletes the budget{{ $deletingBudgetLabel ? ' for ' . $deletingBudgetLabel : '' }}. Existing transactions are not removed.
                </flux:subheading>
            </div>

            <div class="flex justify-end gap-3">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">
                    Delete budget
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
