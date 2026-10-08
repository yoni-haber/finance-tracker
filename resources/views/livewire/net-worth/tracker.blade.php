<div class="space-y-5">
    <x-page-header eyebrow="Your position" title="Net worth" description="Record a snapshot to see how your assets and liabilities change over time.">
        <button type="button" wire:click="openModal" class="app-button-primary">+ New snapshot</button>
    </x-page-header>
    <div class="grid gap-3 sm:grid-cols-3">
        <div class="app-card p-4"><p class="app-eyebrow">Latest net worth</p><p class="mt-2 text-2xl font-semibold tabular-nums {{ $latestEntry && \App\Support\Money::normalize($latestEntry->net_worth) < 0 ? 'text-finance-negative' : ($latestEntry ? 'text-finance-positive' : 'app-muted') }}">{{ $latestEntry ? \App\Support\Money::format($latestEntry->net_worth) : '—' }}</p><p class="mt-1 text-xs app-muted">{{ $latestEntry ? 'As of ' . $latestEntry->date->format('j M Y') : 'No snapshot through today' }}</p></div>
        <div class="app-card p-4"><p class="app-eyebrow">Assets</p><p class="mt-2 text-2xl font-semibold tabular-nums {{ $latestEntry ? 'text-finance-positive' : 'app-muted' }}">{{ $latestEntry ? \App\Support\Money::format($latestEntry->assets) : '—' }}</p><p class="mt-1 text-xs app-muted">{{ $latestEntry ? 'As of ' . $latestEntry->date->format('j M Y') : 'No snapshot through today' }}</p></div>
        <div class="app-card p-4"><p class="app-eyebrow">Liabilities</p><p class="mt-2 text-2xl font-semibold tabular-nums {{ $latestEntry ? 'text-finance-negative' : 'app-muted' }}">{{ $latestEntry ? \App\Support\Money::format($latestEntry->liabilities) : '—' }}</p><p class="mt-1 text-xs app-muted">{{ $latestEntry ? 'As of ' . $latestEntry->date->format('j M Y') : 'No snapshot through today' }}</p></div>
    </div>
    {{-- Status message --}}
    @if (session()->has('status'))
        <div class="rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 dark:bg-emerald-900/20 dark:border-emerald-800" data-action-feedback role="status">
            <p class="text-sm font-medium text-emerald-800 dark:text-emerald-300">{{ session('status') }}</p>
        </div>
    @endif

    {{-- History card --}}
    <div class="app-card overflow-hidden">

        <div class="border-b border-app-border px-4 py-4 sm:px-5"><h2 class="font-semibold">Snapshot history</h2><p class="mt-1 text-sm app-muted">{{ $latestEntry ? 'Latest snapshot: ' . $latestEntry->date->format('j M Y') : 'Your first snapshot will appear here.' }}</p></div>

        {{-- History table --}}
        <div class="hidden overflow-x-auto md:block">
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
                                <div x-show="!expanded" class="font-medium tabular-nums text-emerald-700 dark:text-emerald-400">{{ \App\Support\Money::format($entry->assets) }}</div>
                                <div x-show="expanded" class="space-y-0.5">
                                    @foreach ($entry->lineItems->where('type', 'asset') as $item)
                                        <div class="flex items-center justify-between gap-3 text-xs">
                                            <span class="text-zinc-600 dark:text-zinc-400">{{ $item->category }}</span>
                                            <span class="tabular-nums font-medium text-zinc-800 dark:text-zinc-200">{{ \App\Support\Money::format($item->amount) }}</span>
                                        </div>
                                    @endforeach
                                    <div class="border-t border-zinc-200 pt-0.5 text-xs font-semibold tabular-nums text-emerald-700 dark:text-zinc-400 dark:border-zinc-700">{{ \App\Support\Money::format($entry->assets) }}</div>
                                </div>
                            </td>
                            <td class="px-3 py-2">
                                <div x-show="!expanded" class="font-medium tabular-nums text-rose-700 dark:text-rose-400">{{ \App\Support\Money::format($entry->liabilities) }}</div>
                                <div x-show="expanded" class="space-y-0.5">
                                    @foreach ($entry->lineItems->where('type', 'liability') as $item)
                                        <div class="flex items-center justify-between gap-3 text-xs">
                                            <span class="text-zinc-600 dark:text-zinc-400">{{ $item->category }}</span>
                                            <span class="tabular-nums font-medium text-zinc-800 dark:text-zinc-200">{{ \App\Support\Money::format($item->amount) }}</span>
                                        </div>
                                    @endforeach
                                    <div class="border-t border-zinc-200 pt-0.5 text-xs font-semibold tabular-nums text-rose-700 dark:text-zinc-400 dark:border-zinc-700">{{ \App\Support\Money::format($entry->liabilities) }}</div>
                                </div>
                            </td>
                            <td class="px-3 py-2 font-semibold tabular-nums {{ $entry->net_worth >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400' }}">{{ \App\Support\Money::format($entry->net_worth) }}</td>
                            <td class="px-3 py-2 text-right whitespace-nowrap space-x-3">
                                <button type="button" x-on:click="expanded = !expanded" x-bind:aria-expanded="expanded.toString()" class="text-xs font-medium text-zinc-600 hover:text-zinc-800 focus-visible:rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 dark:text-zinc-300 dark:hover:text-zinc-100" x-text="expanded ? 'Collapse' : 'Expand'"></button>
                                <button type="button" wire:click="edit({{ $entry->id }})" class="text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400">Edit</button>
                                <button type="button" wire:click="confirmDelete({{ $entry->id }})" class="text-xs font-medium text-rose-600 hover:text-rose-800 dark:text-rose-400">Delete</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-zinc-600 dark:text-zinc-400">
                                No entries yet. Start by creating a snapshot of your current assets and liabilities.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="divide-y divide-app-border md:hidden">
            @forelse ($entries as $entry)
                <article class="p-4" x-data="{ expanded: false }">
                    <div class="flex items-start justify-between gap-3"><div><p class="font-semibold">{{ $entry->date->format('j M Y') }}</p><p class="mt-1 text-sm app-muted">Assets {{ \App\Support\Money::format($entry->assets) }} · Liabilities {{ \App\Support\Money::format($entry->liabilities) }}</p></div><p class="shrink-0 font-semibold tabular-nums {{ \App\Support\Money::normalize($entry->net_worth) < 0 ? 'text-rose-700 dark:text-rose-400' : '' }}">{{ \App\Support\Money::format($entry->net_worth) }}</p></div>
                    <button type="button" x-on:click="expanded = !expanded" x-bind:aria-expanded="expanded.toString()" class="app-link mt-3 inline-flex min-h-11 items-center text-sm" x-text="expanded ? 'Hide details' : 'Show details'"></button>
                    <div x-show="expanded" x-cloak class="mt-3 grid grid-cols-2 gap-3 border-t border-app-border pt-3 text-xs">
                        <div><p class="font-semibold">Assets</p>@foreach ($entry->lineItems->where('type', 'asset') as $item)<p class="mt-1 break-words app-muted">{{ $item->category }} · {{ \App\Support\Money::format($item->amount) }}</p>@endforeach</div>
                        <div><p class="font-semibold">Liabilities</p>@foreach ($entry->lineItems->where('type', 'liability') as $item)<p class="mt-1 break-words app-muted">{{ $item->category }} · {{ \App\Support\Money::format($item->amount) }}</p>@endforeach</div>
                    </div>
                    <div class="mt-3 flex gap-4 text-sm"><button type="button" wire:click="edit({{ $entry->id }})" class="app-link inline-flex min-h-11 min-w-11 items-center justify-center">Edit</button><button type="button" wire:click="confirmDelete({{ $entry->id }})" class="inline-flex min-h-11 min-w-11 items-center justify-center font-medium text-rose-700 dark:text-rose-400">Delete</button></div>
                </article>
            @empty
                <p class="app-empty m-4">No snapshots yet. Add your current assets and liabilities to start tracking net worth.</p>
            @endforelse
        </div>
        @if ($entries->hasPages())
            <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-700">
                {{ $entries->links() }}
            </div>
        @endif
    </div>

    {{-- Net worth snapshot modal --}}
    <flux:modal
        name="networth-form"
        x-init="$el.querySelector('dialog').setAttribute('aria-labelledby', 'networth-form-title')"
        x-on:open-networth-modal.window="$flux.modal('networth-form').show()"
        x-on:close-networth-modal.window="$flux.modal('networth-form').close()"
        focusable
        class="min-w-0 w-[calc(100vw-2rem)] max-w-4xl"
    >
        <div class="space-y-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div><flux:heading id="networth-form-title" level="2" size="lg">{{ $entryId ? 'Edit snapshot' : 'New snapshot' }}</flux:heading><p class="mt-1 max-w-md text-sm app-muted">Add assets and liabilities, or copy a previous snapshot and update its amounts.</p></div>
                <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/50">
                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Net Worth</p>
                    <div class="mt-1 rounded-lg px-3 py-1.5 text-lg font-bold {{ $this->calculatedNetWorthStyle }}">
                        £{{ $this->calculatedNetWorth }}
                    </div>
                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                        Assets £{{ $this->assetTotalFormatted }}
                        &nbsp;·&nbsp; Liabilities £{{ $this->liabilityTotalFormatted }}
                    </p>
                </div>
            </div>

            <form wire:submit.prevent="save" class="space-y-5">
                {{-- Date --}}
                <div class="space-y-3">
                    <div class="max-w-xs">
                        <label for="networth-snapshot-date" class="app-form-label">Snapshot date</label>
                        <input id="networth-snapshot-date" type="date" wire:model="date" max="{{ today()->toDateString() }}"
                               class="app-field mt-1.5 w-full"/>
                        @error('date') <p class="mt-1 text-xs text-rose-600" data-action-error role="alert">{{ $message }}</p> @enderror
                    </div>
                    @if (!$entryId)
                        <div class="flex flex-wrap items-center gap-3">
                            <button type="button" wire:click="copyPreviousSnapshot"
                                    wire:loading.attr="disabled" wire:target="copyPreviousSnapshot"
                                    class="app-button-secondary">
                                Copy most recent snapshot before this date
                            </button>
                            @if ($copiedFromDate)
                                <p class="text-sm text-zinc-600 dark:text-zinc-400" role="status">
                                    Copied from {{ \Carbon\Carbon::parse($copiedFromDate)->format('j M Y') }}. Review and update the amounts before saving.
                                </p>
                            @endif
                        </div>
                        @error('copy') <p class="text-sm text-rose-600" role="alert" data-action-error>{{ $message }}</p> @enderror
                    @endif
                </div>

                <p class="text-xs app-muted">Use one line per asset or liability. The total above updates when you add or edit lines.</p>
                {{-- Assets & Liabilities side by side --}}
                <div class="grid gap-4 lg:grid-cols-2">
                    {{-- Assets --}}
                    <div class="rounded-lg border border-emerald-200 dark:border-emerald-800/50">
                        <div class="border-b border-emerald-200 bg-emerald-50 px-3 py-2 dark:border-emerald-800/50 dark:bg-emerald-900/20">
                            <h3 class="text-sm font-semibold text-emerald-800 dark:text-emerald-300">Assets</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="networth-line-table min-w-full text-sm">
                                <thead class="border-b border-zinc-100 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                                    <tr>
                                        <th class="px-3 py-1.5 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-300">Category</th>
                                        <th class="px-3 py-1.5 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-300">Amount (£)</th>
                                        <th class="px-3 py-1.5 text-right text-xs font-semibold text-zinc-500 dark:text-zinc-300">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                                    @forelse ($assetLines as $index => $asset)
                                        <tr>
                                            <td class="px-3 py-2 align-top">
                                                @if ($editingAssetIndex === $index)
                                                    <input type="text" wire:model="assetLines.{{ $index }}.category" aria-label="Asset name {{ $index + 1 }}"
                                                           class="app-field w-full text-sm"/>
                                                    @error('assetLines.' . $index . '.category') <p class="mt-0.5 text-xs text-rose-600" data-action-error role="alert">{{ $message }}</p> @enderror
                                                @else
                                                    <span class="text-zinc-800 dark:text-zinc-100">{{ $asset['category'] }}</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 align-top">
                                                @if ($editingAssetIndex === $index)
                                                    <input type="number" min="0" step="0.01" wire:model="assetLines.{{ $index }}.amount" aria-label="Asset amount {{ $index + 1 }} in pounds"
                                                           class="app-field w-full text-sm"/>
                                                    @error('assetLines.' . $index . '.amount') <p class="mt-0.5 text-xs text-rose-600" data-action-error role="alert">{{ $message }}</p> @enderror
                                                @else
                                                    <span class="font-medium tabular-nums">{{ \App\Support\Money::format($asset['amount']) }}</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 text-right space-x-2">
                                                @if ($editingAssetIndex === $index)
                                                    <button type="button" wire:click="saveAssetLine({{ $index }})" class="inline-flex min-h-11 min-w-11 items-center justify-center text-xs font-medium text-emerald-600 sm:min-h-0 sm:min-w-0">Save</button>
                                                @else
                                                    <button type="button" wire:click="editAssetLine({{ $index }})" class="inline-flex min-h-11 min-w-11 items-center justify-center text-xs font-medium text-blue-600 sm:min-h-0 sm:min-w-0">Edit</button>
                                                @endif
                                                <button type="button" wire:click="removeAssetLine({{ $index }})" class="inline-flex min-h-11 min-w-11 items-center justify-center text-xs font-medium text-rose-600 sm:min-h-0 sm:min-w-0">Delete</button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="px-3 py-3 text-center text-xs text-zinc-600 dark:text-zinc-400">No assets added yet.</td>
                                        </tr>
                                    @endforelse
                                    {{-- Inline add row --}}
                                    <tr class="bg-zinc-50 dark:bg-zinc-800/50">
                                        <td class="px-3 py-2">
                                            <input type="text" wire:model="newAssetCategory" aria-label="New asset name" placeholder="e.g., Cash ISA"
                                                   class="app-field w-full text-sm"/>
                                            @error('newAssetCategory') <p class="mt-0.5 text-xs text-rose-600" data-action-error role="alert">{{ $message }}</p> @enderror
                                        </td>
                                        <td class="px-3 py-2">
                                            <input type="number" min="0" step="0.01" inputmode="decimal" wire:model="newAssetAmount" aria-label="New asset amount in pounds" placeholder="0.00"
                                                   class="app-field w-full text-sm"/>
                                            @error('newAssetAmount') <p class="mt-0.5 text-xs text-rose-600" data-action-error role="alert">{{ $message }}</p> @enderror
                                        </td>
                                        <td class="px-3 py-2 text-right">
                                            <button type="button" wire:click="addAssetLine"
                                                    class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-md bg-emerald-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700 sm:min-h-0 sm:min-w-0">Add</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {{-- Liabilities --}}
                    <div class="rounded-lg border border-rose-200 dark:border-rose-800/50">
                        <div class="border-b border-rose-200 bg-rose-50 px-3 py-2 dark:border-rose-800/50 dark:bg-rose-900/20">
                            <h3 class="text-sm font-semibold text-rose-800 dark:text-rose-300">Liabilities</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="networth-line-table min-w-full text-sm">
                                <thead class="border-b border-zinc-100 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                                    <tr>
                                        <th class="px-3 py-1.5 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-300">Category</th>
                                        <th class="px-3 py-1.5 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-300">Amount (£)</th>
                                        <th class="px-3 py-1.5 text-right text-xs font-semibold text-zinc-500 dark:text-zinc-300">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                                    @forelse ($liabilityLines as $index => $liability)
                                        <tr>
                                            <td class="px-3 py-2 align-top">
                                                @if ($editingLiabilityIndex === $index)
                                                    <input type="text" wire:model="liabilityLines.{{ $index }}.category" aria-label="Liability name {{ $index + 1 }}"
                                                           class="app-field w-full text-sm"/>
                                                    @error('liabilityLines.' . $index . '.category') <p class="mt-0.5 text-xs text-rose-600" data-action-error role="alert">{{ $message }}</p> @enderror
                                                @else
                                                    <span class="text-zinc-800 dark:text-zinc-100">{{ $liability['category'] }}</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 align-top">
                                                @if ($editingLiabilityIndex === $index)
                                                    <input type="number" min="0" step="0.01" wire:model="liabilityLines.{{ $index }}.amount" aria-label="Liability amount {{ $index + 1 }} in pounds"
                                                           class="app-field w-full text-sm"/>
                                                    @error('liabilityLines.' . $index . '.amount') <p class="mt-0.5 text-xs text-rose-600" data-action-error role="alert">{{ $message }}</p> @enderror
                                                @else
                                                    <span class="font-medium tabular-nums">{{ \App\Support\Money::format($liability['amount']) }}</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 text-right space-x-2">
                                                @if ($editingLiabilityIndex === $index)
                                                    <button type="button" wire:click="saveLiabilityLine({{ $index }})" class="inline-flex min-h-11 min-w-11 items-center justify-center text-xs font-medium text-emerald-600 sm:min-h-0 sm:min-w-0">Save</button>
                                                @else
                                                    <button type="button" wire:click="editLiabilityLine({{ $index }})" class="inline-flex min-h-11 min-w-11 items-center justify-center text-xs font-medium text-blue-600 sm:min-h-0 sm:min-w-0">Edit</button>
                                                @endif
                                                <button type="button" wire:click="removeLiabilityLine({{ $index }})" class="inline-flex min-h-11 min-w-11 items-center justify-center text-xs font-medium text-rose-600 sm:min-h-0 sm:min-w-0">Delete</button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="px-3 py-3 text-center text-xs text-zinc-600 dark:text-zinc-400">No liabilities added yet.</td>
                                        </tr>
                                    @endforelse
                                    {{-- Inline add row --}}
                                    <tr class="bg-zinc-50 dark:bg-zinc-800/50">
                                        <td class="px-3 py-2">
                                            <input type="text" wire:model="newLiabilityCategory" aria-label="New liability name" placeholder="e.g., Mortgage"
                                                   class="app-field w-full text-sm"/>
                                            @error('newLiabilityCategory') <p class="mt-0.5 text-xs text-rose-600" data-action-error role="alert">{{ $message }}</p> @enderror
                                        </td>
                                        <td class="px-3 py-2">
                                            <input type="number" min="0" step="0.01" inputmode="decimal" wire:model="newLiabilityAmount" aria-label="New liability amount in pounds" placeholder="0.00"
                                                   class="app-field w-full text-sm"/>
                                            @error('newLiabilityAmount') <p class="mt-0.5 text-xs text-rose-600" data-action-error role="alert">{{ $message }}</p> @enderror
                                        </td>
                                        <td class="px-3 py-2 text-right">
                                            <button type="button" wire:click="addLiabilityLine"
                                                    class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-md bg-rose-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-rose-700 sm:min-h-0 sm:min-w-0">Add</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                @error('save') <p class="text-sm text-rose-600" data-action-error role="alert">{{ $message }}</p> @enderror

                <div class="app-form-actions">
                    <flux:modal.close>
                        <button type="button" class="app-button-secondary">
                            Cancel
                        </button>
                    </flux:modal.close>
                    <button type="submit" class="app-button-primary">
                        {{ $entryId ? 'Save changes' : 'Add snapshot' }}
                    </button>
                </div>
            </form>
        </div>
    </flux:modal>

    <flux:modal
        name="delete-networth"
        x-init="$el.querySelector('dialog').setAttribute('aria-labelledby', 'delete-networth-title')"
        x-on:open-delete-networth-modal.window="$flux.modal('delete-networth').show()"
        x-on:close-delete-networth-modal.window="$flux.modal('delete-networth').close()"
        focusable
        class="max-w-lg"
    >
        <div class="space-y-5">
            <div>
                <flux:heading id="delete-networth-title" level="2" size="lg">Delete net worth snapshot?</flux:heading>
                <flux:subheading class="mt-2">
                    This permanently deletes the snapshot{{ $deletingEntryDate ? ' from ' . $deletingEntryDate : '' }} and all of its line items.
                </flux:subheading>
            </div>

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">
                    Delete snapshot
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
