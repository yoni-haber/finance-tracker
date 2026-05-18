<?php

namespace App\Livewire\Transactions;

use App\Models\Category;
use App\Models\Transaction;
use App\Support\TransactionReport;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Transactions')]
class TransactionManager extends Component
{
    public string $type = Transaction::TYPE_EXPENSE;

    public string $amount = '0.00';

    public string $date;

    public ?string $description = null;

    public ?int $category_id = null;

    public bool $is_recurring = false;

    public ?string $frequency = null;

    public ?string $recurring_until = null;

    public ?int $transactionId = null;

    public int $month;

    public int $year;

    public ?int $filterParentCategory = null;

    public ?int $filterSubCategory = null;

    public ?string $filterType = null;

    public function mount(): void
    {
        $now = now();
        $this->date = $now->toDateString();
        $this->month = $now->month;
        $this->year = $now->year;
    }

    public function render(): View
    {
        $userId = Auth::id();

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

        $transactions = TransactionReport::projectedForMonth($userId, $this->month, $this->year, $effectiveCategoryFilter)
            ->when($this->filterType, fn ($items) => $items->where('type', $this->filterType))
            ->sortByDesc('date');

        return view('livewire.transactions.manager', ['transactions' => $transactions, 'formCategories' => $formCategories, 'filterCategories' => $filterCategories, 'filterSubCategories' => $filterSubCategories]);
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
        $transaction = Transaction::forUser(Auth::id())->findOrFail($transactionId);

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

    public function delete(int $transactionId, ?string $occurrenceDate = null): void
    {
        $transaction = Transaction::forUser(Auth::id())->findOrFail($transactionId);

        if ($transaction->is_recurring) {
            if ($occurrenceDate === null) {
                $transaction->delete();
                session()->flash('status', 'Transaction removed.');

                return;
            }

            try {
                $parsedDate = Carbon::createFromFormat('Y-m-d', $occurrenceDate, config('app.timezone'));
            } catch (InvalidFormatException) {
                $this->addError('delete', 'Invalid occurrence date.');

                return;
            }

            $transaction->occurrenceExceptions()->firstOrCreate(['date' => $parsedDate->toDateString()]);
            session()->flash('status', 'Transaction occurrence removed.');

            return;
        }

        $transaction->delete();
        session()->flash('status', 'Transaction removed.');
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
        $this->date = now()->toDateString();
        $this->description = null;
        $this->category_id = null;
        $this->is_recurring = false;
        $this->frequency = null;
        $this->recurring_until = null;

        $this->resetValidation();
        $this->resetErrorBag();
    }

    /**
     * @return array<string, string[]|\Illuminate\Validation\Rules\Exists[]|string[]>
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
