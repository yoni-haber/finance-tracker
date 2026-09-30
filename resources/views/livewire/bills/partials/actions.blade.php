<button type="button" wire:click="openPaymentModal({{ $bill->id }})" class="app-link">Link payment</button>
<button type="button" wire:click="edit({{ $bill->id }})" class="app-link">Edit</button>
<button type="button" wire:click="confirmDelete({{ $bill->id }})" class="font-medium text-rose-700 dark:text-rose-400">Remove</button>
