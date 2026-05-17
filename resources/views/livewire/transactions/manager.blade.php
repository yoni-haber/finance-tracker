<div class="space-y-4">
    {{-- Status message --}}
    @if (session()->has('status'))
        <div class="rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 dark:bg-emerald-900/20 dark:border-emerald-800">
            <p class="text-sm font-medium text-emerald-800 dark:text-emerald-300">{{ session('status') }}</p>
        </div>
    @endif

    {{-- Main ledger card --}}
    <div class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">

        {{-- Toolbar --}}
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Transactions</h2>
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <select wire:model.live="month"
                        class="h-6 rounded-md border-gray-300 py-1.5 text-sm dark:bg-zinc-800 dark:border-zinc-700">
                    @foreach (range(1, 12) as $m)
                        <option value="{{ $m }}">{{ now()->startOfYear()->month($m)->shortMonthName }}</option>
                    @endforeach
                </select>
                <input type="number" wire:model.live="year"
                       class="h-6 w-15 rounded-md border-gray-300 py-1.5 text-sm text-center dark:bg-zinc-800 dark:border-zinc-700" min="2000" max="2100"/>
                <select wire:model.live="filterParentCategory"
                        class="h-6 rounded-md border-gray-300 py-1.5 text-sm dark:bg-zinc-800 dark:border-zinc-700">
                    <option value="">All categories</option>
                    @foreach ($filterCategories as $parent)
                        <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                    @endforeach
                </select>
                @if ($filterSubCategories->isNotEmpty())
                    <select wire:model.live="filterSubCategory"
                            class="h-6 rounded-md border-gray-300 py-1.5 text-sm dark:bg-zinc-800 dark:border-zinc-700">
                        <option value="">All subcategories</option>
                        @foreach ($filterSubCategories as $sub)
                            <option value="{{ $sub->id }}">{{ $sub->name }}</option>
                        @endforeach
                    </select>
                @endif
                <select wire:model.live="filterType"
                        class="h-6 rounded-md border-gray-300 py-1.5 text-sm dark:bg-zinc-800 dark:border-zinc-700">
                    <option value="">All types</option>
                    <option value="{{ \App\Models\Transaction::TYPE_INCOME }}">Income</option>
                    <option value="{{ \App\Models\Transaction::TYPE_EXPENSE }}">Expense</option>
                </select>
                <button
                    wire:click="openModal"
                    class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-1"
                >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    New transaction
                </button>
            </div>
        </div>

        {{-- Ledger table --}}
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Date</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Category</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Type</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Amount</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Notes</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($transactions as $transaction)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <td class="px-3 py-2 whitespace-nowrap text-zinc-700 dark:text-zinc-300">{{ \Carbon\Carbon::parse($transaction->date)->format('j M Y') }}</td>
                            <td class="px-3 py-2 text-zinc-700 dark:text-zinc-300">{{ $transaction->category->name ?? '—' }}</td>
                            <td class="px-3 py-2">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $transaction->type === \App\Models\Transaction::TYPE_INCOME ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400' }}">
                                    {{ ucfirst($transaction->type) }}
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
                                        class="text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400">Edit</button>
                                <button
                                    type="button"
                                    wire:click="delete({{ $transaction->id }}, '{{ \Carbon\Carbon::parse($transaction->date)->toDateString() }}')"
                                    class="text-xs font-medium text-rose-600 hover:text-rose-800 dark:text-rose-400"
                                >Delete</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-sm text-zinc-400">
                                No transactions found for this period.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Transaction form modal --}}
    <flux:modal
        name="transaction-form"
        x-on:open-transaction-modal.window="$flux.modal('transaction-form').show()"
        x-on:close-transaction-modal.window="$flux.modal('transaction-form').close()"
        focusable
        class="max-w-2xl"
    >
        <div class="space-y-5">
            <flux:heading size="lg">{{ $transactionId ? 'Edit Transaction' : 'New Transaction' }}</flux:heading>

            <form wire:submit.prevent="save" class="space-y-4">
                {{-- Row 1: Amount + Type --}}
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-zinc-300">Amount (£)</label>
                        <input type="number" min="0" step="0.01" wire:model="amount"
                               class="mt-1.5 w-full rounded-md border border-gray-300 dark:bg-zinc-800 dark:border-zinc-700"/>
                        @error('amount') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-zinc-300">Type</label>
                        <select wire:model.live="type"
                                class="mt-1.5 w-full rounded-md border border-gray-300 dark:bg-zinc-800 dark:border-zinc-700">
                            <option value="{{ \App\Models\Transaction::TYPE_INCOME }}">Income</option>
                            <option value="{{ \App\Models\Transaction::TYPE_EXPENSE }}">Expense</option>
                        </select>
                        @error('type') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- Row 2: Date + Category --}}
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-zinc-300">Date</label>
                        <input type="date" wire:model="date"
                               class="mt-1.5 w-full rounded-md border border-gray-300 dark:bg-zinc-800 dark:border-zinc-700"/>
                        @error('date') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-zinc-300">Category</label>
                        <select wire:model="category_id"
                                class="mt-1.5 w-full rounded-md border border-gray-300 dark:bg-zinc-800 dark:border-zinc-700">
                            <option value="">Uncategorised</option>
                            @foreach ($formCategories as $parent)
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
                        @error('category_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- Description --}}
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-zinc-300">Description</label>
                    <textarea wire:model="description"
                              class="mt-1.5 w-full rounded-md border border-gray-300 dark:bg-zinc-800 dark:border-zinc-700"
                              rows="2"></textarea>
                    @error('description') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                {{-- Recurring toggle --}}
                <div class="space-y-3 rounded-md border border-zinc-200 p-3 dark:border-zinc-700">
                    <label class="flex cursor-pointer items-center gap-2.5">
                        <input type="checkbox" wire:model.live="is_recurring"
                               class="rounded border-gray-300 text-emerald-600 shadow-sm focus:ring-emerald-500"/>
                        <span class="text-sm font-medium text-gray-700 dark:text-zinc-300">Recurring transaction</span>
                    </label>

                    @if ($is_recurring)
                        <div class="grid gap-4 sm:grid-cols-2 pt-1">
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-zinc-300">Frequency</label>
                                <select wire:model="frequency"
                                        class="mt-1.5 w-full rounded-md border border-gray-300 dark:bg-zinc-800 dark:border-zinc-700">
                                    <option value="">Select frequency</option>
                                    <option value="weekly">Weekly</option>
                                    <option value="monthly">Monthly</option>
                                    <option value="yearly">Yearly</option>
                                </select>
                                @error('frequency') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-zinc-300">Recurring until <span class="normal-case font-normal text-zinc-400">(optional)</span></label>
                                <input type="date" wire:model="recurring_until"
                                       class="mt-1.5 w-full rounded-md border border-gray-300 dark:bg-zinc-800 dark:border-zinc-700"/>
                                @error('recurring_until') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Set an end date before creating a new recurring amount to keep historical totals intact.</p>
                    @endif
                </div>

                @error('save') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror

                {{-- Actions --}}
                <div class="flex items-center justify-end gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <flux:modal.close>
                        <button type="button" class="rounded-md border border-zinc-300 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800">
                            Cancel
                        </button>
                    </flux:modal.close>
                    <button type="submit" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                        Save transaction
                    </button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>

