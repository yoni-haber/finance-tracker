<?php

declare(strict_types=1);

namespace App\Livewire\Transactions;

use App\Livewire\Concerns\InteractsWithSelectedPeriod;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\TransactionReport;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Transactions')]
class TransactionManager extends Component
{
    use InteractsWithSelectedPeriod;
    use WithPagination;

    #[Url(as: 'search', except: '')]
    public string $search = '';

    public string $type = Transaction::TYPE_EXPENSE;

    public string $amount = '0.00';

    public string $date;

    public ?string $description = null;

    public ?int $category_id = null;

    public bool $is_recurring = false;

    public ?string $frequency = null;

    public ?string $recurring_until = null;

    public ?int $transactionId = null;

    public ?int $deletingTransactionId = null;

    public ?string $deletingOccurrenceDate = null;

    public string $deletingDescription = '';

    public bool $deletingIsRecurring = false;

    #[Url(as: 'category')]
    public ?int $filterParentCategory = null;

    #[Url(as: 'subcategory')]
    public ?int $filterSubCategory = null;

    #[Url(as: 'type')]
    public ?string $filterType = null;

    public function mount(): void
    {
        $this->date = $this->defaultTransactionDate();
        $this->normaliseFilterType();
    }

    public function render(): View
    {
        $userId = (int) Auth::id();

        // Form categories: only those matching the currently selected type, grouped by parent.
        $formCategories = Category::forUser($userId)
            ->where('type', $this->type)
            ->parents()
            ->with('children')
            ->orderBy('name')
            ->get();

        // Filter categories: all parent categories with children eager-loaded (type-independent).
        $filterCategories = Category::forUser($userId)
            ->parents()
            ->with('children')
            ->orderBy('name')
            ->get();

        // Subcategories for the drill-down dropdown (children of the selected parent).
        $filterSubCategories = collect();
        if ($this->filterParentCategory) {
            $parentCategory = $filterCategories->firstWhere('id', $this->filterParentCategory);
            $filterSubCategories = $parentCategory !== null ? $parentCategory->children : collect();
        }

        // Resolve effective filter: subcategory takes precedence; parent expands to include all children.
        $effectiveCategoryFilter = null;
        if ($this->filterSubCategory) {
            $effectiveCategoryFilter = $this->filterSubCategory;
        } elseif ($this->filterParentCategory) {
            $selected = $filterCategories->firstWhere('id', $this->filterParentCategory);
            $effectiveCategoryFilter = $selected
                ? $selected->children->pluck('id')->push($selected->id)->all()
                : $this->filterParentCategory;
        }

        $search = trim($this->search);
        $searching = $search !== '';

        if ($searching) {
            $pattern = '%' . $search . '%';
            $amount = $this->searchAmount($search);

            $transactions = Transaction::forUser($userId)
                ->forCategory($effectiveCategoryFilter)
                ->with('category.parent')
                ->when($this->filterType, fn (Builder $query) => $query->where('type', $this->filterType))
                ->where(function (Builder $query) use ($pattern, $amount): void {
                    $query->where('description', 'like', $pattern)
                        ->orWhereHas('category', function (Builder $category) use ($pattern): void {
                            $category->where('name', 'like', $pattern)
                                ->orWhereHas('parent', fn (Builder $parent) => $parent->where('name', 'like', $pattern));
                        });

                    if ($amount !== null) {
                        $query->orWhere('amount', $amount);
                    }
                })
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->paginate(20);
        } else {
            $transactions = TransactionReport::projectedForMonth($userId, $this->periodMonth, $this->periodYear, $effectiveCategoryFilter)
                ->when($this->filterType, fn ($items) => $items->where('type', $this->filterType))
                ->sortByDesc('date');
        }

        return view('livewire.transactions.manager', ['transactions' => $transactions, 'searching' => $searching, 'formCategories' => $formCategories, 'filterCategories' => $filterCategories, 'filterSubCategories' => $filterSubCategories]);
    }

    public function save(): void
    {
        $data = $this->validate($this->rules());
        $data['user_id'] = Auth::id();

        if (!$data['is_recurring']) {
            $data['frequency'] = null;
            $data['recurring_until'] = null;
        }

        if ($this->transactionId) {
            $transaction = Transaction::where('user_id', $data['user_id'])
                ->find($this->transactionId);

            if (!$transaction) {
                $this->addError('save', 'Transaction not found.');

                return;
            }

            $transaction->update($data);
        } else {
            Transaction::create($data);
        }

        $this->resetForm();
        $this->resetPage();
        session()->flash('status', 'Transaction saved successfully.');
        $this->dispatch('close-transaction-modal');
    }

    public function openModal(): void
    {
        $this->resetForm();
        $this->dispatch('open-transaction-modal');
    }

