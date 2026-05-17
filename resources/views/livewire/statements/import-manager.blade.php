<div class="space-y-6"
     @if($polling)
         wire:poll.2s="checkImportStatus"
     @endif>

    @if (session('status'))
        <div class="rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 dark:bg-emerald-900/20 dark:border-emerald-800">
            <p class="text-sm font-medium text-emerald-800 dark:text-emerald-300">{{ session('status') }}</p>
        </div>
    @endif

    @if (session('error'))
        <div class="rounded-md bg-rose-50 border border-rose-200 px-4 py-3 dark:bg-rose-900/20 dark:border-rose-800">
            <p class="text-sm font-medium text-rose-800 dark:text-rose-300">{{ session('error') }}</p>
        </div>
    @endif

    {{-- Step progress indicator --}}
    @php
        if ($bankProfiles->isEmpty()) {
            $currentStep = 0;
        } elseif (!$currentImport) {
            $currentStep = 1;
        } elseif ($currentImport->isUploaded() || $currentImport->isParsing()) {
            $currentStep = 2;
        } elseif ($currentImport->isParsed()) {
            $currentStep = 3;
        } elseif ($currentImport->isFailed()) {
            $currentStep = -1;
        } else {
            $currentStep = 4;
        }
        $steps = ['Bank Profile', 'Upload File', 'Processing', 'Review'];
    @endphp

    <div class="rounded-xl border border-zinc-200 bg-white px-4 py-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <nav class="flex items-center justify-between" aria-label="Import progress">
            @foreach ($steps as $i => $label)
                @php $stepNum = $i; $isActive = $currentStep === $stepNum; $isDone = $currentStep > $stepNum && $currentStep >= 0; @endphp
                <div class="flex items-center {{ $loop->last ? '' : 'flex-1' }}">
                    <div class="flex flex-col items-center gap-1">
                        <div class="flex h-8 w-8 items-center justify-center rounded-full text-sm font-semibold
                            {{ $isActive ? 'bg-emerald-600 text-white ring-2 ring-emerald-300' : ($isDone ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400' : 'bg-zinc-100 text-zinc-400 dark:bg-zinc-800') }}
                            {{ $currentStep === -1 && $i === 2 ? 'bg-rose-100 text-rose-600 dark:bg-rose-900/40' : '' }}
                        ">
                            @if ($isDone)
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                            @else
                                {{ $i + 1 }}
                            @endif
                        </div>
                        <span class="text-xs font-medium {{ $isActive ? 'text-emerald-700 dark:text-emerald-400' : ($isDone ? 'text-zinc-600 dark:text-zinc-400' : 'text-zinc-400') }}">{{ $label }}</span>
                    </div>
                    @if (!$loop->last)
                        <div class="mx-2 h-px flex-1 {{ $isDone ? 'bg-emerald-300 dark:bg-emerald-700' : 'bg-zinc-200 dark:bg-zinc-700' }}"></div>
                    @endif
                </div>
            @endforeach
        </nav>
    </div>

    {{-- No bank profiles: empty state --}}
    @if ($bankProfiles->isEmpty())
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-6 py-8 text-center shadow-sm dark:border-amber-700/50 dark:bg-amber-900/10">
            <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/40">
                <svg class="h-6 w-6 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v14zM12 12v6m0-6l-2 2m2-2l2 2"/>
                </svg>
            </div>
            <h3 class="text-base font-semibold text-amber-900 dark:text-amber-200">No bank profiles set up</h3>
            <p class="mt-1 text-sm text-amber-700 dark:text-amber-300">You need at least one bank profile to import statements. A profile tells the system how to read your statement file.</p>
            <a href="{{ route('statements.bank-profiles', ['create' => 1]) }}"
               class="mt-4 inline-flex items-center gap-1.5 rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">
                Create bank profile
            </a>
        </div>
    @elseif ($currentImport)
        {{-- Current import status --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <p class="font-semibold text-zinc-900 dark:text-white truncate">{{ $currentImport->original_filename }}</p>
                    <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                        Uploaded {{ $currentImport->created_at->diffForHumans() }}
                        @if ($currentImport->bankProfile)
                            &nbsp;·&nbsp; {{ $currentImport->bankProfile->name }}
                        @endif
                        &nbsp;·&nbsp; {{ $currentImport->statement_type === 'credit_card' ? 'Credit Card' : 'Bank Statement' }}
                    </p>
                </div>
                <div class="shrink-0">
                    @if ($currentImport->isUploaded())
                        <span class="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-1 text-xs font-medium text-blue-800 dark:bg-blue-900/30 dark:text-blue-300">Uploaded</span>
                    @elseif ($currentImport->isParsing())
                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                            <span class="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-amber-500"></span>
                            Processing…
                        </span>
                    @elseif ($currentImport->isParsed())
                        <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-medium text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400">Ready for review</span>
                    @elseif ($currentImport->isFailed())
                        <span class="inline-flex items-center rounded-full bg-rose-100 px-2.5 py-1 text-xs font-medium text-rose-800 dark:bg-rose-900/30 dark:text-rose-400">Failed</span>
                    @endif
                </div>
            </div>

            @if ($currentImport->isParsing())
                <div class="mt-4 rounded-md bg-zinc-50 px-4 py-3 text-sm text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                    <p>Processing your CSV file — this may take a minute or two.</p>
                    <p class="mt-0.5 text-xs">You can safely leave this page and return later.</p>
                </div>
            @endif

            <div class="mt-4 flex flex-wrap gap-3">
                @if ($currentImport->isParsed())
                    <button
                        wire:key="review-button-{{ $currentImport->id }}"
                        wire:click="proceedToReview"
                        class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus:ring-2 focus:ring-emerald-500 focus:ring-offset-1"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        Review transactions
                    </button>
                @endif

                @if (!$currentImport->isCommitted())
                    <flux:modal.trigger name="confirm-delete-import">
                        <button
                            wire:key="delete-button-{{ $currentImport->id }}"
                            class="inline-flex items-center gap-1.5 rounded-md border border-rose-300 px-4 py-2 text-sm font-medium text-rose-700 hover:bg-rose-50 dark:border-rose-700 dark:text-rose-400 dark:hover:bg-rose-900/20"
                        >
                            Delete import
                        </button>
                    </flux:modal.trigger>
                @endif
            </div>
        </div>
    @else
        {{-- Upload form --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-zinc-900 dark:text-white">Upload statement</h3>
                    <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">Select your profile and upload a CSV or TXT file.</p>
                </div>
                <a
                    href="{{ route('statements.bank-profiles') }}"
                    class="rounded-md border border-zinc-300 px-3 py-1.5 text-sm text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800"
                >
                    Manage profiles
                </a>
            </div>

            <form wire:submit="uploadStatement" class="mt-5 space-y-5">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-zinc-300">profile</label>
                    <select wire:model="bankProfileId" class="mt-1.5 w-full rounded-md border-gray-300 dark:bg-zinc-800 dark:border-zinc-700">
                        <option value="">Select a profile…</option>
                        @foreach ($bankProfiles as $profile)
                            <option value="{{ $profile->id }}">
                                {{ $profile->name }}
                                ({{ $profile->statement_type === 'credit_card' ? 'Credit Card' : 'Bank Statement' }})
                            </option>
                        @endforeach
                    </select>
                    @error('bankProfileId')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Profiles define how CSV/TXT files are read.</p>
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-zinc-300">Statement file</label>
                    <input
                        type="file"
                        wire:model="csvFile"
                        accept=".csv,.txt"
                        class="mt-1.5 w-full rounded-md border-gray-300 text-sm dark:bg-zinc-800 dark:border-zinc-700"
                    >
                    @error('csvFile')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">CSV or TXT - max 2 MB</p>
                </div>

                <div>
                    <button
                        type="submit"
                        class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-5 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 disabled:opacity-50"
                        wire:loading.attr="disabled"
                    >
                        <span wire:loading.remove wire:target="uploadStatement">Upload statement</span>
                        <span wire:loading wire:target="uploadStatement">Uploading…</span>
                    </button>
                </div>
            </form>
        </div>
    @endif

    {{-- Delete Import Confirmation Modal --}}
    <flux:modal name="confirm-delete-import" focusable class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Delete Import</flux:heading>
                <flux:subheading>
                    Are you sure you want to delete this import? This will permanently remove
                    the uploaded file and any processed transaction data. This action cannot be undone.
                </flux:subheading>
            </div>

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">Cancel</flux:button>
                </flux:modal.close>

                <flux:modal.close>
                    <flux:button variant="danger" wire:click="cancelImport">
                        Delete Import
                    </flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
