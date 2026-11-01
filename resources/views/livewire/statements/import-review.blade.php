@php
    use App\Models\Transaction;
    use App\Support\Money;
    use Illuminate\Support\Str;

    $visibleSelectableIds = $transactions->where('is_duplicate', false)->pluck('id')->all();
    $allVisibleSelected = $visibleSelectableIds !== [] && array_diff($visibleSelectableIds, $selectedTransactionIds) === [];
    $isEditing = $editingTransactionId !== null;
@endphp
<div class="space-y-5">
    <x-page-header title="Review statement" description="Check the transactions below before adding them to your account." />

    @if (session('status'))
        <p class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800 dark:border-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-200" role="status" data-action-feedback>{{ session('status') }}</p>
    @endif

    <section class="app-card p-4 sm:p-6" aria-label="Statement summary">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <h2 class="break-words text-base font-semibold">{{ $import->original_filename }}</h2>
                <p class="mt-1 text-sm app-muted">
                    @if ($import->bankProfile){{ $import->bankProfile->name }} · @endif
                    {{ $import->statement_type === 'credit_card' ? 'Credit card statement' : 'Bank statement' }}
                </p>
            </div>
        </div>
        <dl class="mt-5 grid grid-cols-2 gap-x-5 gap-y-4 border-t border-app-border pt-4 sm:grid-cols-4">
            @foreach (['Total transactions' => $summary['total'], 'To import' => $summary['new_transactions'], 'Skipped duplicates' => $summary['duplicates']] as $label => $count)
                <div>
                    <dt class="text-sm app-muted">{{ $label }}</dt>
                    <dd class="mt-1 text-xl font-semibold tabular-nums">{{ $count }}</dd>
                </div>
            @endforeach
            <div>
                <dt class="text-sm app-muted">{{ $summary['total_amount'] >= 0 ? 'Net money in' : 'Net money out' }}</dt>
                <dd class="mt-1 text-xl font-semibold tabular-nums">{{ Money::format(abs($summary['total_amount'])) }}</dd>
            </div>
        </dl>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-x-4">
            <p class="text-xs app-muted">Net amount excludes skipped duplicates.</p>
            <button type="button" wire:click="backToImport" @disabled($isEditing) class="app-link inline-flex min-h-11 items-center text-sm">Back to imports</button>
        </div>
    </section>

    @if (count($selectedTransactionIds) > 0)
        <section class="app-card sticky top-3 z-20 border-emerald-200 p-4 dark:border-emerald-800" aria-label="Bulk actions">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <p class="text-sm font-semibold">{{ count($selectedTransactionIds) }} selected</p>
                <button type="button" wire:click="$set('selectedTransactionIds', [])" @disabled($isEditing) class="app-link inline-flex min-h-11 items-center text-sm">Clear selection</button>
                <div class="flex w-full flex-wrap items-center gap-3 sm:ml-auto sm:w-auto">
                    <label for="bulk-import-category" class="text-sm">Assign category</label>
                    <select id="bulk-import-category" wire:change="$event.target.value && $wire.bulkAssignCategory($event.target.value)" @disabled(!$bulkSelectionType || $isEditing) class="app-field min-w-0 flex-1 text-sm sm:w-48 sm:flex-none">
                        <option value="">Choose category</option>
                        @foreach ($categories->where('type', $bulkSelectionType) as $parent)
                            @if ($parent->children->isNotEmpty())
                                <optgroup label="{{ $parent->name }}">
                                    @foreach ($parent->children as $sub)<option value="{{ $sub->id }}">{{ $sub->name }}</option>@endforeach
                                </optgroup>
                            @else
                                <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                            @endif
                        @endforeach
                    </select>
                    <button type="button" wire:click="confirmBulkDelete" @disabled($isEditing) class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-medium text-rose-700 hover:bg-rose-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-600 disabled:opacity-50 dark:text-rose-400 dark:hover:bg-rose-950">Remove selected</button>
                </div>
            </div>
            @if (!$bulkSelectionType)<p class="mt-2 text-sm app-muted">Select money-in or money-out transactions separately to assign a category. You can still remove them together.</p>@endif
            @error('bulk_assign')<p class="mt-2 text-sm text-rose-700 dark:text-rose-400" data-action-error role="alert">{{ $message }}</p>@enderror
        </section>
    @endif

    <section class="app-card overflow-hidden" aria-labelledby="review-transactions-heading">
        <div class="px-4 pt-5 sm:px-6">
            <h2 id="review-transactions-heading" class="text-base font-semibold">Review transactions</h2>
            <div class="mt-4 flex flex-wrap gap-2" role="group" aria-label="Filter imported transactions">
                @foreach (['all' => 'All', 'needs_attention' => 'Needs a category', 'ready' => 'Categorised', 'possible' => 'Possible matches', 'duplicates' => 'Skipped duplicates'] as $filterValue => $filterLabel)
                    <button type="button" wire:click="$set('viewFilter', '{{ $filterValue }}')" @disabled($isEditing) data-review-filter="{{ $filterValue }}" aria-pressed="{{ $viewFilter === $filterValue ? 'true' : 'false' }}" class="inline-flex min-h-11 items-center gap-1.5 rounded-full px-3 py-2 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 disabled:cursor-not-allowed disabled:opacity-50 {{ $viewFilter === $filterValue ? 'bg-emerald-600 text-white hover:bg-emerald-700 dark:bg-emerald-700 dark:hover:bg-emerald-600' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700' }}">
                        {{ $filterLabel }} <span class="tabular-nums">{{ match ($filterValue) { 'all' => $summary['total'], 'needs_attention' => $summary['needs_attention'], 'ready' => $summary['new_transactions'] - $summary['needs_attention'], 'possible' => $summary['possible_duplicates'], default => $summary['duplicates'] } }}</span>
                    </button>
                @endforeach
            </div>
            <p class="mt-3 text-sm app-muted">
                {{ match ($viewFilter) {
                    'needs_attention' => 'Categories are optional. You can assign them now or after importing.',
                    'ready' => 'These transactions have categories. Check any possible matches before importing.',
                    'possible' => 'These entries share a date, description and amount. They may be genuine. All remain included unless you remove any extra entries.',
                    'duplicates' => 'These entries match a transaction already recorded or uploaded. They will be skipped automatically.',
                    default => 'Skipped duplicates are excluded automatically. Possible matches stay included until you remove them.',
                } }}
            </p>
            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-b border-app-border pb-3">
                <div class="text-xs app-muted">
                    <p>Showing {{ $transactions->count() }} of {{ $summary['total'] }} transactions</p>
                    <p class="mt-1">Checkboxes are for bulk actions only.</p>
                </div>
                @if ($visibleSelectableIds !== [])
                    <button type="button" wire:click="toggleVisibleSelection({{ json_encode($visibleSelectableIds) }})" @disabled($isEditing) class="app-link inline-flex min-h-11 items-center text-sm">{{ $allVisibleSelected ? 'Deselect visible' : 'Select visible' }}</button>
                @endif
            </div>
            @error('categoryId')<p class="my-3 text-sm text-rose-700 dark:text-rose-400" data-action-error role="alert">{{ $message }}</p>@enderror
            @if ($isEditing)<p class="my-3 text-sm font-medium text-amber-800 dark:text-amber-300" role="status">Save or cancel your changes before continuing.</p>@endif
        </div>

        <div aria-hidden="true" class="hidden grid-cols-[2.5rem_minmax(0,1fr)_7rem_minmax(10rem,13rem)_7rem] items-center gap-4 border-b border-app-border px-6 py-3 text-xs font-medium app-muted xl:grid">
            <span><span class="sr-only">Select</span></span><span>Transaction</span><span class="text-right">Amount</span><span>Category</span><span class="text-right">Actions</span>
        </div>
        <ul class="divide-y divide-app-border">
            @forelse ($transactions as $transaction)
                @php $rowType = $transaction->amount < 0 ? Transaction::TYPE_EXPENSE : Transaction::TYPE_INCOME; @endphp
                <li wire:key="import-row-{{ $transaction->id }}" data-import-row="{{ $transaction->id }}" class="min-w-0 px-4 py-4 sm:px-6 {{ $transaction->is_duplicate ? 'bg-zinc-50 dark:bg-zinc-900/50' : '' }}">
                    @if ($editingTransactionId === $transaction->id)
                        <form wire:submit="updateTransaction" class="space-y-4" x-data x-init="$nextTick(() => $refs.description.focus())">
                            <h3 class="font-semibold">Edit transaction</h3>
                            <div>
                                <label for="edit-description-{{ $transaction->id }}" class="text-sm font-medium">Description</label>
                                <textarea id="edit-description-{{ $transaction->id }}" x-ref="description" wire:model="editForm.description" rows="2" class="app-field mt-1 w-full" aria-invalid="{{ $errors->has('editForm.description') ? 'true' : 'false' }}" @error('editForm.description') aria-describedby="edit-description-error" @enderror></textarea>
                                @error('editForm.description')<p id="edit-description-error" class="mt-1 text-sm text-rose-700 dark:text-rose-400" data-action-error role="alert">{{ $message }}</p>@enderror
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="edit-date-{{ $transaction->id }}" class="text-sm font-medium">Date</label>
                                    <span class="app-field app-date-field mt-1 w-full"><input id="edit-date-{{ $transaction->id }}" type="date" wire:model="editForm.date" aria-invalid="{{ $errors->has('editForm.date') ? 'true' : 'false' }}" @error('editForm.date') aria-describedby="edit-date-error" @enderror></span>
                                    @error('editForm.date')<p id="edit-date-error" class="mt-1 text-sm text-rose-700 dark:text-rose-400" data-action-error role="alert">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label for="edit-type-{{ $transaction->id }}" class="text-sm font-medium">Direction</label>
                                    <select id="edit-type-{{ $transaction->id }}" wire:model.live="editForm.type" class="app-field mt-1 w-full" aria-invalid="{{ $errors->has('editForm.type') ? 'true' : 'false' }}" @error('editForm.type') aria-describedby="edit-type-error" @enderror><option value="expense">Money out</option><option value="income">Money in</option></select>
                                    @error('editForm.type')<p id="edit-type-error" class="mt-1 text-sm text-rose-700 dark:text-rose-400" data-action-error role="alert">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label for="edit-amount-{{ $transaction->id }}" class="text-sm font-medium">Amount (£)</label>
                                    <input id="edit-amount-{{ $transaction->id }}" type="number" step="0.01" min="0.01" wire:model="editForm.amount" class="app-field mt-1 w-full" aria-invalid="{{ $errors->has('editForm.amount') ? 'true' : 'false' }}" @error('editForm.amount') aria-describedby="edit-amount-error" @enderror>
                                    @error('editForm.amount')<p id="edit-amount-error" class="mt-1 text-sm text-rose-700 dark:text-rose-400" data-action-error role="alert">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label for="edit-category-{{ $transaction->id }}" class="text-sm font-medium">Category <span class="font-normal app-muted">(optional)</span></label>
                                    <select id="edit-category-{{ $transaction->id }}" wire:model="editForm.category_id" class="app-field mt-1 w-full" aria-invalid="{{ $errors->has('editForm.category_id') ? 'true' : 'false' }}" @error('editForm.category_id') aria-describedby="edit-category-error" @enderror>
                                        <option value="">Uncategorised</option>
                                        @foreach ($categories->where('type', $editForm['type'] ?? '') as $parent)
                                            @if ($parent->children->isNotEmpty())
                                                <optgroup label="{{ $parent->name }}">@foreach ($parent->children as $sub)<option value="{{ $sub->id }}">{{ $sub->name }}</option>@endforeach</optgroup>
                                            @else
                                                <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                    @error('editForm.category_id')<p id="edit-category-error" class="mt-1 text-sm text-rose-700 dark:text-rose-400" data-action-error role="alert">{{ $message }}</p>@enderror
                                </div>
                            </div>
                            <div class="flex flex-wrap justify-end gap-2 border-t border-app-border pt-4">
                                <button type="button" wire:click="cancelEdit" class="app-button-secondary">Cancel</button>
                                <button type="submit" wire:loading.attr="disabled" class="app-button-primary">Save changes</button>
                            </div>
                        </form>
                    @else
                        <div class="grid grid-cols-[2.5rem_minmax(0,1fr)_auto] items-start gap-x-3 gap-y-3 xl:grid-cols-[2.5rem_minmax(0,1fr)_7rem_minmax(10rem,13rem)_7rem] xl:items-center xl:gap-4">
                            @if ($transaction->is_duplicate)
                                <span aria-hidden="true"></span>
                            @else
                                <label class="flex min-h-11 w-10 cursor-pointer items-center justify-center">
                                    <input type="checkbox" wire:model.live="selectedTransactionIds" value="{{ $transaction->id }}" @disabled($isEditing) aria-label="Select {{ $transaction->description }}" class="size-4 rounded border-zinc-300 text-emerald-600 accent-emerald-600 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-600 dark:border-zinc-600">
                                </label>
                            @endif
                            <div class="min-w-0">
                                <p class="break-words text-sm font-semibold">{{ $transaction->description }}</p>
                                <p class="mt-1 text-xs app-muted">{{ $transaction->date->format('j M Y') }}</p>
                                @if ($transaction->is_duplicate)
                                    <p class="mt-1 text-xs font-medium app-muted">Duplicate · skipped</p>
                                @elseif (in_array($transaction->id, $possibleDuplicateIds, true))
                                    <p class="mt-1 text-xs font-medium text-amber-800 dark:text-amber-300">Possible match in this file</p>
                                @endif
                            </div>
                            <div class="pt-0.5 text-right xl:pt-0">
                                <p class="whitespace-nowrap text-sm font-semibold tabular-nums {{ $rowType === Transaction::TYPE_INCOME ? 'text-emerald-700 dark:text-emerald-400' : '' }}">{{ $rowType === Transaction::TYPE_INCOME ? '+' : '−' }}{{ Money::format(abs($transaction->amount)) }}</p>
                                <p class="mt-1 whitespace-nowrap text-xs app-muted">{{ $rowType === Transaction::TYPE_INCOME ? 'Money in' : 'Money out' }}</p>
                            </div>
                            @if ($transaction->is_duplicate)
                                <p class="col-span-3 text-sm app-muted xl:col-span-2">Excluded from import</p>
                            @else
                                <div class="col-span-3 min-w-0 xl:col-span-1">
                                    <label for="category-{{ $transaction->id }}" class="mb-1 block text-xs app-muted xl:sr-only">Category (optional)</label>
                                    <select id="category-{{ $transaction->id }}" wire:change="updateCategory({{ $transaction->id }}, $event.target.value)" @disabled($isEditing) aria-label="Category for {{ $transaction->description }}" class="app-field w-full min-w-0 text-sm">
                                        <option value="" @selected($transaction->category_id === null)>Uncategorised</option>
                                        @foreach ($categories->where('type', $rowType) as $parent)
                                            @if ($parent->children->isNotEmpty())
                                                <optgroup label="{{ $parent->name }}">@foreach ($parent->children as $sub)<option value="{{ $sub->id }}" @selected($transaction->category_id == $sub->id)>{{ $sub->name }}</option>@endforeach</optgroup>
                                            @else
                                                <option value="{{ $parent->id }}" @selected($transaction->category_id == $parent->id)>{{ $parent->name }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                    @if (isset($categorySuggestions[$transaction->id]))
                                        <button type="button" wire:click="updateCategory({{ $transaction->id }}, {{ $categorySuggestions[$transaction->id]['id'] }})" @disabled($isEditing) class="app-link mt-1 inline-flex min-h-11 items-center text-left text-xs">Use previous category: {{ $categorySuggestions[$transaction->id]['name'] }}</button>
                                    @endif
                                </div>
                                <div class="col-span-3 flex justify-end gap-4 text-sm xl:col-span-1 xl:gap-3">
                                    <button type="button" wire:click="editTransaction({{ $transaction->id }})" @disabled($isEditing) class="app-link inline-flex min-h-11 items-center">Edit</button>
                                    <button type="button" wire:click="confirmDeleteTransaction({{ $transaction->id }})" @disabled($isEditing) class="inline-flex min-h-11 items-center font-medium text-rose-700 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-600 disabled:opacity-50 dark:text-rose-400">Remove</button>
                                </div>
                            @endif
                        </div>
                    @endif
                </li>
            @empty
                <li class="px-6 py-10 text-center">
                    <p class="font-medium">{{ $summary['total'] === 0 ? 'No transactions left to review' : 'No transactions in this view' }}</p>
                    <p class="mt-1 text-sm app-muted">{{ $summary['total'] === 0 ? 'Return to imports to upload another statement.' : 'Try another filter to see the rest of your statement.' }}</p>
                    @if ($viewFilter !== 'all')<button type="button" wire:click="$set('viewFilter', 'all')" class="app-link mt-3 inline-flex min-h-11 items-center text-sm">Show all transactions</button>@endif
                </li>
            @endforelse
        </ul>
    </section>

    <div class="app-card sticky bottom-3 z-10 flex flex-wrap items-center justify-between gap-3 bg-white p-3 dark:bg-zinc-900 sm:gap-4 sm:px-6 sm:py-4" data-import-footer>
        <div class="min-w-0 flex-1 text-sm">
            @if ($summary['new_transactions'] > 0)
                <p class="font-semibold"><span class="sm:hidden">{{ $summary['new_transactions'] }} to import</span><span class="hidden sm:inline">{{ $summary['new_transactions'] }} new transaction{{ $summary['new_transactions'] !== 1 ? 's' : '' }} will be imported.</span></p>
                <p class="mt-1 hidden app-muted sm:block">{{ $isEditing ? 'Save or cancel your changes to continue.' : 'All remaining new transactions are included, across every filter.' }}</p>
            @else
                <p class="font-semibold">No new transactions to import.</p>
                <p class="mt-1 app-muted">{{ $summary['duplicates'] > 0 ? 'All remaining entries are skipped duplicates.' : 'Return to imports to upload another statement.' }}</p>
            @endif
        </div>
        @if ($summary['new_transactions'] > 0)
            <flux:modal.trigger name="confirm-import-commit">
                <button type="button" @disabled($isEditing) class="app-button-primary" data-import-trigger>Import {{ $summary['new_transactions'] }} transaction{{ $summary['new_transactions'] !== 1 ? 's' : '' }}</button>
            </flux:modal.trigger>
        @endif
    </div>

    <flux:modal name="confirm-import-commit" focusable class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Import {{ $summary['new_transactions'] }} transaction{{ $summary['new_transactions'] !== 1 ? 's' : '' }}?</flux:heading>
                <flux:subheading class="mt-2">All remaining new transactions will be added to your account with their assigned categories. Your current filter and checkbox selection don’t change what gets imported.</flux:subheading>
                <ul class="mt-4 list-disc space-y-2 pl-5 text-sm">
                    @if ($summary['duplicates'] > 0)<li>{{ $summary['duplicates'] }} duplicate {{ Str::plural('entry', $summary['duplicates']) }} will be skipped.</li>@endif
                    @if ($summary['needs_attention'] > 0)<li>{{ $summary['needs_attention'] }} {{ Str::plural('transaction', $summary['needs_attention']) }} will remain uncategorised. You can categorise them later.</li>@endif
                    @if ($summary['possible_duplicates'] > 0)<li class="font-medium text-amber-800 dark:text-amber-300">{{ $summary['possible_duplicates'] }} possible matching {{ Str::plural('entry', $summary['possible_duplicates']) }} will be included. Go back and remove any extra entries before importing.</li>@endif
                </ul>
                <p class="mt-4 text-sm app-muted">After importing, you can edit or remove individual entries on the Transactions page.</p>
            </div>
            @error('commit')<p class="text-sm text-rose-700 dark:text-rose-400" data-action-error role="alert">{{ $message }}</p>@enderror
            <div class="flex flex-wrap justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Back to review</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="commitImport" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="commitImport">Confirm import</span>
                    <span wire:loading wire:target="commitImport">Importing…</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-bulk-delete" x-on:open-bulk-delete-modal.window="$flux.modal('confirm-bulk-delete').show()" x-on:close-bulk-delete-modal.window="$flux.modal('confirm-bulk-delete').close()" focusable class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Remove {{ count($selectedTransactionIds) }} {{ Str::plural('transaction', count($selectedTransactionIds)) }}?</flux:heading>
                <flux:subheading class="mt-2">These entries will be removed from this statement and won’t be imported. Transactions already in your account are unaffected. You can’t undo this removal.</flux:subheading>
            </div>
            <div class="flex flex-wrap justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancel</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="bulkDeleteTransactions" wire:loading.attr="disabled">Remove {{ count($selectedTransactionIds) }} {{ Str::plural('transaction', count($selectedTransactionIds)) }}</flux:button>
            </div>
        </div>
    </flux:modal>

    @php $deletingTransaction = $deletingTransactionId ? $transactions->find($deletingTransactionId) : null; @endphp
    <flux:modal name="confirm-remove-transaction" x-on:open-delete-modal.window="$flux.modal('confirm-remove-transaction').show()" x-on:close-delete-modal.window="$flux.modal('confirm-remove-transaction').close()" focusable class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Remove transaction?</flux:heading>
                @if ($deletingTransaction)
                    <p class="mt-3 break-words font-medium">{{ $deletingTransaction->description }}</p>
                    <p class="mt-1 text-sm app-muted">{{ $deletingTransaction->date->format('j M Y') }} · {{ $deletingTransaction->amount < 0 ? '−' : '+' }}{{ Money::format(abs($deletingTransaction->amount)) }}</p>
                @endif
                <flux:subheading class="mt-3">This entry will be removed from this statement and won’t be imported. Transactions already in your account are unaffected. You can’t undo this removal.</flux:subheading>
            </div>
            <div class="flex flex-wrap justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancel</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="deleteTransaction" wire:loading.attr="disabled">Remove transaction</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
