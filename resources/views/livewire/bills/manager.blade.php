<div class="space-y-5">
    <x-page-header eyebrow="Plan" title="Bills" description="Know when larger quarterly and yearly costs are expected. Estimates do not count as spending.">
        <button type="button" wire:click="openModal" class="app-button-primary">+ New bill</button>
    </x-page-header>

    @if (session()->has('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-800 dark:bg-emerald-900/20" role="status">
            <p class="text-sm font-medium text-emerald-800 dark:text-emerald-300">{{ session('status') }}</p>
        </div>
    @endif

    @if ($bills->isEmpty())
        <div class="app-card p-6 sm:p-8">
            <h2 class="text-lg font-semibold">No planned bills yet</h2>
            <p class="mt-2 text-sm app-muted">Add an estimated amount and date, or start with a previous expense such as an insurance renewal.</p>
            <button type="button" wire:click="openModal" class="app-button-primary mt-5">Add your first bill</button>
        </div>
    @else
        <div class="app-card overflow-hidden">
            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full text-sm" aria-label="Planned bills">
                    <thead class="border-b border-app-border bg-zinc-50 dark:bg-zinc-800/50">
                        <tr class="text-xs uppercase tracking-wide app-muted">
                            <th scope="col" class="px-4 py-3 text-left">Bill</th>
                            <th scope="col" class="px-4 py-3 text-left">Next date</th>
                            <th scope="col" class="px-4 py-3 text-left">Repeats</th>
                            <th scope="col" class="px-4 py-3 text-right">Estimate</th>
                            <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-app-border">
                        @foreach ($bills as $bill)
                            <tr wire:key="bill-row-{{ $bill->id }}" class="align-top">
                                <td class="max-w-sm px-4 py-3">
                                    <p class="break-words font-semibold">{{ $bill->name }}@if ($bill->category) <span class="text-xs font-normal app-muted">({{ $bill->category->name }})</span>@endif</p>
                                    @include('livewire.bills.partials.reference')
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $bill->next_due_date->format('j M Y') }}</td>
                                <td class="px-4 py-3">{{ ucfirst($bill->frequency) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums">{{ \App\Support\Money::format($bill->estimated_amount) }}</td>
                                <td class="px-4 py-3"><div class="flex justify-end gap-3 whitespace-nowrap text-xs">@include('livewire.bills.partials.actions')</div></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="divide-y divide-app-border md:hidden">
                @foreach ($bills as $bill)
                    <article wire:key="bill-mobile-{{ $bill->id }}" class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="break-words text-sm font-semibold">{{ $bill->name }}@if ($bill->category) <span class="text-xs font-normal app-muted">({{ $bill->category->name }})</span>@endif</h2>
                                <p class="mt-1 text-xs app-muted">{{ $bill->next_due_date->format('j M Y') }} · {{ ucfirst($bill->frequency) }}</p>
                            </div>
                            <span class="shrink-0 text-sm font-semibold tabular-nums">{{ \App\Support\Money::format($bill->estimated_amount) }}</span>
                        </div>
                        @include('livewire.bills.partials.reference')
                        <div class="mt-3 flex flex-wrap gap-4 border-t border-app-border pt-3 text-sm">@include('livewire.bills.partials.actions')</div>
                    </article>
                @endforeach
            </div>
        </div>
    @endif

    <flux:modal name="bill-form" x-on:open-bill-modal.window="$flux.modal('bill-form').show()" x-on:close-bill-modal.window="$flux.modal('bill-form').close()" focusable class="min-w-0 w-[calc(100vw-2rem)] max-w-2xl">
        <div class="space-y-5">
            <div><flux:heading size="lg">{{ $billId ? 'Edit bill' : 'New planned bill' }}</flux:heading><p class="mt-1 text-sm app-muted">Use your best estimate. You can change the amount and expected date later.</p></div>
            @unless ($billId)
                <div class="rounded-xl border border-app-border bg-zinc-50/50 p-4 dark:bg-zinc-800/40">
                    <label for="bill-source-search" class="app-form-label">Start from a previous expense <span class="normal-case font-normal app-muted">(optional)</span></label>
                    <input id="bill-source-search" type="search" wire:model.live.debounce.300ms="sourceSearch" placeholder="Search descriptions or categories" class="app-field mt-1.5 w-full" />
                    <div data-testid="bill-source-results" class="mt-2 h-36 space-y-1 overflow-y-auto">
                        @forelse ($sourceTransactions as $transaction)
                            <button type="button" wire:click="useTransaction({{ $transaction->id }})" class="flex w-full items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-left text-sm hover:bg-zinc-100 focus-visible:outline-2 focus-visible:outline-emerald-600 dark:hover:bg-zinc-700">
                                <span class="min-w-0 truncate">{{ $transaction->description ?: ($transaction->category?->name ?? 'Expense') }} · {{ $transaction->date->format('j M Y') }}</span><span class="shrink-0 tabular-nums">{{ \App\Support\Money::format($transaction->amount) }}</span>
                            </button>
                        @empty
                            <p class="flex h-full items-center justify-center px-2 text-center text-sm app-muted" role="status">No available recorded expenses found.</p>
                        @endforelse
                    </div>
                    @if ($sourceTransactionId)<p class="mt-2 text-xs text-emerald-700 dark:text-emerald-400">Previous expense selected. Review the estimate and next date below.</p>@endif
                    @error('sourceTransactionId') <p class="app-form-error">{{ $message }}</p> @enderror
                </div>
            @endunless
            <form wire:submit.prevent="save" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2"><label for="bill-name" class="app-form-label">Bill name</label><input id="bill-name" wire:model="name" type="text" class="app-field mt-1.5 w-full" />@error('name') <p class="app-form-error">{{ $message }}</p> @enderror</div>
                    <div><label for="bill-amount" class="app-form-label">Estimated amount (£)</label><input id="bill-amount" wire:model="estimatedAmount" type="number" min="0.01" step="0.01" inputmode="decimal" class="app-field mt-1.5 w-full" />@error('estimatedAmount') <p class="app-form-error">{{ $message }}</p> @enderror</div>
                    <div><label for="bill-date" class="app-form-label">Next expected date</label><input id="bill-date" wire:model="nextDueDate" type="date" class="app-field mt-1.5 w-full" />@error('nextDueDate') <p class="app-form-error">{{ $message }}</p> @enderror</div>
                    <div><label for="bill-frequency" class="app-form-label">Repeats</label><select id="bill-frequency" wire:model.live="frequency" class="app-field mt-1.5 w-full"><option value="yearly">Yearly</option><option value="quarterly">Quarterly</option></select>@error('frequency') <p class="app-form-error">{{ $message }}</p> @enderror</div>
                    <div>
                        <label for="bill-category" class="app-form-label">Category <span class="normal-case font-normal app-muted">(optional)</span></label>
                        <select id="bill-category" wire:model="categoryId" class="app-field mt-1.5 w-full">
                            <option value="">Uncategorised</option>
                            @foreach ($categories as $parent)
                                @if ($parent->children->isNotEmpty())
                                    <optgroup label="{{ $parent->name }}">
                                        <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                                        @foreach ($parent->children as $child)
                                            <option value="{{ $child->id }}">{{ $child->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @else
                                    <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                                @endif
                            @endforeach
                        </select>
                        @error('categoryId') <p class="app-form-error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label for="bill-note" class="app-form-label">Note <span class="normal-case font-normal app-muted">(optional)</span></label>
                    <textarea id="bill-note" wire:model="note" rows="2" maxlength="2000" class="app-field mt-1.5 w-full"></textarea>
                    @error('note') <p class="app-form-error">{{ $message }}</p> @enderror
                </div>
                @error('save') <p class="app-form-error">{{ $message }}</p> @enderror
                <div class="app-form-actions"><flux:modal.close><button type="button" class="app-button-secondary">Cancel</button></flux:modal.close><button type="submit" wire:loading.attr="disabled" wire:target="save" class="app-button-primary">Save bill</button></div>
            </form>
        </div>
    </flux:modal>

    <flux:modal name="bill-payment" x-on:open-bill-payment-modal.window="$flux.modal('bill-payment').show()" x-on:close-bill-payment-modal.window="$flux.modal('bill-payment').close()" focusable class="min-w-0 w-[calc(100vw-2rem)] max-w-xl">
        <div class="space-y-5">
            <div><flux:heading size="lg">Link a recorded payment</flux:heading><p class="mt-1 text-sm app-muted">Choose the expense that paid this bill. Linking it will not create another transaction.</p></div>
            <form wire:submit.prevent="linkPayment" class="space-y-4">
                <div><label for="bill-payment-search" class="app-form-label">Find expense</label><input id="bill-payment-search" type="search" wire:model.live.debounce.300ms="paymentSearch" placeholder="Search descriptions or categories" class="app-field mt-1.5 w-full" /></div>
                <div data-testid="bill-payment-results" class="h-60 space-y-1 overflow-y-auto" role="group" aria-label="Recorded expenses">
                    @forelse ($paymentTransactions as $transaction)
                        <button type="button" wire:click="selectPayment({{ $transaction->id }})" aria-pressed="{{ $paymentTransactionId === $transaction->id ? 'true' : 'false' }}"
                            class="flex w-full items-center justify-between gap-3 rounded-lg border px-3 py-2 text-left text-sm focus-visible:outline-2 focus-visible:outline-emerald-600 {{ $paymentTransactionId === $transaction->id ? 'border-emerald-500 bg-emerald-50 dark:border-emerald-600 dark:bg-emerald-900/20' : 'border-transparent hover:bg-zinc-100 dark:hover:bg-zinc-700' }}">
                            <span class="min-w-0"><span class="block break-words font-medium">{{ $transaction->description ?: ($transaction->category?->name ?? 'Expense') }}</span><span class="mt-0.5 block text-xs app-muted">{{ $transaction->date->format('j M Y') }}@if ($transaction->category) · {{ $transaction->category->name }}@endif</span></span>
                            <span class="shrink-0 tabular-nums">{{ \App\Support\Money::format($transaction->amount) }}</span>
                        </button>
                    @empty
                        <p class="flex h-full items-center justify-center px-3 text-center text-sm app-muted" role="status">No available recorded expenses found.</p>
                    @endforelse
                </div>
                @if ($selectedPaymentTransaction)
                    <p class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm dark:border-emerald-800 dark:bg-emerald-900/20">Selected: <strong>{{ $selectedPaymentTransaction->description ?: ($selectedPaymentTransaction->category?->name ?? 'Expense') }}</strong> · {{ $selectedPaymentTransaction->date->format('j M Y') }} · {{ \App\Support\Money::format($selectedPaymentTransaction->amount) }}</p>
                @endif
                @error('paymentTransactionId') <p class="app-form-error">{{ $message }}</p> @enderror
                <p class="text-xs app-muted">The next estimate will use this payment's amount. You can edit it afterwards.</p>
                <div class="app-form-actions"><flux:modal.close><button type="button" class="app-button-secondary">Cancel</button></flux:modal.close><button type="submit" wire:loading.attr="disabled" wire:target="linkPayment" @disabled($paymentTransactionId === null) class="app-button-primary">Link payment</button></div>
            </form>
        </div>
    </flux:modal>

    <flux:modal name="bill-history" x-on:open-bill-history-modal.window="$flux.modal('bill-history').show()" x-on:close-bill-history-modal.window="$flux.modal('bill-history').close()" focusable class="min-w-0 w-[calc(100vw-2rem)] max-w-lg">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Payment history</flux:heading>
                @if ($historyBill)<flux:subheading class="mt-2">{{ $historyBill->name }}</flux:subheading>@endif
            </div>
            @if ($historyBill)
                @php($latestPayment = $historyBill->payments->sortByDesc('expected_date')->first())
                <ol class="max-h-80 divide-y divide-app-border overflow-y-auto">
                    @forelse ($historyBill->payments->sortByDesc('expected_date') as $payment)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                            <a class="app-link flex flex-1 items-center justify-between gap-4" href="{{ route('transactions', ['transaction' => $payment->transaction_id]) }}" wire:navigate aria-label="View transaction from {{ $payment->transaction->date->format('j M Y') }} for {{ \App\Support\Money::format($payment->transaction->amount) }}">
                                <span>{{ $payment->transaction->date->format('j M Y') }}</span>
                                <span class="font-semibold tabular-nums">{{ \App\Support\Money::format($payment->transaction->amount) }}</span>
                            </a>
                            @if ($latestPayment?->id === $payment->id)
                                <button type="button" wire:click="confirmUnlinkPayment({{ $payment->id }})" class="app-link text-xs">Unlink payment</button>
                            @endif
                        </li>
                    @empty
                        <li class="py-3 text-sm app-muted">No linked payments.</li>
                    @endforelse
                </ol>
            @endif
            <div class="app-form-actions"><flux:modal.close><button type="button" class="app-button-secondary">Close</button></flux:modal.close></div>
        </div>
    </flux:modal>

    <flux:modal name="unlink-bill-payment" x-on:open-unlink-bill-payment-modal.window="$flux.modal('unlink-bill-payment').show()" x-on:close-unlink-bill-payment-modal.window="$flux.modal('unlink-bill-payment').close()" focusable class="max-w-lg">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Unlink payment?</flux:heading>
                <flux:subheading class="mt-2">Unlink <strong>{{ $unlinkingDescription }}</strong> from this bill? The recorded transaction will remain, and the bill's previous date and estimate will be restored.</flux:subheading>
            </div>
            <div class="flex flex-wrap justify-end gap-3">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="unlinkPayment" wire:loading.attr="disabled" wire:target="unlinkPayment">Unlink payment</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="delete-bill" x-on:open-delete-bill-modal.window="$flux.modal('delete-bill').show()" x-on:close-delete-bill-modal.window="$flux.modal('delete-bill').close()" focusable class="max-w-lg">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Remove planned bill?</flux:heading>
                <flux:subheading class="mt-2">This removes its schedule and payment links. Recorded transactions will remain.</flux:subheading>
            </div>
            <div class="flex flex-wrap justify-end gap-3">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">Remove bill</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
