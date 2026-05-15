<div class="space-y-4">
    {{-- Status message --}}
    @if (session()->has('status'))
        <div class="rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 dark:bg-emerald-900/20 dark:border-emerald-800">
            <p class="text-sm font-medium text-emerald-800 dark:text-emerald-300">{{ session('status') }}</p>
        </div>
    @endif

    {{-- Categories card --}}
    <div class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">

        {{-- Toolbar --}}
        <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Categories</h2>
            <button
                wire:click="openModal"
                class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-1"
            >
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New category
            </button>
        </div>

        {{-- Categories grid --}}
        <div class="p-4">
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @forelse ($categories as $category)
                    <div class="flex items-center justify-between rounded-lg border border-zinc-200 px-4 py-3 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <div class="min-w-0">
                            <p class="truncate font-medium text-zinc-900 dark:text-white">{{ $category->name }}</p>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $category->transactions()->count() }} transactions</p>
                        </div>
                        <div class="ml-3 flex shrink-0 gap-3 text-sm">
                            <button type="button" wire:click="edit({{ $category->id }})" class="font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400">Edit</button>
                            <button type="button" wire:click="delete({{ $category->id }})" class="font-medium text-rose-600 hover:text-rose-800 dark:text-rose-400">Delete</button>
                        </div>
                    </div>
                @empty
                    <div class="sm:col-span-2 lg:col-span-3 py-8 text-center text-sm text-zinc-400">
                        No categories yet. Add your first category above.
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Category form modal --}}
    <flux:modal
        name="category-form"
        x-on:open-category-modal.window="$flux.modal('category-form').show()"
        x-on:close-category-modal.window="$flux.modal('category-form').close()"
        focusable
        class="max-w-md"
    >
        <div class="space-y-5">
            <flux:heading size="lg">{{ $categoryId ? 'Edit Category' : 'New Category' }}</flux:heading>

            <form wire:submit.prevent="save" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-zinc-300">Category Name</label>
                    <input type="text" wire:model="name" placeholder="e.g. Groceries, Internet, Salary"
                           class="mt-1.5 w-full rounded-md border border-gray-300 dark:bg-zinc-800 dark:border-zinc-700" autofocus/>
                    @error('name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                @error('save') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror

                <div class="flex items-center justify-end gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <flux:modal.close>
                        <button type="button" class="rounded-md border border-zinc-300 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800">
                            Cancel
                        </button>
                    </flux:modal.close>
                    <button type="submit" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                        Save category
                    </button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>

