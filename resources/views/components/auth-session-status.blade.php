@props([
    'status',
])

@if ($status)
    <div data-action-feedback role="status" {{ $attributes->merge(['class' => 'font-medium text-sm text-green-600']) }}>
        {{ $status }}
    </div>
@endif
