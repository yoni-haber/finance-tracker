@props(['title', 'description' => null, 'descriptionClass' => 'max-w-2xl'])

<header class="app-page-header">
    <div class="min-w-0">
        <h1 class="app-page-title">{{ $title }}</h1>
        @if ($description)
            <p class="mt-2 text-sm app-muted {{ $descriptionClass }}">{{ $description }}</p>
        @endif
    </div>
    @if ($slot->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2">{{ $slot }}</div>
    @endif
</header>
