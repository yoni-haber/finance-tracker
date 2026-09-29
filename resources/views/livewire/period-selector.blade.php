<div class="w-full sm:w-auto">
    <div class="flex items-center gap-2">
        <span class="mr-1 hidden text-xs font-semibold uppercase tracking-[0.14em] app-muted sm:inline">Period</span>
        <button
            type="button"
            wire:click="previousMonth"
            @disabled(! $canGoPrevious)
            aria-label="Previous month"
            class="app-button-secondary h-11 w-10 shrink-0 !p-0 disabled:hover:bg-app-surface sm:w-11"
        >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </button>

        <select
            wire:model.live="month"
            aria-label="Month"
            class="app-field app-filter-select app-period-select min-w-0 flex-1 sm:w-28 sm:flex-none"
        >
            @foreach (range(1, 12) as $m)
                <option value="{{ $m }}">{{ now()->startOfYear()->month($m)->shortMonthName }}</option>
            @endforeach
        </select>

        <select
            wire:model.live="year"
            aria-label="Year"
            class="app-field app-filter-select app-period-select w-20 shrink-0 sm:w-24"
        >
            @foreach ($years as $y)
                <option value="{{ $y }}">{{ $y }}</option>
            @endforeach
        </select>

        <button
            type="button"
            wire:click="nextMonth"
            @disabled(! $canGoNext)
            aria-label="Next month"
            class="app-button-secondary h-11 w-10 shrink-0 !p-0 disabled:hover:bg-app-surface sm:w-11"
        >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        </button>
    </div>
</div>
