@if ($bill->note !== null && $bill->note !== '')
    <p class="mt-1 whitespace-pre-line break-words text-xs app-muted">{{ $bill->note }}</p>
@endif
@if ($bill->payments->isNotEmpty())
    <button type="button" wire:click="openHistory({{ $bill->id }})" class="app-link mt-2 text-xs">Payment history ({{ $bill->payments->count() }})</button>
@endif
