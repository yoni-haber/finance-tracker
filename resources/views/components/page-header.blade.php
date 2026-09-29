@props(['eyebrow' => null, 'title', 'description' => null])

<header class="app-page-header">
    <div class="min-w-0">
        @if ($eyebrow)
            <p class="app-eyebrow">{{ $eyebrow }}</p>
        @endif
        <h1 class="app-page-title">{{ $title }}</h1>
        @if ($description)
            <p class="mt-2 max-w-2xl text-sm app-muted">{{ $description }}</p>
        @endif
    </div>
    @if ($slot->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2">{{ $slot }}</div>
    @endif
</header>
