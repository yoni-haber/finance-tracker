<x-layouts.app.sidebar :title="$title ?? null">
    <flux:main class="mx-auto w-full max-w-[1440px] px-4 py-6 sm:px-6 lg:px-8">
        @if (request()->routeIs('dashboard', 'transactions', 'budgets'))
            <div class="mb-6 flex justify-end">
                <livewire:period-selector />
            </div>
        @endif
        {{ $slot }}
    </flux:main>
</x-layouts.app.sidebar>
