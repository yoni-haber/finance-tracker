<div class="space-y-4">
    {{-- Status messages --}}
    @if (session()->has('status'))
        <div class="rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 dark:bg-emerald-900/20 dark:border-emerald-800">
            <p class="text-sm font-medium text-emerald-800 dark:text-emerald-300">{{ session('status') }}</p>
        </div>
    @endif
    @if (session()->has('copy_status'))
        <div class="rounded-md bg-blue-50 border border-blue-200 px-4 py-3 dark:bg-blue-900/20 dark:border-blue-800">
            <p class="text-sm font-medium text-blue-800 dark:text-blue-300">{{ session('copy_status') }}</p>
        </div>
    @endif

    {{-- Main budgets card --}}
    <div class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">

        {{-- Toolbar --}}
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Budgets</h2>

            <div class="flex flex-wrap items-center gap-2 text-sm">
                <select wire:model.live="filterMonth" class="h-8 rounded-md border-gray-300 py-1.5 text-sm dark:bg-zinc-800 dark:border-zinc-700">
                    @foreach (range(1, 12) as $m)
                        <option value="{{ $m }}">{{ now()->startOfYear()->month($m)->shortMonthName }}</option>
                    @endforeach
                </select>
                <input type="number" wire:model.live="filterYear"
                       class="h-8 w-15 rounded-md border-gray-300 py-1.5 text-sm text-center dark:bg-zinc-800 dark:border-zinc-700" min="2000" max="2100"/>
                <select wire:model.live="filterCategory"
                        class="h-8 rounded-md border-gray-300 py-1.5 text-sm dark:bg-zinc-800 dark:border-zinc-700">
                    <option value="">All categories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
                <button
                    type="button"
                    wire:click="copyFromPreviousMonth"
                    wire:loading.attr="disabled"
                    wire:target="copyFromPreviousMonth"
                    class="rounded-md border border-zinc-300 px-3 py-1.5 text-sm text-zinc-700 hover:bg-zinc-50 disabled:opacity-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800"
                >
                    <span wire:loading.remove wire:target="copyFromPreviousMonth">Copy from previous month</span>
                    <span wire:loading wire:target="copyFromPreviousMonth">Copying…</span>
                </button>
                <button
                    wire:click="openModal"
                    class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-1"
                >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    New budget
                </button>
            </div>
        </div>

        {{-- Budgets table --}}
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Category</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Month</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Year</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Amount</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($budgets as $budget)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <td class="px-3 py-2 font-medium text-zinc-900 dark:text-white">{{ $budget->category->name }}</td>
                            <td class="px-3 py-2 text-zinc-700 dark:text-zinc-300">{{ now()->startOfYear()->month($budget->month)->format('F') }}</td>
                            <td class="px-3 py-2 text-zinc-700 dark:text-zinc-300">{{ $budget->year }}</td>
                            <td class="px-3 py-2 text-right font-medium tabular-nums text-zinc-900 dark:text-white">£{{ number_format($budget->amount, 2) }}</td>
                            <td class="px-3 py-2 text-right whitespace-nowrap space-x-3">
                                <button type="button" wire:click="edit({{ $budget->id }})" class="text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400">Edit</button>
                                <button type="button" wire:click="delete({{ $budget->id }})" class="text-xs font-medium text-rose-600 hover:text-rose-800 dark:text-rose-400">Delete</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-zinc-400">No budgets defined for this period.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Budget form modal --}}
    <flux:modal
        name="budget-form"
        x-on:open-budget-modal.window="$flux.modal('budget-form').show()"
        x-on:close-budget-modal.window="$flux.modal('budget-form').close()"
        focusable
        class="max-w-xl"
    >
        <div class="space-y-5">
            <flux:heading size="lg">{{ $budgetId ? 'Edit Budget' : 'New Budget' }}</flux:heading>

            <form wire:submit.prevent="save" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-zinc-300">Category</label>
                    <select wire:model="category_id"
                            class="mt-1.5 w-full rounded-md border border-gray-300 dark:bg-zinc-800 dark:border-zinc-700">
                        <option value="">Select category</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                    @error('category_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-zinc-300">Month</label>
                        <select wire:model="month"
                                class="mt-1.5 w-full rounded-md border border-gray-300 dark:bg-zinc-800 dark:border-zinc-700">
                            @foreach (range(1, 12) as $m)
                                <option value="{{ $m }}">{{ now()->startOfYear()->month($m)->format('F') }}</option>
                            @endforeach
                        </select>
                        @error('month') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-zinc-300">Year</label>
                        <input type="number" wire:model="year" min="2000" max="2100"
                               class="mt-1.5 w-full rounded-md border border-gray-300 dark:bg-zinc-800 dark:border-zinc-700"/>
                        @error('year') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-zinc-300">Amount (£)</label>
                    <input type="number" min="0" step="0.01" wire:model="amount"
                           class="mt-1.5 w-full rounded-md border border-gray-300 dark:bg-zinc-800 dark:border-zinc-700"/>
                    @error('amount') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                @error('save') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror

                <div class="flex items-center justify-end gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <flux:modal.close>
                        <button type="button" class="rounded-md border border-zinc-300 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800">
                            Cancel
                        </button>
                    </flux:modal.close>
                    <button type="submit" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                        Save budget
                    </button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>

