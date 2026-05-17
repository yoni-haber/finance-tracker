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

        <div class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
            {{-- Income section --}}
            <div class="p-4">
                <h3 class="mb-3 text-xs font-semibold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Income</h3>
                @forelse ($incomeParents as $parent)
                    <div class="mb-2">
                        {{-- Parent row --}}
                        <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800/60">
                            <div class="flex min-w-0 items-center gap-2">
                                <p class="truncate font-semibold text-zinc-900 dark:text-white">{{ $parent->name }}</p>
                                <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ $parent->transactions_count }} transactions</span>
                            </div>
                            <div class="ml-3 flex shrink-0 gap-3 text-sm">
                                <button type="button" wire:click="edit({{ $parent->id }})" class="font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400">Edit</button>
                                <button type="button" wire:click="confirmDelete({{ $parent->id }})" class="font-medium text-rose-600 hover:text-rose-800 dark:text-rose-400">Delete</button>
                            </div>
                        </div>
                        {{-- Subcategory rows --}}
                        @foreach ($parent->children as $sub)
                            <div class="ml-6 mt-1 flex items-center justify-between rounded-lg border border-zinc-100 px-4 py-2 dark:border-zinc-700/50 hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                <div class="flex min-w-0 items-center gap-2">
                                    <svg class="h-3 w-3 shrink-0 text-zinc-300 dark:text-zinc-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v12h12"/></svg>
                                    <p class="truncate text-sm text-zinc-700 dark:text-zinc-300">{{ $sub->name }}</p>
                                    <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ $sub->transactions_count }} transactions</span>
                                </div>
                                <div class="ml-3 flex shrink-0 gap-3 text-sm">
                                    <button type="button" wire:click="edit({{ $sub->id }})" class="font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400">Edit</button>
                                    <button type="button" wire:click="confirmDelete({{ $sub->id }})" class="font-medium text-rose-600 hover:text-rose-800 dark:text-rose-400">Delete</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @empty
                    <p class="py-4 text-center text-sm text-zinc-400">No income categories yet.</p>
                @endforelse
            </div>

            {{-- Expense section --}}
            <div class="p-4">
                <h3 class="mb-3 text-xs font-semibold uppercase tracking-wider text-rose-600 dark:text-rose-400">Expenses</h3>
                @forelse ($expenseParents as $parent)
                    <div class="mb-2">
                        {{-- Parent row --}}
                        <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800/60">
                            <div class="flex min-w-0 items-center gap-2">
                                <p class="truncate font-semibold text-zinc-900 dark:text-white">{{ $parent->name }}</p>
                                <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ $parent->transactions_count }} transactions</span>
                            </div>
                            <div class="ml-3 flex shrink-0 gap-3 text-sm">
                                <button type="button" wire:click="edit({{ $parent->id }})" class="font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400">Edit</button>
                                <button type="button" wire:click="confirmDelete({{ $parent->id }})" class="font-medium text-rose-600 hover:text-rose-800 dark:text-rose-400">Delete</button>
                            </div>
                        </div>
                        {{-- Subcategory rows --}}
                        @foreach ($parent->children as $sub)
                            <div class="ml-6 mt-1 flex items-center justify-between rounded-lg border border-zinc-100 px-4 py-2 dark:border-zinc-700/50 hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                <div class="flex min-w-0 items-center gap-2">
                                    <svg class="h-3 w-3 shrink-0 text-zinc-300 dark:text-zinc-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v12h12"/></svg>
                                    <p class="truncate text-sm text-zinc-700 dark:text-zinc-300">{{ $sub->name }}</p>
                                    <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ $sub->transactions_count }} transactions</span>
                                </div>
                                <div class="ml-3 flex shrink-0 gap-3 text-sm">
                                    <button type="button" wire:click="edit({{ $sub->id }})" class="font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400">Edit</button>
                                    <button type="button" wire:click="confirmDelete({{ $sub->id }})" class="font-medium text-rose-600 hover:text-rose-800 dark:text-rose-400">Delete</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @empty
                    <p class="py-4 text-center text-sm text-zinc-400">No expense categories yet.</p>
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
                {{-- Type --}}
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-zinc-300">Type</label>
                    <select wire:model.live="type" class="mt-1.5 w-full rounded-md border border-gray-300 bg-white text-sm dark:bg-zinc-800 dark:border-zinc-700 dark:text-white">
                        <option value="expense">Expense</option>
                        <option value="income">Income</option>
                    </select>
                    @error('type') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                {{-- Parent (optional — makes this a subcategory) --}}
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-zinc-300">Parent Category <span class="font-normal normal-case text-zinc-400">(optional)</span></label>
                    <select wire:model="parentId" class="mt-1.5 w-full rounded-md border border-gray-300 bg-white text-sm dark:bg-zinc-800 dark:border-zinc-700 dark:text-white">
                        <option value="">No parent (top-level)</option>
                        @foreach ($parentOptions as $option)
                            <option value="{{ $option->id }}">{{ $option->name }}</option>
                        @endforeach
                    </select>
                    @error('parentId') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                {{-- Name --}}
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-zinc-300">Name</label>
                    <input type="text" wire:model="name" placeholder="e.g. Groceries, Salary"
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

    {{-- Delete confirmation modal --}}
    <flux:modal
        name="delete-category"
        x-on:open-delete-category-modal.window="$flux.modal('delete-category').show()"
        x-on:close-delete-category-modal.window="$flux.modal('delete-category').close()"
        focusable
        class="max-w-sm"
    >
        <div class="space-y-4">
            <flux:heading size="lg">Delete Category</flux:heading>

            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Are you sure you want to delete <strong class="text-zinc-900 dark:text-white">{{ $deletingName }}</strong>?
                @if ($deletingHasChildren)
                    <span class="mt-1 block text-rose-600 dark:text-rose-400">This will also delete all subcategories.</span>
                @endif
            </p>

            <div class="flex items-center justify-end gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:modal.close>
                    <button type="button" class="rounded-md border border-zinc-300 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800">
                        Cancel
                    </button>
                </flux:modal.close>
                <button type="button" wire:click="delete" class="rounded-md bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-700">
                    Delete
                </button>
            </div>
        </div>
    </flux:modal>
</div>

