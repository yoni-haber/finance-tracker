<div class="space-y-4">
    {{-- Status message --}}
    @if (session()->has('status'))
        <div class="rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 dark:bg-emerald-900/20 dark:border-emerald-800">
            <p class="text-sm font-medium text-emerald-800 dark:text-emerald-300">{{ session('status') }}</p>
        </div>
    @endif

    {{-- History card --}}
    <div class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">

        {{-- Toolbar --}}
        <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Net Worth</h2>
            <button
                wire:click="openModal"
                class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-1"
            >
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New snapshot
            </button>
        </div>

        {{-- History table --}}
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Date</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Assets</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Liabilities</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Net Worth</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($entries as $entry)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50" x-data="{ expanded: false }">
                            <td class="px-3 py-2 whitespace-nowrap text-zinc-700 dark:text-zinc-300">{{ $entry->date->format('M d, Y') }}</td>
                            <td class="px-3 py-2">
                                <div x-show="!expanded" class="font-medium tabular-nums text-emerald-700 dark:text-emerald-400">£{{ number_format($entry->assets, 2) }}</div>
                                <div x-show="expanded" class="space-y-0.5">
                                    @foreach ($entry->lineItems->where('type', 'asset') as $item)
                                        <div class="flex items-center justify-between gap-3 text-xs">
                                            <span class="text-zinc-600 dark:text-zinc-400">{{ $item->category }}</span>
                                            <span class="tabular-nums font-medium text-zinc-800 dark:text-zinc-200">£{{ number_format($item->amount, 2) }}</span>
                                        </div>
                                    @endforeach
                                    <div class="border-t border-zinc-200 pt-0.5 text-xs font-semibold tabular-nums text-emerald-700 dark:text-zinc-400 dark:border-zinc-700">£{{ number_format($entry->assets, 2) }}</div>
                                </div>
                            </td>
                            <td class="px-3 py-2">
                                <div x-show="!expanded" class="font-medium tabular-nums text-rose-700 dark:text-rose-400">£{{ number_format($entry->liabilities, 2) }}</div>
                                <div x-show="expanded" class="space-y-0.5">
                                    @foreach ($entry->lineItems->where('type', 'liability') as $item)
                                        <div class="flex items-center justify-between gap-3 text-xs">
                                            <span class="text-zinc-600 dark:text-zinc-400">{{ $item->category }}</span>
                                            <span class="tabular-nums font-medium text-zinc-800 dark:text-zinc-200">£{{ number_format($item->amount, 2) }}</span>
                                        </div>
                                    @endforeach
                                    <div class="border-t border-zinc-200 pt-0.5 text-xs font-semibold tabular-nums text-rose-700 dark:text-zinc-400 dark:border-zinc-700">£{{ number_format($entry->liabilities, 2) }}</div>
                                </div>
                            </td>
                            <td class="px-3 py-2 font-semibold tabular-nums {{ $entry->net_worth >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400' }}">£{{ number_format($entry->net_worth, 2) }}</td>
                            <td class="px-3 py-2 text-right whitespace-nowrap space-x-3">
                                <button type="button" x-on:click="expanded = !expanded" class="text-xs font-medium text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200" x-text="expanded ? 'Collapse' : 'Expand'"></button>
                                <button type="button" wire:click="edit({{ $entry->id }})" class="text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400">Edit</button>
                                <button type="button" wire:click="delete({{ $entry->id }})" class="text-xs font-medium text-rose-600 hover:text-rose-800 dark:text-rose-400">Delete</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-zinc-400">
                                No entries yet. Start by creating a snapshot of your current assets and liabilities.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Net worth snapshot modal --}}
    <flux:modal
        name="networth-form"
        x-on:open-networth-modal.window="$flux.modal('networth-form').show()"
        x-on:close-networth-modal.window="$flux.modal('networth-form').close()"
        focusable
        class="max-w-4xl"
    >
        <div class="space-y-5">
            <div class="flex items-start justify-between gap-4">
                <flux:heading size="lg">{{ $entryId ? 'Edit Snapshot' : 'New Snapshot' }}</flux:heading>
                <div class="text-centre px-7 py-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Net Worth</p>
                    <div class="mt-1 rounded-lg px-3 py-1.5 text-lg font-bold {{ $this->calculatedNetWorthStyle }}">
                        £{{ $this->calculatedNetWorth }}
                    </div>
                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                        Assets £{{ number_format(array_sum(array_column($assetLines, 'amount')), 2) }}
                        &nbsp;·&nbsp; Liabilities £{{ number_format(array_sum(array_column($liabilityLines, 'amount')), 2) }}
                    </p>
                </div>
            </div>

            <form wire:submit.prevent="save" class="space-y-5">
                {{-- Date --}}
                <div class="max-w-xs">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-zinc-300">Date</label>
                    <input type="date" wire:model="date"
                           class="mt-1.5 w-full rounded-md border border-gray-300 dark:bg-zinc-800 dark:border-zinc-700"/>
                    @error('date') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                {{-- Assets & Liabilities side by side --}}
                <div class="grid gap-4 lg:grid-cols-2">
                    {{-- Assets --}}
                    <div class="rounded-lg border border-emerald-200 dark:border-emerald-800/50">
                        <div class="border-b border-emerald-200 bg-emerald-50 px-3 py-2 dark:border-emerald-800/50 dark:bg-emerald-900/20">
                            <h4 class="text-sm font-semibold text-emerald-800 dark:text-emerald-300">Assets</h4>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="border-b border-zinc-100 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                                    <tr>
                                        <th class="px-3 py-1.5 text-left text-xs font-semibold text-zinc-500">Category</th>
                                        <th class="px-3 py-1.5 text-left text-xs font-semibold text-zinc-500">Amount</th>
                                        <th class="px-3 py-1.5 text-right text-xs font-semibold text-zinc-500">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                                    @forelse ($assetLines as $index => $asset)
                                        <tr>
                                            <td class="px-3 py-2 align-top">
                                                @if ($editingAssetIndex === $index)
                                                    <input type="text" wire:model="assetLines.{{ $index }}.category"
                                                           class="w-full rounded-md border-gray-300 text-sm dark:bg-zinc-900 dark:border-zinc-700"/>
                                                    @error('assetLines.' . $index . '.category') <p class="mt-0.5 text-xs text-rose-600">{{ $message }}</p> @enderror
                                                @else
                                                    <span class="text-zinc-800 dark:text-zinc-100">{{ $asset['category'] }}</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 align-top">
                                                @if ($editingAssetIndex === $index)
                                                    <input type="number" min="0" step="0.01" wire:model="assetLines.{{ $index }}.amount"
                                                           class="w-full rounded-md border-gray-300 text-sm dark:bg-zinc-900 dark:border-zinc-700"/>
                                                    @error('assetLines.' . $index . '.amount') <p class="mt-0.5 text-xs text-rose-600">{{ $message }}</p> @enderror
                                                @else
                                                    <span class="font-medium tabular-nums">£{{ number_format((float) $asset['amount'], 2) }}</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 text-right space-x-2">
                                                @if ($editingAssetIndex === $index)
                                                    <button type="button" wire:click="saveAssetLine({{ $index }})" class="text-xs font-medium text-emerald-600">Save</button>
                                                @else
                                                    <button type="button" wire:click="editAssetLine({{ $index }})" class="text-xs font-medium text-blue-600">Edit</button>
                                                @endif
                                                <button type="button" wire:click="removeAssetLine({{ $index }})" class="text-xs font-medium text-rose-600">Delete</button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="px-3 py-3 text-center text-xs text-zinc-400">No assets added yet.</td>
                                        </tr>
                                    @endforelse
                                    {{-- Inline add row --}}
                                    <tr class="bg-zinc-50 dark:bg-zinc-800/50">
                                        <td class="px-3 py-2">
                                            <input type="text" wire:model="newAssetCategory" placeholder="e.g. Cash ISA"
                                                   class="w-full rounded-md border-gray-300 text-sm dark:bg-zinc-900 dark:border-zinc-700"/>
                                            @error('newAssetCategory') <p class="mt-0.5 text-xs text-rose-600">{{ $message }}</p> @enderror
                                        </td>
                                        <td class="px-3 py-2">
                                            <input type="number" min="0" step="0.01" wire:model="newAssetAmount"
                                                   class="w-full rounded-md border-gray-300 text-sm dark:bg-zinc-900 dark:border-zinc-700"/>
                                            @error('newAssetAmount') <p class="mt-0.5 text-xs text-rose-600">{{ $message }}</p> @enderror
                                        </td>
                                        <td class="px-3 py-2 text-right">
                                            <button type="button" wire:click="addAssetLine"
                                                    class="rounded-md bg-emerald-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">Add</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {{-- Liabilities --}}
                    <div class="rounded-lg border border-rose-200 dark:border-rose-800/50">
                        <div class="border-b border-rose-200 bg-rose-50 px-3 py-2 dark:border-rose-800/50 dark:bg-rose-900/20">
                            <h4 class="text-sm font-semibold text-rose-800 dark:text-rose-300">Liabilities</h4>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="border-b border-zinc-100 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                                    <tr>
                                        <th class="px-3 py-1.5 text-left text-xs font-semibold text-zinc-500">Category</th>
                                        <th class="px-3 py-1.5 text-left text-xs font-semibold text-zinc-500">Amount</th>
                                        <th class="px-3 py-1.5 text-right text-xs font-semibold text-zinc-500">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                                    @forelse ($liabilityLines as $index => $liability)
                                        <tr>
                                            <td class="px-3 py-2 align-top">
                                                @if ($editingLiabilityIndex === $index)
                                                    <input type="text" wire:model="liabilityLines.{{ $index }}.category"
                                                           class="w-full rounded-md border-gray-300 text-sm dark:bg-zinc-900 dark:border-zinc-700"/>
                                                    @error('liabilityLines.' . $index . '.category') <p class="mt-0.5 text-xs text-rose-600">{{ $message }}</p> @enderror
                                                @else
                                                    <span class="text-zinc-800 dark:text-zinc-100">{{ $liability['category'] }}</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 align-top">
                                                @if ($editingLiabilityIndex === $index)
                                                    <input type="number" min="0" step="0.01" wire:model="liabilityLines.{{ $index }}.amount"
                                                           class="w-full rounded-md border-gray-300 text-sm dark:bg-zinc-900 dark:border-zinc-700"/>
                                                    @error('liabilityLines.' . $index . '.amount') <p class="mt-0.5 text-xs text-rose-600">{{ $message }}</p> @enderror
                                                @else
                                                    <span class="font-medium tabular-nums">£{{ number_format((float) $liability['amount'], 2) }}</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 text-right space-x-2">
                                                @if ($editingLiabilityIndex === $index)
                                                    <button type="button" wire:click="saveLiabilityLine({{ $index }})" class="text-xs font-medium text-emerald-600">Save</button>
                                                @else
                                                    <button type="button" wire:click="editLiabilityLine({{ $index }})" class="text-xs font-medium text-blue-600">Edit</button>
                                                @endif
                                                <button type="button" wire:click="removeLiabilityLine({{ $index }})" class="text-xs font-medium text-rose-600">Delete</button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="px-3 py-3 text-center text-xs text-zinc-400">No liabilities added yet.</td>
                                        </tr>
                                    @endforelse
                                    {{-- Inline add row --}}
                                    <tr class="bg-zinc-50 dark:bg-zinc-800/50">
                                        <td class="px-3 py-2">
                                            <input type="text" wire:model="newLiabilityCategory" placeholder="e.g. Mortgage"
                                                   class="w-full rounded-md border-gray-300 text-sm dark:bg-zinc-900 dark:border-zinc-700"/>
                                            @error('newLiabilityCategory') <p class="mt-0.5 text-xs text-rose-600">{{ $message }}</p> @enderror
                                        </td>
                                        <td class="px-3 py-2">
                                            <input type="number" min="0" step="0.01" wire:model="newLiabilityAmount"
                                                   class="w-full rounded-md border-gray-300 text-sm dark:bg-zinc-900 dark:border-zinc-700"/>
                                            @error('newLiabilityAmount') <p class="mt-0.5 text-xs text-rose-600">{{ $message }}</p> @enderror
                                        </td>
                                        <td class="px-3 py-2 text-right">
                                            <button type="button" wire:click="addLiabilityLine"
                                                    class="rounded-md bg-rose-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-rose-700">Add</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                @error('save') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror

                <div class="flex items-center justify-end gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <flux:modal.close>
                        <button type="button" class="rounded-md border border-zinc-300 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800">
                            Cancel
                        </button>
                    </flux:modal.close>
                    <button type="submit" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                        Save snapshot
                    </button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>

