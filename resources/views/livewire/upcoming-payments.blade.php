<div class="space-y-5 sm:space-y-7">
    <x-page-header eyebrow="Planning ahead" title="Upcoming Payments" description="Expected payments based on the amounts and schedules you have entered. No previous payment is needed." description-class="max-w-none">
        <a href="{{ route('transactions', ['new' => 1, 'scheduled' => 1, 'scope' => 'all', 'upcoming' => 1]) }}" wire:navigate class="app-button-primary">+ Add scheduled payment</a>
    </x-page-header>

    @if (session()->has('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-800 dark:bg-emerald-900/20" data-action-feedback role="status">
            <p class="text-sm font-medium text-emerald-800 dark:text-emerald-300">{{ session('status') }}</p>
        </div>
    @endif

    <section class="app-card p-4 sm:p-5" aria-label="Forecast filters">
        <h2 class="mb-4 text-base font-semibold">Forecast</h2>
        <div class="grid items-start gap-4 sm:grid-cols-2 lg:grid-cols-[12rem_12rem_1fr_auto]">
            <div>
                <label for="forecast-months" class="app-form-label">Look ahead</label>
                <select id="forecast-months" wire:model.live="months" class="app-field app-filter-select mt-1.5 w-full" aria-describedby="forecast-months-help">
                    @foreach ([3, 6, 12] as $horizon)<option value="{{ $horizon }}" @selected($months === (string) $horizon)>{{ $horizon }} months</option>@endforeach
                </select>
                <p id="forecast-months-help" class="mt-1.5 text-xs app-muted">Includes the rest of this month</p>
                @error('months')<p class="app-form-error" data-action-error role="alert">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="forecast-minimum" class="app-form-label">Minimum amount (£)</label>
                <input id="forecast-minimum" type="number" inputmode="decimal" min="0" max="9999999999.99" step="0.01" placeholder="Any amount" wire:model.live.debounce.300ms="minimum" class="app-field mt-1.5 w-full" />
                @error('minimum')<p class="app-form-error" data-action-error role="alert">{{ $message }}</p>@enderror
            </div>
            <label class="flex min-h-11 cursor-pointer items-center gap-2.5 sm:mt-5">
                <input type="checkbox" wire:model.live="includeRegular" class="size-4 rounded border-zinc-300 text-emerald-600 focus:ring-emerald-500" />
                <span class="text-sm font-medium">Include weekly/monthly payments</span>
            </label>
            <button type="button" wire:click="resetFilters" class="app-button-secondary sm:mt-5">Reset filters</button>
        </div>
        @if ($forecast)
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-app-border pt-4">
                <div>
                    <p class="text-sm font-semibold">{{ $forecast['start']->format('j M Y') }}–{{ $forecast['end']->format('j M Y') }}</p>
                    <p class="mt-1 text-xs app-muted">Spending only · Savings and investments excluded</p>
                </div>
                <p class="app-badge bg-[#4d6f96]/10 text-[#4d6f96] dark:bg-[#a7c9ec]/10 dark:text-[#a7c9ec]">{{ $forecast['payments']->count() }} {{ \Illuminate\Support\Str::plural('payment', $forecast['payments']->count()) }}</p>
            </div>
        @endif
        <span wire:loading wire:target="months,minimum,includeRegular,resetFilters" role="status" class="mt-2 text-xs app-muted">Updating forecast…</span>
    </section>

    @if ($forecast)
        @if ($forecast['payments']->isEmpty())
            <div class="app-empty">
                @if ($minimum !== '' || $includeRegular)
                    <p>No upcoming payments match these filters.</p>
                    <button type="button" wire:click="resetFilters" class="app-link mt-2">Reset filters</button>
                @else
                    <p>No scheduled spending payments in this date range.</p>
                    <a href="{{ route('transactions', ['new' => 1, 'scheduled' => 1, 'scope' => 'all', 'upcoming' => 1]) }}" wire:navigate class="app-link mt-2 inline-block">Add scheduled payment</a>
                @endif
            </div>
        @endif

        @foreach ($forecast['months'] as $month)
            <section class="app-card overflow-hidden {{ $month['payments']->isNotEmpty() ? 'border-l-4 border-l-emerald-600/60 dark:border-l-[#75ddb2]/60' : '' }}" aria-label="{{ $month['label'] }} payments">
                <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-app-border p-4 sm:px-5 {{ $month['payments']->isNotEmpty() ? 'bg-emerald-600/5 dark:bg-[#75ddb2]/5' : '' }}">
                    <h2 class="{{ $month['payments']->isNotEmpty() ? 'text-lg font-semibold text-emerald-700 dark:text-[#75ddb2]' : 'text-base font-medium app-muted' }}">{{ $month['label'] }}</h2>
                    <p class="font-semibold tabular-nums {{ $month['payments']->isNotEmpty() ? 'text-lg text-[#bd5b52] dark:text-[#f19b91]' : 'text-sm app-muted' }}">{{ \App\Support\Money::format($month['total']) }} <span class="text-xs font-normal app-muted">expected</span></p>
                </div>
                @if ($month['payments']->isEmpty())
                    <p class="p-4 text-sm app-muted sm:px-5">No payments matching these filters.</p>
                @else
                    <div class="hidden md:block">
                        <table class="w-full table-fixed text-sm">
                            <thead class="bg-zinc-50 dark:bg-zinc-800"><tr>
                                <th scope="col" class="w-32 px-4 py-3 text-left app-muted">Due date</th>
                                <th scope="col" class="px-4 py-3 text-left app-muted">Payment</th>
                                <th scope="col" class="w-28 px-4 py-3 text-left app-muted">Frequency</th>
                                <th scope="col" class="w-36 px-4 py-3 text-right app-muted">Amount</th>
                                <th scope="col" class="w-28 px-4 py-3 text-right app-muted">Action</th>
                            </tr></thead>
                            <tbody class="divide-y divide-app-border">
                                @foreach ($month['payments'] as $payment)
                                    <tr>
                                        <td class="px-4 py-3">{{ $payment->date->format('j M Y') }}</td>
                                        <td class="break-words px-4 py-3"><p class="font-medium">{{ $payment->description ?: ($payment->category?->name ?? 'Payment') }}</p><p class="mt-1 text-xs app-muted">{{ $payment->category?->name ?? 'Uncategorised' }}</p></td>
                                        <td class="px-4 py-3"><span class="app-badge bg-[#4d6f96]/10 text-[#4d6f96] dark:bg-[#a7c9ec]/10 dark:text-[#a7c9ec]">{{ $payment->is_recurring ? ucfirst($payment->frequency) : 'One-off' }}</span></td>
                                        <td class="break-words px-4 py-3 text-right font-semibold tabular-nums">{{ \App\Support\Money::format($payment->amount) }}</td>
                                        <td class="px-4 py-3 text-right"><a href="{{ route('transactions', ['edit' => $payment->id, 'scope' => 'all', 'upcoming' => 1]) }}" wire:navigate class="app-link">{{ $payment->is_recurring ? 'Edit series' : 'Edit' }}</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="divide-y divide-app-border md:hidden">
                        @foreach ($month['payments'] as $payment)
                            <article class="p-4">
                                <p class="text-xs app-muted">{{ $payment->date->format('j M Y') }}</p>
                                <div class="mt-2 flex flex-wrap items-baseline justify-between gap-2">
                                    <h3 class="min-w-0 break-words font-medium">{{ $payment->description ?: ($payment->category?->name ?? 'Payment') }}</h3>
                                    <p class="break-words font-semibold tabular-nums">{{ \App\Support\Money::format($payment->amount) }}</p>
                                </div>
                                <div class="mt-2 flex flex-wrap items-center gap-2"><p class="break-words text-xs app-muted">{{ $payment->category?->name ?? 'Uncategorised' }}</p><span class="app-badge bg-[#4d6f96]/10 text-[#4d6f96] dark:bg-[#a7c9ec]/10 dark:text-[#a7c9ec]">{{ $payment->is_recurring ? ucfirst($payment->frequency) : 'One-off' }}</span></div>
                                <a href="{{ route('transactions', ['edit' => $payment->id, 'scope' => 'all', 'upcoming' => 1]) }}" wire:navigate class="app-link mt-2 inline-flex min-h-11 items-center text-sm">{{ $payment->is_recurring ? 'Edit series' : 'Edit' }}</a>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>
        @endforeach
    @else
        <p class="app-empty" role="status">Correct the filters to see expected payments.</p>
    @endif
</div>
