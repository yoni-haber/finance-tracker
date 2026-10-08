<div class="space-y-5 sm:space-y-7">
    <x-page-header eyebrow="Planning ahead" title="Upcoming Payments" description="Keep track of payments before they happen. Plans do not count as spending until you record a transaction." description-class="max-w-none xl:whitespace-nowrap">
        <button type="button" wire:click="openCreate" class="app-button-primary">+ Add payment</button>
    </x-page-header>

    @if ($status !== '' || session()->has('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-800 dark:bg-emerald-900/20" data-action-feedback role="status">
            <div class="flex flex-wrap items-center gap-3 text-sm font-medium text-emerald-800 dark:text-emerald-300"><span>{{ $status !== '' ? $status : session('status') }}</span>@if ($status !== '' && $statusUndoId !== null)<button type="button" wire:click="undo({{ $statusUndoId }})" class="app-link underline">Undo</button>@endif</div>
        </div>
    @endif

    <section class="app-card overflow-hidden" aria-labelledby="planned-payments-heading">
        <div class="border-b border-app-border px-4 py-3 sm:px-5">
            <h2 id="planned-payments-heading" class="text-base font-semibold">Your payment plans</h2>
            <p class="mt-0.5 text-xs app-muted">Every due date in the next 12 months, plus the next date for overdue and later plans.</p>
        </div>
        @if ($payments->isEmpty())
            <div class="p-5 sm:p-8">
                <p class="font-medium">No upcoming payments yet.</p>
                <p class="mt-1 text-sm app-muted">Add a payment you want to plan for, even if you have never paid it before.</p>
                <button type="button" wire:click="openCreate" class="app-link mt-3 inline-flex min-h-11 items-center">Add your first payment</button>
            </div>
        @else
            <div class="hidden overflow-x-auto lg:block">
                <table class="w-full table-fixed divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                    <caption class="sr-only">Payment occurrences in due date order</caption>
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th scope="col" class="w-32 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide app-muted">Due date</th>
                            <th scope="col" class="w-44 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide app-muted">Payment</th>
                            <th scope="col" class="hidden w-24 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide app-muted xl:table-cell">Repeat</th>
                            <th scope="col" class="w-24 px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide app-muted">Amount</th>
                            <th scope="col" class="hidden px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide app-muted xl:table-cell">Note</th>
                            <th scope="col" class="w-48 px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide app-muted">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($payments as $item)
                            @php $payment = $item['plan']; $due = $item['due']; $isNext = $item['is_next']; @endphp
                            <tr wire:key="planned-payment-desktop-{{ $payment->id }}-{{ $due->toDateString() }}" class="h-14 hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                <td class="whitespace-nowrap px-3 py-2"><time datetime="{{ $due->toDateString() }}">{{ $due->format('j M Y') }}</time><span class="block text-xs font-medium {{ $due->isBefore(today()) ? 'text-[#a33d36] dark:text-[#ffafa6]' : 'text-finance-positive' }}">{{ $payment->dueLabel($due) }}</span></td>
                                <th scope="row" class="break-words px-3 py-2 text-left font-medium">{{ $payment->name }}<span class="block truncate text-xs font-normal app-muted xl:hidden" title="{{ $payment->note }}">{{ $payment->frequencyLabel() }}@if ($payment->note) · {{ $payment->note }}@endif</span></th>
                                <td class="hidden px-3 py-2 xl:table-cell">{{ $payment->frequencyLabel() }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums">{{ \App\Support\Money::format($payment->amount) }}</td>
                                <td class="hidden px-3 py-2 text-xs app-muted xl:table-cell"><span class="line-clamp-2 break-words leading-4" title="{{ $payment->note }}">{{ $payment->note ?: '—' }}</span></td>
                                <td class="whitespace-nowrap px-3 py-2 text-right">
                                    <div class="flex items-center justify-end gap-3">
                                        @if ($isNext)
                                            <button type="button" wire:click="markDone({{ $payment->id }}, '{{ $due->toDateString() }}')" wire:loading.attr="disabled" class="app-button-primary px-3 text-xs">Mark done <span class="sr-only">{{ $payment->name }} due {{ $due->format('j M Y') }}</span></button>
                                        @endif
                                        <button type="button" wire:click="edit({{ $payment->id }})" wire:loading.attr="disabled" wire:target="edit({{ $payment->id }})" class="text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400">Edit <span class="sr-only">{{ $payment->name }} plan</span></button>
                                        <button type="button" wire:click="confirmDelete({{ $payment->id }})" wire:loading.attr="disabled" wire:target="confirmDelete" class="text-xs font-medium text-rose-600 hover:text-rose-800 dark:text-rose-400">Delete <span class="sr-only">{{ $payment->name }} plan</span></button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="divide-y divide-app-border lg:hidden">
                @foreach ($payments as $item)
                    @php $payment = $item['plan']; $due = $item['due']; $isNext = $item['is_next']; @endphp
                    <article wire:key="planned-payment-mobile-{{ $payment->id }}-{{ $due->toDateString() }}" class="p-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <h3 class="break-words text-sm font-semibold">{{ $payment->name }}</h3>
                                @if ($payment->note)<p class="mt-0.5 line-clamp-2 break-words text-xs app-muted">{{ $payment->note }}</p>@endif
                            </div>
                            <p class="shrink-0 text-sm font-semibold tabular-nums">{{ \App\Support\Money::format($payment->amount) }}</p>
                        </div>
                        <p class="mt-1 text-xs"><time datetime="{{ $due->toDateString() }}">{{ $due->format('j M Y') }}</time><span class="mx-1 app-muted" aria-hidden="true">·</span>{{ $payment->frequencyLabel() }}<span class="mx-1 app-muted" aria-hidden="true">·</span><span class="font-medium {{ $due->isBefore(today()) ? 'text-[#a33d36] dark:text-[#ffafa6]' : 'text-finance-positive' }}">{{ $payment->dueLabel($due) }}</span></p>
                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                            @if ($isNext)
                                <button type="button" wire:click="markDone({{ $payment->id }}, '{{ $due->toDateString() }}')" wire:loading.attr="disabled" class="app-button-primary px-3 text-xs">Mark done <span class="sr-only">{{ $payment->name }} due {{ $due->format('j M Y') }}</span></button>
                            @endif
                            <button type="button" wire:click="edit({{ $payment->id }})" class="app-link min-h-10">Edit <span class="sr-only">{{ $payment->name }} plan</span></button>
                            <button type="button" wire:click="confirmDelete({{ $payment->id }})" class="min-h-10 font-medium text-rose-700 dark:text-rose-400">Delete <span class="sr-only">{{ $payment->name }} plan</span></button>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <flux:modal name="planned-payment-form" @close="resetForm" x-on:open-planned-payment-modal.window="$flux.modal('planned-payment-form').show(); $nextTick(() => $el.querySelector('[data-planned-heading]').focus({ preventScroll: true }))" x-on:close-planned-payment-modal.window="$flux.modal('planned-payment-form').close()" focusable class="min-w-0 w-[calc(100vw-2rem)] max-w-lg">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg" level="2" tabindex="-1" autofocus data-planned-heading class="focus:outline-none">{{ $editingId ? 'Edit payment plan' : 'Add payment plan' }}</flux:heading>
                <p class="mt-1 text-sm app-muted">This is only a reminder for your budget planning. Record the transaction separately when you pay.</p>
            </div>
            <form wire:submit.prevent="save" class="space-y-4">
                <div><label for="plan-name" class="app-form-label">Name</label><input id="plan-name" type="text" wire:model="name" maxlength="120" placeholder="e.g. Car insurance" autocomplete="off" class="app-field mt-1.5 w-full" />@error('name') <p class="app-form-error" data-action-error role="alert">{{ $message }}</p> @enderror</div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label for="plan-amount" class="app-form-label">Expected amount (£)</label><input id="plan-amount" type="number" min="0.01" step="0.01" inputmode="decimal" wire:model="amount" placeholder="0.00" class="app-field mt-1.5 w-full" />@error('amount') <p class="app-form-error" data-action-error role="alert">{{ $message }}</p> @enderror</div>
                    <div><label for="plan-due" class="app-form-label">Next due date</label><input id="plan-due" type="date" wire:model="due_on" class="app-field mt-1.5 w-full" />@error('due_on') <p class="app-form-error" data-action-error role="alert">{{ $message }}</p> @enderror</div>
                </div>
                <div><label for="plan-frequency" class="app-form-label">Repeat</label><select id="plan-frequency" wire:model="frequency" class="app-field mt-1.5 w-full"><option value="once">One-off</option><option value="quarterly">Quarterly</option><option value="yearly">Yearly</option></select>@error('frequency') <p class="app-form-error" data-action-error role="alert">{{ $message }}</p> @enderror</div>
                <div><label for="plan-note" class="app-form-label">Note <span class="normal-case font-normal app-muted">(optional)</span></label><textarea id="plan-note" wire:model="note" rows="2" maxlength="500" placeholder="Anything useful to remember" class="app-field mt-1.5 w-full"></textarea>@error('note') <p class="app-form-error" data-action-error role="alert">{{ $message }}</p> @enderror</div>
                @if ($editingId)
                    <p class="text-xs app-muted">Changes to this plan affect its upcoming dates. Changing the date or repeat choice starts a new schedule from that date.</p>
                @endif
                <div class="app-form-actions"><flux:modal.close><button type="button" class="app-button-secondary">Cancel</button></flux:modal.close><button type="submit" wire:loading.attr="disabled" wire:target="save" class="app-button-primary">{{ $editingId ? 'Save changes' : 'Add payment' }}</button></div>
            </form>
        </div>
    </flux:modal>

    <flux:modal name="delete-planned-payment" @close="resetDelete" x-on:open-delete-planned-payment-modal.window="$flux.modal('delete-planned-payment').show(); $nextTick(() => $el.querySelector('[data-delete-planned-heading]').focus({ preventScroll: true }))" x-on:close-delete-planned-payment-modal.window="$flux.modal('delete-planned-payment').close()" focusable class="min-w-0 w-[calc(100vw-2rem)] max-w-lg">
        <div class="space-y-5">
            <flux:heading size="lg" level="2" tabindex="-1" autofocus data-delete-planned-heading class="focus:outline-none">Delete payment plan?</flux:heading>
            <p class="text-sm app-muted">This permanently removes <strong>{{ $deletingName }}</strong> and all its upcoming dates from your plans.</p>
            <div class="flex flex-wrap justify-end gap-3"><flux:modal.close><button type="button" class="app-button-secondary">Cancel</button></flux:modal.close><flux:button variant="danger" wire:click="delete" wire:loading.attr="disabled">Delete plan</flux:button></div>
        </div>
    </flux:modal>
</div>
