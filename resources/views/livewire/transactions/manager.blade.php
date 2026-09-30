<div class="space-y-4">
    <x-page-header eyebrow="Activity" title="Transactions" description="Find, review, and add the money moving through your accounts.">
        <button type="button" wire:click="openModal" class="app-button-primary">+ New transaction</button>
    </x-page-header>
    {{-- Status message --}}
    @if (session()->has('status'))
        <div class="rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 dark:bg-emerald-900/20 dark:border-emerald-800">
            <p class="text-sm font-medium text-emerald-800 dark:text-emerald-300">{{ session('status') }}</p>
        </div>
    @endif

    {{-- Main ledger card --}}
    <div class="app-card overflow-hidden">

        {{-- Toolbar --}}
        <div class="space-y-3 border-b border-app-border p-4 sm:p-5">
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <div class="w-full flex-1 sm:min-w-64">
                    <label for="transaction-search" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide app-muted">Search transactions</label>
                    <div class="relative">
                        <svg aria-hidden="true" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" stroke-width="1.8"/><path d="m16 16 4 4" stroke-linecap="round" stroke-width="1.8"/></svg>
                        <input id="transaction-search" type="search" wire:model.live.debounce.300ms="search" aria-describedby="transaction-search-help"
                               placeholder="Description, category, or amount"
                               class="app-field w-full border-2 border-zinc-300 bg-white py-2 pl-10 pr-3 text-sm shadow-sm placeholder:text-zinc-500 hover:border-zinc-400 focus:border-emerald-600 dark:border-zinc-600 dark:bg-zinc-900 dark:hover:border-zinc-500" />
                    </div>
                    <p id="transaction-search-help" class="mt-1.5 text-xs app-muted">Search all time · Recorded transactions only</p>
                </div>
                <span wire:loading wire:target="search" role="status" class="text-xs text-zinc-500 dark:text-zinc-400">Searching…</span>
            </div>
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="mr-1 text-xs font-semibold uppercase tracking-wider app-muted">Filter by</span>
                <select wire:model.live="filterParentCategory"
                        aria-label="Category filter" class="app-field app-filter-select min-w-40 flex-1 sm:flex-none {{ $filterParentCategory ? 'app-filter-select-active' : '' }}">
                    <option value="">All categories</option>
                    @foreach ($filterCategories as $parent)
                        <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                    @endforeach
                </select>
                @if ($filterSubCategories->isNotEmpty())
                    <select wire:model.live="filterSubCategory"
                            aria-label="Subcategory filter" class="app-field app-filter-select min-w-40 flex-1 sm:flex-none {{ $filterSubCategory ? 'app-filter-select-active' : '' }}">
                        <option value="">All subcategories</option>
                        @foreach ($filterSubCategories as $sub)
                            <option value="{{ $sub->id }}">{{ $sub->name }}</option>
                        @endforeach
                    </select>
                @endif
                <select wire:model.live="filterType"
                        aria-label="Transaction type filter" class="app-field app-filter-select min-w-32 flex-1 sm:flex-none {{ $filterType ? 'app-filter-select-active' : '' }}">
                    <option value="">All types</option>
                    <option value="{{ \App\Models\Transaction::TYPE_INCOME }}">Money in</option>
                    <option value="{{ \App\Models\Transaction::TYPE_EXPENSE }}">Money out</option>
                </select>
                @if ($search || $scope === 'all' || $filterParentCategory || $filterSubCategory || $filterType || $filterImportId || $filterTransactionId !== null)
                    <button type="button" wire:click="clearFilters" class="app-link text-sm">Clear filters</button>
                @endif
            </div>
            @if ($filterImportId)<p class="text-xs app-muted">Showing transactions from imported statement #{{ $filterImportId }}. You can edit an entry here to correct it.</p>@endif
        </div>

        @if ($showingRecorded)
            <div class="flex flex-wrap items-center gap-3 border-b border-zinc-200 px-4 py-2 text-sm dark:border-zinc-700">
                <span class="font-medium text-zinc-700 dark:text-zinc-200">{{ $filterTransactionId !== null ? 'Selected transaction' : ($searching ? 'Searching all dates' : 'All recorded dates') }}</span>
                <span class="text-zinc-500 dark:text-zinc-400">{{ $transactions->total() }} {{ \Illuminate\Support\Str::plural('result', $transactions->total()) }}</span>
                @unless ($searching)
                    <span class="app-muted">Recorded entries only; projected occurrences are excluded.</span>
                @endunless
                @if ($scope === 'all')
                    <button type="button" wire:click="showSelectedMonth" class="app-link ml-auto">View selected month</button>
                @endif
            </div>
        @endif

        <p class="border-b border-app-border px-4 py-2 text-xs app-muted sm:px-5">{{ $showingRecorded ? $transactions->total() : $transactions->count() }} {{ \Illuminate\Support\Str::plural('transaction', $showingRecorded ? $transactions->total() : $transactions->count()) }} {{ $showingRecorded ? 'recorded across all dates' : 'in the selected month' }}</p>

        {{-- Ledger table --}}
        <div class="hidden overflow-x-auto md:block">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Date</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Category</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Direction</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Amount</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Notes</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($transactions as $transaction)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <td class="px-3 py-2 whitespace-nowrap text-zinc-700 dark:text-zinc-300">
                                {{ \Carbon\Carbon::parse($transaction->date)->format('j M Y') }}
                                @if ($showingRecorded && $transaction->is_recurring)
                                    <span class="block text-xs text-zinc-500 dark:text-zinc-400">Recurring series · Started {{ \Carbon\Carbon::parse($transaction->date)->format('j M Y') }}</span>
                                @elseif ($transaction->getAttribute('projected'))
                                    <span class="block text-xs app-muted">Scheduled occurrence</span>
                                @else
                                    <span class="block text-xs app-muted">Recorded</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-zinc-700 dark:text-zinc-300">{{ $transaction->category->name ?? '—' }}</td>
                            <td class="px-3 py-2">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $transaction->type === \App\Models\Transaction::TYPE_INCOME ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400' }}">
                                    {{ $transaction->type === \App\Models\Transaction::TYPE_INCOME ? 'Money in' : 'Money out' }}
                                </span>
                                @if ($transaction->is_recurring)
                                    <span class="ml-1 text-xs text-zinc-400" title="Recurring {{ ucfirst($transaction->frequency) }}">↻</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right font-medium tabular-nums {{ $transaction->type === \App\Models\Transaction::TYPE_INCOME ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                                £{{ number_format($transaction->amount, 2) }}
                            </td>
                            <td class="px-3 py-2 max-w-xs truncate text-zinc-500 dark:text-zinc-400">{{ $transaction->description }}</td>
                            <td class="px-3 py-2 text-right whitespace-nowrap space-x-3">
                                <button type="button" wire:click="edit({{ $transaction->id }})"
                                        wire:loading.attr="disabled" wire:target="edit({{ $transaction->id }})"
                                        class="text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400">{{ $showingRecorded && $transaction->is_recurring ? 'Edit series' : 'Edit' }}</button>
                                <button
                                    type="button"
                                    @if ($showingRecorded && $transaction->is_recurring)
                                        wire:click="confirmDelete({{ $transaction->id }})"
                                    @else
                                        wire:click="confirmDelete({{ $transaction->id }}, '{{ \Carbon\Carbon::parse($transaction->date)->toDateString() }}')"
                                    @endif
                                    wire:loading.attr="disabled"
                                    wire:target="confirmDelete"
                                    class="text-xs font-medium text-rose-600 hover:text-rose-800 dark:text-rose-400"
                                >{{ $showingRecorded && $transaction->is_recurring ? 'Delete series' : 'Delete' }}</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-sm text-zinc-400">
                                @if ($searching)
                                    {{ $filterParentCategory || $filterSubCategory || $filterType ? 'No transactions match this search and the active filters.' : 'No transactions match this search.' }}
                                @elseif ($showingRecorded)
                                    No recorded transactions match these filters across all dates.
                                @else
                                    {{ $filterParentCategory || $filterSubCategory || $filterType ? 'No transactions found for this period with the active filters.' : 'No transactions found for this period.' }}
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="divide-y divide-app-border md:hidden">
            @forelse ($transactions as $transaction)
                <article class="p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="break-words text-sm font-semibold">{{ $transaction->description ?: ($transaction->category?->name ?? 'Transaction') }}</p>
                            <p class="mt-1 text-xs app-muted">{{ \Carbon\Carbon::parse($transaction->date)->format('j M Y') }} · {{ $transaction->category?->name ?? 'Uncategorised' }}</p>
                            @if ($transaction->is_recurring)
                                <span class="app-badge mt-2 bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">Recurring {{ $transaction->frequency }}</span>
                            @elseif ($transaction->getAttribute('projected'))
                                <span class="app-badge mt-2 bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">Scheduled occurrence</span>
                            @else
                                <span class="app-badge mt-2 bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">Recorded</span>
                            @endif
                        </div>
                        <span class="shrink-0 text-sm font-semibold tabular-nums {{ $transaction->type === \App\Models\Transaction::TYPE_INCOME ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400' }}">{{ $transaction->type === \App\Models\Transaction::TYPE_INCOME ? '+' : '−' }}{{ \App\Support\Money::format($transaction->amount) }}</span>
                    </div>
                    <div class="mt-3 flex gap-4 border-t border-app-border pt-3 text-sm">
                        <button type="button" wire:click="edit({{ $transaction->id }})" class="app-link">{{ $showingRecorded && $transaction->is_recurring ? 'Edit series' : 'Edit' }}</button>
                        <button type="button"
                            @if ($showingRecorded && $transaction->is_recurring)
                                wire:click="confirmDelete({{ $transaction->id }})"
                            @else
                                wire:click="confirmDelete({{ $transaction->id }}, '{{ \Carbon\Carbon::parse($transaction->date)->toDateString() }}')"
                            @endif
                            class="font-medium text-rose-700 dark:text-rose-400">{{ $showingRecorded && $transaction->is_recurring ? 'Delete series' : 'Delete' }}</button>
                    </div>
                </article>
            @empty
                <p class="app-empty m-4">{{ $searching ? 'No transactions match your search.' : ($showingRecorded ? 'No recorded transactions match these filters across all dates.' : ($filterParentCategory || $filterSubCategory || $filterType ? 'No transactions match these filters for this month.' : 'No transactions for this month yet. Add one to start your ledger.')) }}</p>
            @endforelse
        </div>
        @if ($showingRecorded && $transactions->hasPages())
            <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-700">{{ $transactions->links() }}</div>
        @endif
    </div>

    {{-- Transaction form modal --}}
    <flux:modal
        name="transaction-form"
        x-on:open-transaction-modal.window="$flux.modal('transaction-form').show()"
        x-on:close-transaction-modal.window="$flux.modal('transaction-form').close()"
        focusable
        class="min-w-0 w-[calc(100vw-2rem)] max-w-2xl"
    >
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $transactionId ? ($is_recurring ? 'Edit recurring series' : 'Edit transaction') : 'New transaction' }}</flux:heading>
                <p class="mt-1 text-sm app-muted">{{ $transactionId ? 'Review the details below before saving your changes.' : 'The date starts in your selected month. Change it if this entry belongs elsewhere.' }}</p>
            </div>
            @if ($transactionId && $is_recurring)
                <p class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-900/20 dark:text-amber-200">Changes to this series affect its generated occurrences. To preserve historical amounts, end this series and create a new one.</p>
            @endif
            <form wire:submit.prevent="save" class="space-y-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label for="transaction-amount" class="app-form-label">Amount (£)</label><input id="transaction-amount" type="number" min="0" step="0.01" inputmode="decimal" wire:model="amount" placeholder="0.00" class="app-field mt-1.5 w-full" />@error('amount') <p class="app-form-error">{{ $message }}</p> @enderror</div>
                    <div><label for="transaction-direction" class="app-form-label">Direction</label><select id="transaction-direction" wire:model.live="type" class="app-field mt-1.5 w-full"><option value="{{ \App\Models\Transaction::TYPE_INCOME }}">Money in</option><option value="{{ \App\Models\Transaction::TYPE_EXPENSE }}">Money out</option></select>@error('type') <p class="app-form-error">{{ $message }}</p> @enderror</div>
                    <div><label for="transaction-date" class="app-form-label">Date</label><input id="transaction-date" type="date" wire:model.live="date" class="app-field mt-1.5 w-full" />@error('date') <p class="app-form-error">{{ $message }}</p> @enderror</div>
                    <div><label for="transaction-category" class="app-form-label">Category</label><select id="transaction-category" wire:model="category_id" class="app-field mt-1.5 w-full"><option value="">Uncategorised</option>@foreach ($formCategories as $parent)@if ($parent->children->isNotEmpty())<optgroup label="{{ $parent->name }}">@foreach ($parent->children as $sub)<option value="{{ $sub->id }}">{{ $sub->name }}</option>@endforeach</optgroup>@else<option value="{{ $parent->id }}">{{ $parent->name }}</option>@endif @endforeach</select>@error('category_id') <p class="app-form-error">{{ $message }}</p> @enderror</div>
                </div>
                <div><label for="transaction-description" class="app-form-label">Description <span class="normal-case font-normal app-muted">(optional)</span></label><textarea id="transaction-description" wire:model="description" rows="2" placeholder="What was this for?" class="app-field mt-1.5 w-full"></textarea>@error('description') <p class="app-form-error">{{ $message }}</p> @enderror</div>
                <div class="rounded-xl border border-app-border bg-zinc-50/50 p-4 dark:bg-zinc-800/40">
                    <label class="flex cursor-pointer items-center gap-2.5"><input type="checkbox" wire:model.live="is_recurring" class="rounded border-zinc-300 text-emerald-600 focus:ring-emerald-500" /><span class="text-sm font-semibold">Repeat this transaction</span></label>
                    <p class="mt-1 pl-7 text-xs app-muted">Use this for regular income or payments. The schedule contributes to projected totals.</p>
                    @if ($is_recurring)
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div><label for="transaction-frequency" class="app-form-label">Frequency</label><select id="transaction-frequency" wire:model.live="frequency" class="app-field mt-1.5 w-full"><option value="">Select frequency</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option><option value="yearly">Yearly</option></select>@error('frequency') <p class="app-form-error">{{ $message }}</p> @enderror</div>
                            <div><label for="transaction-recurrence-end" class="app-form-label">Repeat until <span class="normal-case font-normal app-muted">(optional)</span></label><input id="transaction-recurrence-end" type="date" wire:model.live="recurring_until" class="app-field mt-1.5 w-full" />@error('recurring_until') <p class="app-form-error">{{ $message }}</p> @enderror</div>
                        </div>
                        @if ($this->recurringPreview())
                            <div class="mt-4 rounded-lg bg-app-surface p-3 text-sm"><p class="font-semibold">Expected dates</p><p class="mt-1 app-muted">{{ implode(' · ', $this->recurringPreview()) }}</p><p class="mt-1 text-xs app-muted">Preview of the first three scheduled dates, subject to skipped occurrences.</p></div>
                        @elseif ($frequency && $date)
                            <p class="mt-3 text-xs app-muted">Choose a valid start date and end date to preview this schedule.</p>
                        @endif
                    @endif
                </div>
                @error('save') <p class="app-form-error" role="alert">{{ $message }}</p> @enderror
                <div class="app-form-actions"><flux:modal.close><button type="button" class="app-button-secondary">Cancel</button></flux:modal.close><button type="submit" wire:loading.attr="disabled" wire:target="save" class="app-button-primary"><span wire:loading.remove wire:target="save">{{ $transactionId ? 'Save changes' : 'Add transaction' }}</span><span wire:loading wire:target="save">Saving…</span></button></div>
            </form>
        </div>
    </flux:modal>

    <flux:modal
        name="delete-transaction"
        x-on:open-delete-transaction-modal.window="$flux.modal('delete-transaction').show()"
        x-on:close-delete-transaction-modal.window="$flux.modal('delete-transaction').close()"
        focusable
        class="max-w-lg"
    >
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Delete transaction?</flux:heading>
                <flux:subheading class="mt-2">
                    @if ($deletingIsRecurring)
                        @if ($deletingOccurrenceDate)
                            Choose whether to remove only the occurrence on {{ $deletingOccurrenceDate }} or the entire recurring series for <strong>{{ $deletingDescription }}</strong>.
                        @else
                            This permanently deletes the entire recurring series for <strong>{{ $deletingDescription }}</strong>.
                        @endif
                    @else
                        This permanently deletes <strong>{{ $deletingDescription }}</strong>.
                    @endif
                </flux:subheading>
            </div>

            @error('delete') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror

            <div class="flex flex-wrap justify-end gap-3">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                @if ($deletingIsRecurring)
                    @if ($deletingOccurrenceDate)
                        <flux:button variant="danger" wire:click="delete(false)" wire:loading.attr="disabled" wire:target="delete">
                            Delete this occurrence
                        </flux:button>
                    @endif
                    <flux:button variant="danger" wire:click="delete(true)" wire:loading.attr="disabled" wire:target="delete">
                        Delete entire series
                    </flux:button>
                @else
                    <flux:button variant="danger" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">
                        Delete transaction
                    </flux:button>
                @endif
            </div>
        </div>
    </flux:modal>
</div>
