<div class="space-y-6">
    @if (session('status'))
        <div class="rounded-md bg-emerald-50 border border-emerald-200 p-4">
            <div class="flex">
                <div class="ml-3">
                    <p class="text-sm font-medium text-emerald-800">{{ session('status') }}</p>
                </div>
            </div>
        </div>
    @endif

    <!-- Import Summary -->
    <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
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
                class="inline-flex items-center gap-1 rounded-md border border-zinc-300 px-3 py-1.5 text-sm text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800"
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
                <div class="text-2xl font-bold tabular-nums text-emerald-700 dark:text-emerald-400">{{ $summary['new_transactions'] }}</div>
                <div class="mt-0.5 text-xs text-emerald-600 dark:text-emerald-500">New</div>
            </div>
            <div class="rounded-lg bg-amber-50 px-4 py-3 dark:bg-amber-900/20">
                <div class="text-2xl font-bold tabular-nums text-amber-700 dark:text-amber-400">{{ $summary['duplicates'] }}</div>
                <div class="mt-0.5 text-xs text-amber-600 dark:text-amber-500">Duplicates (skipped)</div>
            </div>
            <div class="rounded-lg bg-zinc-50 px-4 py-3 dark:bg-zinc-800">
                <div class="text-2xl font-bold tabular-nums {{ $summary['total_amount'] >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400' }}">
                    £{{ number_format(abs($summary['total_amount']), 2) }}
                </div>
                <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $summary['total_amount'] >= 0 ? 'Net credit' : 'Net debit' }}</div>
            </div>
        </div>

        @if ($summary['new_transactions'] > 0)
            <div class="mt-5 flex items-center justify-between border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                    Ready to import <strong class="text-zinc-900 dark:text-white">{{ $summary['new_transactions'] }}</strong> new transaction{{ $summary['new_transactions'] !== 1 ? 's' : '' }}.
                </p>
                <flux:modal.trigger name="confirm-import-commit">
                    <button class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 focus:ring-2 focus:ring-emerald-500 focus:ring-offset-1">
                        Import {{ $summary['new_transactions'] }} transaction{{ $summary['new_transactions'] !== 1 ? 's' : '' }}
                    </button>
                </flux:modal.trigger>
            </div>
        @else
            <p class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">No new transactions to import.</p>
        @endif
    </div>

    <!-- Transaction List -->
    <div class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
            <h3 class="text-base font-semibold text-zinc-900 dark:text-white">Transaction Details</h3>
            <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">Review, edit, categorize, or remove transactions before importing.</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200 dark:bg-zinc-900 dark:divide-gray-700">
                    @forelse ($transactions as $transaction)
                        <tr class="{{ $transaction->is_duplicate ? 'opacity-60 bg-amber-50/50 dark:bg-amber-900/10' : '' }}">
                            @if ($editingTransactionId === $transaction->id)
                                <!-- Edit Mode -->
                                <td class="px-6 py-4">
                                    <input
                                        type="date"
                                        wire:model="editForm.date"
                                        class="w-full text-sm border-gray-300 rounded-md dark:bg-zinc-800 dark:border-zinc-700"
                                    >
                                    @error('editForm.date')
                                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                                    @enderror
                                </td>
                                <td class="px-6 py-4">
                                    <textarea
                                        wire:model="editForm.description"
                                        class="w-full text-sm border-gray-300 rounded-md dark:bg-zinc-800 dark:border-zinc-700"
                                        rows="2"
                                    ></textarea>
                                    @error('editForm.description')
                                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                                    @enderror
                                </td>
                                <td class="px-6 py-4">
                                    <select
                                        wire:model="editForm.type"
                                        class="w-full text-sm border-gray-300 rounded-md dark:bg-zinc-800 dark:border-zinc-700"
                                    >
                                        <option value="{{ \App\Models\Transaction::TYPE_EXPENSE }}">Expense</option>
                                        <option value="{{ \App\Models\Transaction::TYPE_INCOME }}">Income</option>
                                    </select>
                                    @error('editForm.type')
                                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                                    @enderror
                                </td>
                                <td class="px-6 py-4">
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        wire:model="editForm.amount"
                                        class="w-full text-sm border-gray-300 rounded-md text-right dark:bg-zinc-800 dark:border-zinc-700"
                                    >
                                    @error('editForm.amount')
                                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                                    @enderror
                                </td>
                                <td class="px-6 py-4">
                                    <select
                                        wire:model="editForm.category_id"
                                        class="w-full text-sm border-gray-300 rounded-md dark:bg-zinc-800 dark:border-zinc-700"
                                    >
                                        <option value="">Uncategorised</option>
                                        @foreach ($categories as $category)
                                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800">
                                        Editing
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex gap-2 justify-center">
                                        <button
                                            wire:click="updateTransaction"
                                            class="text-emerald-600 hover:text-emerald-800 text-xs font-medium"
                                        >
                                            Save
                                        </button>
                                        <button
                                            wire:click="cancelEdit"
                                            class="text-gray-600 hover:text-gray-800 text-xs font-medium"
                                        >
                                            Cancel
                                        </button>
                                    </div>
                                </td>
                            @else
                                <!-- View Mode -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                    {{ $transaction->date->format('j M Y') }}
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                    {{ $transaction->description }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    @if (!$transaction->is_duplicate)
                                        <select
                                            wire:change="updateType({{ $transaction->id }}, $event.target.value)"
                                            class="text-sm border-gray-300 rounded-md dark:bg-zinc-800 dark:border-zinc-700"
                                        >
                                            @php
                                                // Determine correct type based on statement type and amount sign
                                                if ($import->statement_type === 'credit_card') {
                                                    // Credit Card: Negative = Expense (purchases), Positive = Income (payments/refunds)
                                                    $currentType = $transaction->amount < 0 ? \App\Models\Transaction::TYPE_EXPENSE : \App\Models\Transaction::TYPE_INCOME;
                                                } else {
                                                    // Bank: Positive = Income (deposits), Negative = Expense (withdrawals)
                                                    $currentType = $transaction->amount >= 0 ? \App\Models\Transaction::TYPE_INCOME : \App\Models\Transaction::TYPE_EXPENSE;
                                                }
                                            @endphp
                                            <option value="{{ \App\Models\Transaction::TYPE_EXPENSE }}" {{ $currentType === \App\Models\Transaction::TYPE_EXPENSE ? 'selected' : '' }}>Expense</option>
                                            <option value="{{ \App\Models\Transaction::TYPE_INCOME }}" {{ $currentType === \App\Models\Transaction::TYPE_INCOME ? 'selected' : '' }}>Income</option>
                                        </select>
                                    @else
                                        <span class="text-gray-400 text-sm">
                                            @php
                                                if ($import->statement_type === 'credit_card') {
                                                    $displayType = $transaction->amount < 0 ? 'Expense' : 'Income';
                                                } else {
                                                    $displayType = $transaction->amount >= 0 ? 'Income' : 'Expense';
                                                }
                                            @endphp
                                            {{ $displayType }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                                    £{{ number_format(abs($transaction->amount), 2) }}
                                </td>
                                <td class="px-6 py-4">
                                    @if (!$transaction->is_duplicate)
                                        <select
                                            wire:change="updateCategory({{ $transaction->id }}, $event.target.value)"
                                            class="text-sm border-gray-300 rounded-md dark:bg-zinc-800 dark:border-zinc-700"
                                        >
                                            <option value="">Uncategorised</option>
                                            @foreach ($categories as $category)
                                                <option value="{{ $category->id }}" @selected($transaction->category_id == $category->id)>
                                                    {{ $category->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    @else
                                        <span class="text-gray-400 text-sm">N/A</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    @if ($transaction->is_duplicate)
                                        <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800">
                                            Duplicate
                                        </span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-800">
                                            New
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    @if (!$transaction->is_duplicate)
                                        <div class="flex gap-2 justify-center">
                                            <button
                                                wire:click="editTransaction({{ $transaction->id }})"
                                                class="text-blue-600 hover:text-blue-800 text-xs font-medium"
                                            >
                                                Edit
                                            </button>
                                            <button
                                                wire:click="confirmDeleteTransaction({{ $transaction->id }})"
                                                class="text-red-600 hover:text-red-800 text-xs font-medium"
                                            >
                                                Delete
                                            </button>
                                        </div>
                                    @else
                                        <span class="text-gray-400 text-xs">N/A</span>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-4 text-center text-gray-500">
                                No transactions found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <flux:modal name="confirm-import-commit" focusable class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Confirm Import</flux:heading>
                <flux:subheading>
                    This will create <strong>{{ $summary['new_transactions'] }}</strong> new transactions in your account.
                    Categories will be assigned as selected. This action cannot be undone.
                </flux:subheading>
            </div>

            @error('commit')
                <div class="rounded-md bg-red-50 border border-red-200 p-4">
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
                        <strong>{{ $deletingTransaction->description }}</strong> - £{{ number_format(abs($deletingTransaction->amount), 2) }}
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
