<section class="app-card min-w-0 p-5 sm:p-6" aria-labelledby="dashboard-notes-heading">
    <h2 id="dashboard-notes-heading" class="text-lg font-semibold tracking-tight">Notes</h2>
    <p class="mt-1 text-sm app-muted">A place for reminders and anything you want to keep in mind.</p>

    <form wire:submit="save" class="mt-4 space-y-3">
        <div>
            <label for="dashboard-note-body" class="sr-only">Your note</label>
            <textarea id="dashboard-note-body" wire:model="body" wire:loading.attr="readonly" wire:target="save" rows="8" maxlength="2000" placeholder="What would you like to remember?" aria-describedby="dashboard-note-hint{{ $errors->has('body') ? ' dashboard-note-error' : '' }}" @error('body') aria-invalid="true" @enderror class="app-field min-h-48 w-full resize-y [overflow-wrap:anywhere]">{{ $body }}</textarea>
            <p id="dashboard-note-hint" class="mt-1 text-xs app-muted">Up to 2,000 characters. Only you can see your note.</p>
            @error('body') <p id="dashboard-note-error" class="app-form-error" data-action-error role="alert">{{ $message }}</p> @enderror
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="text-sm">
                <p wire:dirty wire:target="body" class="app-muted">Unsaved changes</p>
                @if ($status !== '')
                    <p wire:dirty.remove wire:target="body" class="font-medium text-finance-positive" data-action-feedback role="status">{{ $status }}</p>
                @endif
            </div>
            <button type="submit" wire:loading.attr="disabled" wire:target="save" class="app-button-primary"><span wire:loading.remove wire:target="save">Save note</span><span wire:loading wire:target="save">Saving…</span></button>
        </div>
    </form>
</section>
