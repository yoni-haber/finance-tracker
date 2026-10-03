@php use App\Models\Transaction; @endphp
<div class="space-y-5">
    <x-page-header eyebrow="Import" title="Review statement" description="Check possible matches, choose which transactions to keep, and assign categories before importing." />
    @if (session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-900/20" role="status" data-action-feedback>
            <div class="flex">
                <div class="ml-3">
                    <p class="text-sm font-medium text-emerald-800 dark:text-emerald-200">{{ session('status') }}</p>
                </div>
            </div>
        </div>
    @endif

    <!-- Import Summary -->
    <div class="app-card p-4 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h3 class="text-base font-semibold text-zinc-900 dark:text-white">Import Summary</h3>
                <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ $import->original_filename }}
                    @if ($import->bankProfile)
                        &nbsp;·&nbsp; {{ $import->bankProfile->name }}
                    @endif
                    &nbsp;·&nbsp; {{ $import->statement_type === 'credit_card' ? 'Credit Card' : 'Bank Statement' }}
                </p>
            </div>
            <button
                wire:click="backToImport"
                class="app-button-secondary"
            >
                ← Back
            </button>
        </div>

        <div class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div class="rounded-lg bg-zinc-50 px-4 py-3 dark:bg-zinc-800">
                <div class="text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $summary['total'] }}</div>
                <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">Total transactions</div>
            </div>
            <div class="rounded-lg bg-emerald-50 px-4 py-3 dark:bg-emerald-900/20">
                <div
                    class="text-2xl font-bold tabular-nums text-emerald-700 dark:text-emerald-400">{{ $summary['new_transactions'] }}</div>
                <div class="mt-0.5 text-xs text-emerald-600 dark:text-emerald-500">New</div>
            </div>
            <div class="rounded-lg bg-amber-50 px-4 py-3 dark:bg-amber-900/20">
                <div
                    class="text-2xl font-bold tabular-nums text-amber-700 dark:text-amber-400">{{ $summary['duplicates'] }}</div>
                <div class="mt-0.5 text-xs text-amber-600 dark:text-amber-500">Duplicates (skipped)</div>
            </div>
            <div class="rounded-lg bg-zinc-50 px-4 py-3 dark:bg-zinc-800">
                <div
                    class="text-2xl font-bold tabular-nums {{ $summary['total_amount'] >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400' }}">
                    £{{ number_format(abs($summary['total_amount']), 2) }}
                </div>
                <div
                    class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $summary['total_amount'] >= 0 ? 'Net credit' : 'Net debit' }}</div>
            </div>
        </div>
        <p class="mt-3 text-xs app-muted">Net credit or debit is the movement on this statement. Money-in entries will count as income when imported.</p>

        @if ($summary['possible_duplicates'] > 0)
            <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-100">
                Review before importing: {{ $summary['possible_duplicates'] }} matching {{ \Illuminate\Support\Str::plural('row', $summary['possible_duplicates']) }} within this file. Matching charges may both be genuine.
            </div>
        @endif

        @if ($summary['new_transactions'] > 0)
            <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                    Ready to import <strong
                        class="text-zinc-900 dark:text-white">{{ $summary['new_transactions'] }}</strong> new
                    transaction{{ $summary['new_transactions'] !== 1 ? 's' : '' }}.
                </p>
                <flux:modal.trigger name="confirm-import-commit">
                    <button
                        class="app-button-primary">
                        Import {{ $summary['new_transactions'] }}
                        transaction{{ $summary['new_transactions'] !== 1 ? 's' : '' }}
                    </button>
                </flux:modal.trigger>
            </div>
        @else
            <p class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">No new transactions to import.</p>
        @endif
    </div>

    <!-- Bulk Actions Toolbar -->
    @if (count($selectedTransactionIds) > 0)
        <div
            class="app-card sticky top-2 z-10 border-emerald-200 bg-emerald-50 p-3 shadow-sm dark:border-emerald-900 dark:bg-emerald-950/90">
            <div class="flex flex-wrap items-center gap-4">
                <span class="text-sm font-medium text-emerald-900 dark:text-emerald-100">
                    {{ count($selectedTransactionIds) }} transaction{{ count($selectedTransactionIds) !== 1 ? 's' : '' }} selected
                </span>
                <button
                    wire:click="$set('selectedTransactionIds', [])"
                    class="app-link text-xs"
                >
                    Clear selection
                </button>

                <div class="ml-auto flex flex-wrap items-center gap-3">
                    <!-- Bulk Category Assignment -->
                    <div class="flex items-center gap-2">
                        <label class="text-sm text-emerald-900 dark:text-emerald-100">Assign category:</label>
                        <select
                            wire:change="bulkAssignCategory($event.target.value)"
                            class="app-field text-sm"
                        >
                            <option value="">— select —</option>
                            @foreach (($bulkSelectionType ? $categories->where('type', $bulkSelectionType) : $categories) as $parent)
                                @if ($parent->children->isNotEmpty())
                                    <optgroup label="{{ $parent->name }}">
                                        @foreach ($parent->children as $sub)
                                            <option value="{{ $sub->id }}">{{ $sub->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @else
                                    <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>

                    <!-- Bulk Delete -->
                    <button
                        wire:click="confirmBulkDelete"
                        class="inline-flex items-center gap-1 rounded-md border border-red-300 px-3 py-1.5 text-sm text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-400 dark:hover:bg-red-900/20"
                    >
                        Delete selected
                    </button>
                </div>
            </div>

            @error('bulk_assign')
            <p class="mt-2 text-sm text-red-700 dark:text-red-400" data-action-error role="alert">{{ $message }}</p>
            @enderror
        </div>
    @endif

    <!-- Transaction List -->
    <div class="app-card overflow-hidden">
        <div class="border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
            <h3 class="text-base font-semibold text-zinc-900 dark:text-white">Transaction Details</h3>
            <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">Review, edit, categorize, or remove transactions
                before importing.</p>
        </div>

        <div class="flex flex-wrap gap-2 border-b border-app-border px-4 py-3 sm:px-6" role="group" aria-label="Filter imported transactions">
            @foreach (['all' => 'All', 'needs_attention' => 'Needs a category', 'ready' => 'Ready', 'possible' => 'Possible matches', 'duplicates' => 'Skipped duplicates'] as $filterValue => $filterLabel)
                <button type="button" wire:click="$set('viewFilter', '{{ $filterValue }}')" aria-pressed="{{ $viewFilter === $filterValue ? 'true' : 'false' }}" class="rounded-full px-3 py-1.5 text-xs font-medium transition-colors {{ $viewFilter === $filterValue ? 'bg-emerald-600 text-white hover:bg-emerald-700 hover:text-white dark:bg-emerald-700 dark:text-white dark:hover:bg-emerald-600 dark:hover:text-white' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 hover:text-zinc-900 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700 dark:hover:text-white' }}">{{ $filterLabel }} <span class="tabular-nums">{{ match ($filterValue) { 'all' => $summary['total'], 'needs_attention' => $summary['needs_attention'], 'ready' => $summary['new_transactions'] - $summary['needs_attention'], 'possible' => $summary['possible_duplicates'], default => $summary['duplicates'] } }}</span></button>
            @endforeach
        </div>
        <div class="grid gap-3 p-3 sm:p-4">
            @forelse ($transactions as $transaction)
                @php
                    $mobileType = $import->statement_type === 'credit_card'
                        ? ($transaction->amount < 0 ? Transaction::TYPE_EXPENSE : Transaction::TYPE_INCOME)
                        : ($transaction->amount >= 0 ? Transaction::TYPE_INCOME : Transaction::TYPE_EXPENSE);
                @endphp
                <article wire:key="import-row-{{ $transaction->id }}" class="min-w-0 rounded-xl border border-app-border bg-app-surface p-4">
                    @if ($editingTransactionId === $transaction->id)
                        <div class="space-y-3">
                            <h3 class="font-semibold">Edit imported transaction</h3>
                            <label class="block text-sm">Date<input type="date" wire:model="editForm.date" class="app-field mt-1 w-full"></label>
                            <label class="block text-sm">Description<textarea wire:model="editForm.description" rows="2" class="app-field mt-1 w-full"></textarea></label>
                            <div class="grid grid-cols-2 gap-2"><label class="block text-sm">Amount<input type="number" step="0.01" min="0.01" wire:model="editForm.amount" class="app-field mt-1 w-full"></label><label class="block text-sm">Direction<select wire:model.live="editForm.type" class="app-field mt-1 w-full"><option value="expense">Money out</option><option value="income">Money in</option></select></label></div>
                            <label class="block text-sm">Category<select wire:model="editForm.category_id" class="app-field mt-1 w-full"><option value="">Uncategorised</option>@foreach ($categories->where('type', $editForm['type'] ?? '') as $parent)@if ($parent->children->isNotEmpty())<optgroup label="{{ $parent->name }}">@foreach ($parent->children as $sub)<option value="{{ $sub->id }}">{{ $sub->name }}</option>@endforeach</optgroup>@else<option value="{{ $parent->id }}">{{ $parent->name }}</option>@endif @endforeach</select></label>
                            @foreach (['date', 'description', 'amount', 'type', 'category_id'] as $field) @error('editForm.' . $field)<p class="text-xs text-rose-700" data-action-error role="alert">{{ $message }}</p>@enderror @endforeach
                            <div class="flex flex-wrap gap-2 border-t border-app-border pt-3"><button type="button" wire:click="cancelEdit" class="app-button-secondary">Cancel</button><button type="button" wire:click="updateTransaction" class="app-button-primary">Save changes</button></div>
                        </div>
                    @else
                        <div class="flex flex-wrap items-start gap-3 lg:grid lg:grid-cols-[minmax(0,1fr)_6rem_minmax(10rem,14rem)_5rem] lg:items-center">
                            <div class="min-w-0 flex-1">
                                <p class="break-words font-semibold">{{ $transaction->description }}</p>
                                <p class="mt-1 text-xs app-muted">{{ $transaction->date->format('j M Y') }} · {{ $mobileType === Transaction::TYPE_INCOME ? 'Money in' : 'Money out' }}</p>
                                @if (in_array($transaction->id, $possibleDuplicateIds, true))<p class="mt-1 text-xs font-medium text-amber-800 dark:text-amber-300">Possible match in this file · check both charges</p>@endif
                            </div>
                            <p class="shrink-0 font-semibold tabular-nums lg:text-right">{{ \App\Support\Money::format(abs($transaction->amount)) }}</p>
                            @if ($transaction->is_duplicate)
                                <span class="app-badge basis-full bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">Duplicate · skipped</span>
                            @else
                                <div class="min-w-0 basis-full">
                                    <div class="flex items-center gap-2"><input type="checkbox" wire:model.live="selectedTransactionIds" value="{{ $transaction->id }}" aria-label="Select {{ $transaction->description }}" class="rounded border-zinc-300"><select wire:change="updateCategory({{ $transaction->id }}, $event.target.value)" aria-label="Category for {{ $transaction->description }}" class="app-field min-w-0 flex-1 text-sm"><option value="" @selected($transaction->category_id === null)>Uncategorised</option>@foreach ($categories->where('type', $mobileType) as $parent)@if ($parent->children->isNotEmpty())<optgroup label="{{ $parent->name }}">@foreach ($parent->children as $sub)<option value="{{ $sub->id }}" @selected($transaction->category_id == $sub->id)>{{ $sub->name }}</option>@endforeach</optgroup>@else<option value="{{ $parent->id }}" @selected($transaction->category_id == $parent->id)>{{ $parent->name }}</option>@endif @endforeach</select></div>
                                    @if (isset($categorySuggestions[$transaction->id]))<button type="button" wire:click="updateCategory({{ $transaction->id }}, {{ $categorySuggestions[$transaction->id]['id'] }})" class="app-link mt-1 text-xs">Use previous category: {{ $categorySuggestions[$transaction->id]['name'] }}</button>@endif
                                </div>
                                <div class="flex basis-full gap-3 text-sm lg:flex-col lg:items-end lg:gap-1"><button type="button" wire:click="editTransaction({{ $transaction->id }})" class="app-link">Edit</button><button type="button" wire:click="confirmDeleteTransaction({{ $transaction->id }})" class="font-medium text-rose-700 dark:text-rose-400">Remove</button></div>
                            @endif
                        </div>
                    @endif
                </article>
            @empty
                <p class="app-empty m-4">No transactions in this view.</p>
            @endforelse
        </div>
    </div>

    <!-- Confirmation Modal -->
    <flux:modal name="confirm-import-commit" focusable class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Confirm Import</flux:heading>
                <flux:subheading>
                    This will create <strong>{{ $summary['new_transactions'] }}</strong> new transactions in your
                    account.
                    Categories will be assigned as selected. This action cannot be undone.
                </flux:subheading>
                @if ($summary['needs_attention'] > 0 || $summary['possible_duplicates'] > 0)
                    <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-100">
                        {{ $summary['needs_attention'] }} without a category · {{ $summary['possible_duplicates'] }} possible matching rows. Review these before importing.
                    </div>
                @endif
            </div>

            @error('commit')
            <div class="rounded-md bg-red-50 border border-red-200 p-4" data-action-error role="alert">
                <p class="text-sm text-red-800">{{ $message }}</p>
            </div>
            @enderror

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">Cancel</flux:button>
                </flux:modal.close>

                <flux:button
                    variant="primary"
                    wire:click="commitImport"
                    wire:loading.attr="disabled"
                >
                    <span wire:loading.remove wire:target="commitImport">Confirm Import</span>
                    <span wire:loading wire:target="commitImport">Importing...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Bulk Delete Confirmation Modal --}}
    <flux:modal
        name="confirm-bulk-delete"
        x-on:open-bulk-delete-modal.window="$flux.modal('confirm-bulk-delete').show()"
        x-on:close-bulk-delete-modal.window="$flux.modal('confirm-bulk-delete').close()"
        focusable
        class="max-w-lg"
    >
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Remove Transactions</flux:heading>
                <flux:subheading>
                    Are you sure you want to remove
                    <strong>{{ count($selectedTransactionIds) }}
                        transaction{{ count($selectedTransactionIds) !== 1 ? 's' : '' }}</strong>
                    from this import? This cannot be undone.
                </flux:subheading>
            </div>

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">Cancel</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" wire:click="bulkDeleteTransactions">
                    Delete {{ count($selectedTransactionIds) }}
                    transaction{{ count($selectedTransactionIds) !== 1 ? 's' : '' }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Single shared Remove Transaction Modal --}}
    @php $deletingTransaction = $deletingTransactionId ? $transactions->find($deletingTransactionId) : null; @endphp
    <flux:modal
        name="confirm-remove-transaction"
        x-on:open-delete-modal.window="$flux.modal('confirm-remove-transaction').show()"
        x-on:close-delete-modal.window="$flux.modal('confirm-remove-transaction').close()"
        focusable
        class="max-w-lg"
    >
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Remove Transaction</flux:heading>
                <flux:subheading>
                    Are you sure you want to remove this transaction from the import?
                    @if ($deletingTransaction)
                        <br><br>
                        <strong>{{ $deletingTransaction->description }}</strong> -
                        £{{ number_format(abs($deletingTransaction->amount), 2) }}
                        <br><br>
                    @endif
                    This will permanently remove the transaction from this import.
                </flux:subheading>
            </div>

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">Cancel</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" wire:click="deleteTransaction">
                    Remove Transaction
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
