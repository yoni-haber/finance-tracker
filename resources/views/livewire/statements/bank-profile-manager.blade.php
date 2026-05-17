<div class="space-y-4">
    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-800 dark:bg-emerald-900/20">
            <p class="text-sm font-medium text-emerald-800 dark:text-emerald-300">{{ session('status') }}</p>
        </div>
    @endif

    @error('delete')
        <div class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 dark:border-rose-800 dark:bg-rose-900/20">
            <p class="text-sm font-medium text-rose-800 dark:text-rose-300">{{ $message }}</p>
        </div>
    @enderror

    <div class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
            <div>
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Bank profiles</h2>
                <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">Configure CSV parsing formats for different banks.</p>
            </div>

            <div class="flex flex-wrap items-center gap-2 text-sm">
                <a
                    href="{{ route('statements.import') }}"
                    class="rounded-md border border-zinc-300 px-3 py-1.5 text-sm text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800"
                >
                    Back to import
                </a>
                <button
                    type="button"
                    wire:click="showCreate"
                    class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-1"
                >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    New profile
                </button>
            </div>
        </div>

        <div class="p-4">
            @if ($profiles->count() > 0)
                <div class="grid gap-3 lg:grid-cols-2">
                    @foreach ($profiles as $profile)
                        @php
                            $columns = $profile->config['columns'] ?? [];
                            $hasAmountColumn = isset($columns['amount']);
                            $dateColumn = ($columns['date'] ?? 0) + 1;
                            $descriptionColumn = ($columns['description'] ?? 1) + 1;
                            $amountColumn = isset($columns['amount']) ? $columns['amount'] + 1 : null;
                            $debitColumn = isset($columns['debit']) ? $columns['debit'] + 1 : null;
                            $creditColumn = isset($columns['credit']) ? $columns['credit'] + 1 : null;
                            $dateFormat = $profile->config['date_format'] ?? 'd/m/Y';
                            $hasHeader = $profile->config['has_header'] ?? true;
                        @endphp

                        <div class="rounded-lg border border-zinc-200 px-4 py-3 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="truncate font-medium text-zinc-900 dark:text-white">{{ $profile->name }}</h3>
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $profile->statement_type === 'credit_card' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' }}">
                                            {{ $profile->statement_type === 'credit_card' ? 'Credit Card' : 'Bank Statement' }}
                                        </span>
                                    </div>
                                    <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                                        <span>{{ $hasHeader ? 'Header row' : 'No header row' }}</span>
                                        <span>{{ $hasAmountColumn ? 'Signed amount column' : 'Separate debit/credit columns' }}</span>
                                        <span>{{ $dateFormat }}</span>
                                    </div>
                                </div>

                                <div class="flex shrink-0 gap-3 text-xs">
                                    <button
                                        type="button"
                                        wire:click="edit({{ $profile->id }})"
                                        class="font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400"
                                    >
                                        Edit
                                    </button>
                                    <flux:modal.trigger name="confirm-delete-profile-{{ $profile->id }}">
                                        <button type="button" class="font-medium text-rose-600 hover:text-rose-800 dark:text-rose-400">
                                            Delete
                                        </button>
                                    </flux:modal.trigger>
                                </div>
                            </div>

                            <div class="mt-4 grid gap-2 text-xs sm:grid-cols-3">
                                <div class="rounded-md bg-zinc-50 px-3 py-2 dark:bg-zinc-800">
                                    <div class="font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Date</div>
                                    <div class="mt-0.5 text-zinc-900 dark:text-white">Column {{ $dateColumn }}</div>
                                </div>
                                <div class="rounded-md bg-zinc-50 px-3 py-2 dark:bg-zinc-800">
                                    <div class="font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Description</div>
                                    <div class="mt-0.5 text-zinc-900 dark:text-white">Column {{ $descriptionColumn }}</div>
                                </div>
                                @if ($hasAmountColumn)
                                    <div class="rounded-md bg-zinc-50 px-3 py-2 dark:bg-zinc-800">
                                        <div class="font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Amount</div>
                                        <div class="mt-0.5 text-zinc-900 dark:text-white">Column {{ $amountColumn }}</div>
                                    </div>
                                @else
                                    <div class="rounded-md bg-zinc-50 px-3 py-2 dark:bg-zinc-800">
                                        <div class="font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Debit/Credit</div>
                                        <div class="mt-0.5 text-zinc-900 dark:text-white">Columns {{ $debitColumn }}/{{ $creditColumn }}</div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-6 py-8 text-center dark:border-amber-700/50 dark:bg-amber-900/10">
                    <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/40">
                        <svg class="h-6 w-6 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v14zM12 12v6m0-6l-2 2m2-2l2 2"/>
                        </svg>
                    </div>
                    <h3 class="text-base font-semibold text-amber-900 dark:text-amber-200">No bank profiles found</h3>
                    <p class="mt-1 text-sm text-amber-700 dark:text-amber-300">Create your first bank profile to start importing statements.</p>
                    <button
                        type="button"
                        wire:click="showCreate"
                        class="mt-4 inline-flex items-center gap-1.5 rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700"
                    >
                        Create bank profile
                    </button>
                </div>
            @endif
        </div>
    </div>

    <flux:modal
        name="bank-profile-form"
        x-on:open-bank-profile-modal.window="$flux.modal('bank-profile-form').show()"
        x-on:close-bank-profile-modal.window="$flux.modal('bank-profile-form').close()"
        focusable
        class="max-w-3xl"
    >
        <div class="space-y-5">
            <flux:heading size="lg">{{ $editingProfile ? 'Edit Bank Profile' : 'Create Bank Profile' }}</flux:heading>

            <form wire:submit.prevent="save" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-zinc-300">Profile Name</label>
                    <input
                        type="text"
                        wire:model="form.name"
                        placeholder="e.g Halifax, American Express"
                        class="mt-1.5 w-full rounded-md border border-gray-300 dark:border-zinc-700 dark:bg-zinc-800"
                    >
                    @error('form.name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">A short name for this statement format.</p>
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-zinc-300">Statement Type</label>
                    <select wire:model="form.statement_type" class="mt-1.5 w-full rounded-md border border-gray-300 dark:border-zinc-700 dark:bg-zinc-800">
                        <option value="bank">Bank Statement</option>
                        <option value="credit_card">Credit Card Statement</option>
                    </select>
                    @error('form.statement_type') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Choose how positive and negative amounts should be interpreted.</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-zinc-300">Date Column</label>
                        <input
                            type="number"
                            wire:model="form.date_column"
                            min="1"
                            class="mt-1.5 w-full rounded-md border border-gray-300 dark:border-zinc-700 dark:bg-zinc-800"
                        >
                        @error('form.date_column') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Column number for the transaction date.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-zinc-300">Description Column</label>
                        <input
                            type="number"
                            wire:model="form.description_column"
                            min="1"
                            class="mt-1.5 w-full rounded-md border border-gray-300 dark:border-zinc-700 dark:bg-zinc-800"
                        >
                        @error('form.description_column') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Column number for the transaction description.</p>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-zinc-300">Date Format</label>
                    <select
                        wire:model="form.date_format"
                        class="mt-1.5 w-full rounded-md border border-gray-300 dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option value="d/m/Y">DD/MM/YYYY (e.g 31/12/2025)</option>
                        <option value="Y-m-d">YYYY-MM-DD (e.g 2025-12-31)</option>
                        <option value="m/d/Y">MM/DD/YYYY (e.g 12/31/2025)</option>
                        <option value="d-m-Y">DD-MM-YYYY (e.g 31-12-2025)</option>
                    </select>
                    @error('form.date_format') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Format of dates in the statement file.</p>
                </div>

                <div class="space-y-3 rounded-md border border-zinc-200 p-3 dark:border-zinc-700">
                    <label class="flex cursor-pointer items-center gap-2.5">
                        <input
                            type="checkbox"
                            wire:model="form.has_header"
                            id="has-header"
                            class="rounded border-gray-300 text-emerald-600 shadow-sm focus:ring-emerald-500 dark:border-zinc-600 dark:bg-zinc-800"
                        >
                        <span class="text-sm font-medium text-gray-700 dark:text-zinc-300">CSV has a header row</span>
                    </label>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Turn off when the first row is already a transaction.</p>
                </div>

                <div class="space-y-3 rounded-md border border-zinc-200 p-3 dark:border-zinc-700">
                    <label class="flex cursor-pointer items-center gap-2.5">
                        <input
                            type="checkbox"
                            wire:model.live="hasSeparateColumns"
                            id="separate-columns"
                            class="rounded border-gray-300 text-emerald-600 shadow-sm focus:ring-emerald-500 dark:border-zinc-600 dark:bg-zinc-800"
                        >
                        <span class="text-sm font-medium text-gray-700 dark:text-zinc-300">CSV has separate debit and credit columns</span>
                    </label>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Use this when money in and money out are separate columns.</p>

                    @if (! $hasSeparateColumns)
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-zinc-300">Amount Column</label>
                            <input
                                type="number"
                                wire:model="form.amount_column"
                                min="1"
                                class="mt-1.5 w-full rounded-md border border-gray-300 dark:border-zinc-700 dark:bg-zinc-800"
                            >
                            @error('form.amount_column') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Column number for signed +/- amounts.</p>
                        </div>
                    @else
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-zinc-300">Debit Column</label>
                                <input
                                    type="number"
                                    wire:model="form.debit_column"
                                    min="1"
                                    class="mt-1.5 w-full rounded-md border border-gray-300 dark:border-zinc-700 dark:bg-zinc-800"
                                >
                                @error('form.debit_column') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Column number for money out.</p>
                            </div>

                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-zinc-300">Credit Column</label>
                                <input
                                    type="number"
                                    wire:model="form.credit_column"
                                    min="1"
                                    class="mt-1.5 w-full rounded-md border border-gray-300 dark:border-zinc-700 dark:bg-zinc-800"
                                >
                                @error('form.credit_column') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Column number for money in.</p>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <button
                        type="button"
                        wire:click="cancel"
                        class="rounded-md border border-zinc-300 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800"
                    >
                        Cancel
                    </button>
                    <button type="submit" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                        {{ $editingProfile ? 'Update Profile' : 'Create Profile' }}
                    </button>
                </div>
            </form>
        </div>
    </flux:modal>

    @if ($openCreateModalOnFirstRender)
        <div x-data x-init="$nextTick(() => $flux.modal('bank-profile-form').show())"></div>
    @endif

    @foreach ($profiles as $profile)
        <flux:modal name="confirm-delete-profile-{{ $profile->id }}" focusable class="max-w-lg">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Delete Bank Profile</flux:heading>
                    <flux:subheading>
                        Are you sure you want to delete the bank profile <strong>"{{ $profile->name }}"</strong>?
                        <br><br>
                        This action cannot be undone.
                    </flux:subheading>
                </div>

                @error('delete')
                    <div class="rounded-md border border-rose-200 bg-rose-50 p-4 dark:border-rose-800 dark:bg-rose-900/20">
                        <p class="text-sm text-rose-800 dark:text-rose-300">{{ $message }}</p>
                    </div>
                @enderror

                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close>
                        <flux:button variant="filled">Cancel</flux:button>
                    </flux:modal.close>

                    <flux:modal.close>
                        <flux:button variant="danger" wire:click="delete({{ $profile->id }})">
                            Delete Profile
                        </flux:button>
                    </flux:modal.close>
                </div>
            </div>
        </flux:modal>
    @endforeach
</div>