    public function edit(int $transactionId): void
    {
        $transaction = Transaction::forUser((int) Auth::id())->findOrFail($transactionId);

        $this->transactionId = $transaction->id;
        $this->type = $transaction->type;
        $this->amount = (string) $transaction->amount;
        $this->date = $transaction->date->toDateString();
        $this->description = $transaction->description;
        $this->category_id = $transaction->category_id;
        $this->is_recurring = $transaction->is_recurring;
        $this->frequency = $transaction->frequency;
        $this->recurring_until = $transaction->recurring_until?->toDateString();

        $this->dispatch('open-transaction-modal');
    }

    public function confirmDelete(int $transactionId, ?string $occurrenceDate = null): void
    {
        $transaction = Transaction::forUser((int) Auth::id())->findOrFail($transactionId);

        $this->deletingTransactionId = $transaction->id;
        $this->deletingOccurrenceDate = $occurrenceDate;
        $this->deletingDescription = $transaction->description ?: 'this transaction';
        $this->deletingIsRecurring = $transaction->is_recurring;
        $this->dispatch('open-delete-transaction-modal');
    }

    public function delete(bool $entireSeries = false): void
    {
        if (!$this->deletingTransactionId) {
            return;
        }

        $transaction = Transaction::forUser((int) Auth::id())->findOrFail($this->deletingTransactionId);

        if ($transaction->is_recurring) {
            if ($entireSeries) {
                $transaction->delete();
                session()->flash('status', 'Recurring transaction series removed.');
                $this->finishDelete();

                return;
            }

            if ($this->deletingOccurrenceDate === null) {
                $this->addError('delete', 'An occurrence date is required.');

                return;
            }

            try {
                $parsedDate = Carbon::createFromFormat('Y-m-d', $this->deletingOccurrenceDate, config('app.timezone'));
                assert($parsedDate instanceof Carbon);
            } catch (InvalidFormatException) {
                $this->addError('delete', 'Invalid occurrence date.');

                return;
            }

            $transaction->occurrenceExceptions()->firstOrCreate(['date' => $parsedDate->toDateString()]);
            session()->flash('status', 'Transaction occurrence removed.');
            $this->finishDelete();

            return;
        }

        $transaction->delete();
        session()->flash('status', 'Transaction removed.');
        $this->finishDelete();
    }

    private function finishDelete(): void
    {
        $this->deletingTransactionId = null;
        $this->deletingOccurrenceDate = null;
        $this->deletingDescription = '';
        $this->deletingIsRecurring = false;
        $this->resetPage();
        $this->dispatch('close-delete-transaction-modal');
    }

    /** Clear the selected category when the transaction type changes. */
    public function updatedType(): void
    {
        $this->category_id = null;
    }

    /** Reset the subcategory drill-down whenever the parent category changes. */
    public function updatedFilterParentCategory(): void
    {
        $this->filterSubCategory = null;
        $this->resetPage();
    }

    public function updatedFilterSubCategory(): void
    {
        $this->resetPage();
    }

    public function updatedFilterType(): void
    {
        $this->normaliseFilterType();
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    public function updatedIsRecurring(bool $value): void
    {
        if (!$value) {
            $this->frequency = null;
            $this->recurring_until = null;

            return;
        }

        if (!$this->frequency) {
            $this->frequency = 'monthly';
        }
    }

    public function resetForm(): void
    {
        $this->transactionId = null;
        $this->type = Transaction::TYPE_EXPENSE;
        $this->amount = '0.00';
        $this->date = $this->defaultTransactionDate();
        $this->description = null;
        $this->category_id = null;
        $this->is_recurring = false;
        $this->frequency = null;
        $this->recurring_until = null;

        $this->resetValidation();
        $this->resetErrorBag();
    }

    /**
     * Default a new transaction's date to today when viewing the current month,
     * otherwise to the first day of the selected period.
     */
    private function defaultTransactionDate(): string
    {
        $selectedPeriod = $this->selectedPeriod();

        return $selectedPeriod->isCurrentMonth()
            ? now()->toDateString()
            : $selectedPeriod->startOfMonth()->toDateString();
    }

    private function normaliseFilterType(): void
    {
        if (
            $this->filterType !== null
            && !in_array($this->filterType, [Transaction::TYPE_INCOME, Transaction::TYPE_EXPENSE], true)
        ) {
            $this->filterType = null;
        }
    }

    private function searchAmount(string $search): ?string
    {
        if (!preg_match('/^£?\s*(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{1,2})?$/u', $search)) {
            return null;
        }

        [$pounds, $pence] = array_pad(explode('.', str_replace([',', '£', ' '], '', $search)), 2, '');

        return $pounds . '.' . str_pad($pence, 2, '0');
    }

    /**
     * @return array<string, string[]|Exists[]|string[]>
     */
    protected function rules(): array
    {
        return [
            'type' => ['required', 'in:income,expense'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:500'],
            'category_id' => [
                'nullable',
                Rule::exists('categories', 'id')
                    ->where('user_id', Auth::id())
                    ->where('type', $this->type),
            ],
            'is_recurring' => ['boolean'],
            'frequency' => ['nullable', 'required_if:is_recurring,true', 'in:weekly,monthly,yearly'],
            'recurring_until' => ['nullable', 'date', 'after_or_equal:date'],
        ];
    }
}
