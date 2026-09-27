<div class="space-y-5">
    <x-page-header eyebrow="Organise" title="Categories" description="Give every transaction a useful place, and decide how expenses appear in your reports.">
        <button type="button" wire:click="openModal" class="app-button-primary">+ New category</button>
    </x-page-header>
    {{-- Status message --}}
    @if (session()->has('status'))
        <div class="rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 dark:bg-emerald-900/20 dark:border-emerald-800">
            <p class="text-sm font-medium text-emerald-800 dark:text-emerald-300">{{ session('status') }}</p>
        </div>
    @endif

    {{-- Categories card --}}
    <div class="app-card overflow-hidden">

        <div class="border-b border-app-border px-4 py-4 sm:px-5"><h2 class="font-semibold">Your category structure</h2><p class="mt-1 text-sm app-muted">Subcategories sit under their parent. Select a count to view recorded transactions across all dates.</p></div>

        <div class="divide-y divide-app-border">
            @foreach ([['label' => 'Income', 'parents' => $incomeParents, 'expense' => false], ['label' => 'Expenses', 'parents' => $expenseParents, 'expense' => true]] as $section)
                <section class="p-4 sm:p-5" aria-labelledby="category-{{ $section['expense'] ? 'expenses' : 'income' }}-heading">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h3 id="category-{{ $section['expense'] ? 'expenses' : 'income' }}-heading" class="text-xs font-semibold uppercase tracking-wider {{ $section['expense'] ? 'text-rose-700 dark:text-rose-400' : 'text-emerald-700 dark:text-emerald-400' }}">{{ $section['label'] }}</h3>
                        <span class="text-xs app-muted">{{ $section['parents']->count() }} {{ \Illuminate\Support\Str::plural('group', $section['parents']->count()) }}</span>
                    </div>

                    @if ($section['parents']->isEmpty())
                        <p class="app-empty">No {{ strtolower($section['label']) }} categories yet.</p>
                    @else
                        <div class="space-y-3">
                            @foreach ($section['parents'] as $parent)
                                @php($groupTransactions = $parent->transactions_count + $parent->children->sum('transactions_count'))
                                <div class="min-w-0 overflow-hidden rounded-xl border border-app-border">
                                    <div class="flex flex-wrap items-start justify-between gap-3 border-l-4 {{ $section['expense'] ? 'border-rose-300 dark:border-rose-700' : 'border-emerald-300 dark:border-emerald-700' }} bg-zinc-50 px-4 py-3 dark:bg-zinc-800">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <h4 class="min-w-0 break-words font-semibold">{{ $parent->name }}</h4>
                                                @if ($section['expense'])
                                                    @if ($parent->expense_treatment === \App\Models\Category::TREATMENT_INVESTMENT)
                                                        <span class="inline-flex items-center rounded-full bg-violet-100 px-2 py-0.5 text-xs font-medium text-violet-700 dark:bg-violet-900/30 dark:text-violet-300">{{ $parent->expenseTreatmentLabel() }}</span>
                                                    @elseif ($parent->expense_treatment === \App\Models\Category::TREATMENT_SAVING)
                                                        <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">{{ $parent->expenseTreatmentLabel() }}</span>
                                                    @else
                                                        <span class="inline-flex items-center rounded-full bg-rose-100 px-2 py-0.5 text-xs font-medium text-rose-700 dark:bg-rose-900/30 dark:text-rose-300">{{ $parent->expenseTreatmentLabel() }}</span>
                                                    @endif
                                                @endif
                                            </div>
                                            <a href="{{ route('transactions', ['scope' => 'all', 'category' => $parent->id]) }}" wire:navigate class="app-link mt-1 inline-flex rounded text-xs underline decoration-current/40 underline-offset-2 hover:decoration-current focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600" aria-label="View {{ $groupTransactions }} recorded {{ \Illuminate\Support\Str::plural('transaction', $groupTransactions) }} in {{ $parent->name }}{{ $parent->children->isNotEmpty() ? ' and its subcategories' : '' }}">{{ $groupTransactions }} {{ \Illuminate\Support\Str::plural('transaction', $groupTransactions) }} total <span aria-hidden="true" class="ml-1">↗</span></a>
                                        </div>
                                        <div class="flex shrink-0 gap-3 text-sm">
                                            <button type="button" wire:click="edit({{ $parent->id }})" class="app-link">Edit</button>
                                            <button type="button" wire:click="confirmDelete({{ $parent->id }})" class="font-medium text-rose-600 hover:text-rose-800 dark:text-rose-400">Delete</button>
                                        </div>
                                    </div>
                                    @if ($parent->children->isNotEmpty())
                                        <ul class="divide-y divide-app-border border-t border-app-border">
                                            @foreach ($parent->children as $sub)
                                                <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5">
                                                    <div class="min-w-0 flex-1 border-l-2 border-app-border pl-3">
                                                        <p class="break-words text-sm font-medium">{{ $sub->name }}</p>
                                                        <a href="{{ route('transactions', ['scope' => 'all', 'category' => $parent->id, 'subcategory' => $sub->id]) }}" wire:navigate class="app-link mt-0.5 inline-flex rounded text-xs underline decoration-current/40 underline-offset-2 hover:decoration-current focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600" aria-label="View {{ $sub->transactions_count }} recorded {{ \Illuminate\Support\Str::plural('transaction', $sub->transactions_count) }} in {{ $sub->name }}">{{ $sub->transactions_count }} {{ \Illuminate\Support\Str::plural('transaction', $sub->transactions_count) }} <span aria-hidden="true" class="ml-1">↗</span></a>
                                                    </div>
                                                    <div class="flex shrink-0 gap-3 text-sm">
                                                        <button type="button" wire:click="edit({{ $sub->id }})" class="app-link">Edit</button>
                                                        <button type="button" wire:click="confirmDelete({{ $sub->id }})" class="font-medium text-rose-600 hover:text-rose-800 dark:text-rose-400">Delete</button>
                                                    </div>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endforeach
        </div>
    </div>

    {{-- Category form modal --}}
    <flux:modal
        name="category-form"
        x-on:open-category-modal.window="$flux.modal('category-form').show()"
        x-on:close-category-modal.window="$flux.modal('category-form').close()"
        focusable
        class="min-w-0 w-[calc(100vw-2rem)] max-w-md"
    >
        <div class="space-y-5">
            <div><flux:heading size="lg">{{ $categoryId ? 'Edit category' : 'New category' }}</flux:heading><p class="mt-1 text-sm app-muted">Categories organise transactions and control how expenses appear in reports.</p></div>
            <form wire:submit.prevent="save" class="space-y-5">
                <div><label for="category-name" class="app-form-label">Name</label><input id="category-name" type="text" wire:model="name" placeholder="e.g., Groceries or Salary" class="app-field mt-1.5 w-full" autofocus />@error('name') <p class="app-form-error">{{ $message }}</p> @enderror</div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label for="category-type" class="app-form-label">Type</label><select id="category-type" wire:model.live="type" @disabled($editingStructureLocked) class="app-field mt-1.5 w-full disabled:opacity-60"><option value="expense">Money out</option><option value="income">Money in</option></select>@error('type') <p class="app-form-error">{{ $message }}</p> @enderror</div>
                    <div><label for="category-parent" class="app-form-label">Parent <span class="normal-case font-normal app-muted">(optional)</span></label><select id="category-parent" wire:model.live="parentId" @disabled($editingStructureLocked) class="app-field mt-1.5 w-full disabled:opacity-60"><option value="">Top-level category</option>@foreach ($parentOptions as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach</select>@error('parentId') <p class="app-form-error">{{ $message }}</p> @enderror</div>
                </div>
                @if ($editingStructureLocked)
                    <p class="rounded-xl bg-zinc-50 p-3 text-xs app-muted dark:bg-zinc-800">Type and parent are locked because this category is in use. You can still rename it.</p>
                @endif
                @if ($type === \App\Models\Category::TYPE_EXPENSE && $parentId === null)
                    <div><label for="category-treatment" class="app-form-label">How to report this expense</label><select id="category-treatment" wire:model.live="expenseTreatment" class="app-field mt-1.5 w-full"><option value="spending">Spending</option><option value="saving">Saving</option><option value="investment">Investment</option></select><p class="mt-1.5 text-xs app-muted">Spending counts as consumed money. Saving and investment appear separately in summaries.</p>@error('expenseTreatment') <p class="app-form-error">{{ $message }}</p> @enderror</div>
                    @if ($editingCategoryImpact && $editingCategoryImpact['treatment'] !== $expenseTreatment && $editingCategoryImpact['transactions'] > 0)
                        <p class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-900/20 dark:text-amber-200">This will reclassify {{ $editingCategoryImpact['transactions'] }} existing {{ \Illuminate\Support\Str::plural('transaction', $editingCategoryImpact['transactions']) }} in past and future reports.@if ($editingCategoryImpact['budgets'] > 0) Remove this category's budgets before making this change.@endif</p>
                    @endif
                @elseif ($type === \App\Models\Category::TYPE_EXPENSE)
                    <p class="rounded-xl bg-zinc-50 p-3 text-xs app-muted dark:bg-zinc-800">Subcategories inherit their parent’s reporting treatment.</p>
                @endif
                @error('save') <p class="app-form-error" role="alert">{{ $message }}</p> @enderror
                <div class="app-form-actions"><flux:modal.close><button type="button" class="app-button-secondary">Cancel</button></flux:modal.close><button type="submit" wire:loading.attr="disabled" wire:target="save" class="app-button-primary">{{ $categoryId ? 'Save changes' : 'Add category' }}</button></div>
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
                    <button type="button" class="app-button-secondary">
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
