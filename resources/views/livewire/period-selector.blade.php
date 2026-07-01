<div class="px-1 py-2">
    <p class="mb-1 px-1 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Period</p>
    <div class="flex items-center gap-1">
        <button
            type="button"
            wire:click="previousMonth"
            aria-label="Previous month"
            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-zinc-300 text-zinc-600 hover:bg-zinc-100 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800"
        >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </button>

        <select
            wire:model.live="month"
            aria-label="Month"
            class="h-8 flex-1 rounded-md border-gray-300 py-1.5 text-sm dark:bg-zinc-800 dark:border-zinc-700"
        >
            @foreach (range(1, 12) as $m)
                <option value="{{ $m }}">{{ now()->startOfYear()->month($m)->shortMonthName }}</option>
            @endforeach
        </select>

        <select
            wire:model.live="year"
            aria-label="Year"
            class="h-8 w-20 rounded-md border-gray-300 py-1.5 text-sm dark:bg-zinc-800 dark:border-zinc-700"
        >
            @foreach ($years as $y)
                <option value="{{ $y }}">{{ $y }}</option>
            @endforeach
        </select>

        <button
            type="button"
            wire:click="nextMonth"
            aria-label="Next month"
            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-zinc-300 text-zinc-600 hover:bg-zinc-100 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800"
        >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        </button>
    </div>
</div>
