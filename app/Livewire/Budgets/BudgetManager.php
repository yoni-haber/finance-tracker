<?php

namespace App\Livewire\Budgets;

use App\Models\Budget;
use App\Models\Category;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Budgets')]
class BudgetManager extends Component
{
    public ?int $category_id = null;

    public int $month;

    public int $year;

    public string $amount = '0.00';

    public ?int $budgetId = null;

    public ?int $filterCategory = null;

    public int $filterMonth;

    public int $filterYear;

    public function mount(): void
    {
        $now = now();
        $this->month = $now->month;
        $this->year = $now->year;
        $this->filterMonth = $now->month;
        $this->filterYear = $now->year;
    }

    public function render(): View
    {
        $userId = Auth::id();

        $budgets = Budget::with('category')
            ->where('user_id', $userId)
            ->when($this->filterCategory, fn ($query) => $query->where('category_id', $this->filterCategory))
            ->when($this->filterMonth, fn ($query) => $query->where('month', $this->filterMonth))
            ->when($this->filterYear, fn ($query) => $query->where('year', $this->filterYear))
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get();

        // Budgets may only be set on expense parent categories.
        $categories = Category::forUser($userId)
            ->expense()
            ->parents()
            ->orderBy('name')
            ->get();

        return view('livewire.budgets.manager', ['budgets' => $budgets, 'categories' => $categories]);
    }

    public function save(): void
    {
        $data = $this->validate($this->rules());
        $data['user_id'] = Auth::id();

        if ($this->budgetExists($data)) {
            $this->addError('save', 'A budget for this category, month, and year already exists.');

            return;
        }

        if ($this->budgetId) {
            $budget = Budget::where('user_id', $data['user_id'])->find($this->budgetId);

            if (!$budget) {
                $this->addError('save', 'Budget not found.');

                return;
            }

            $budget->update($data);
        } else {
            Budget::create($data);
        }

        $this->resetForm();
        session()->flash('status', 'Budget saved.');
        $this->dispatch('close-budget-modal');
    }

    public function openModal(): void
    {
        $this->resetForm();
        $this->month = $this->filterMonth;
        $this->year = $this->filterYear;
        $this->dispatch('open-budget-modal');
    }

    public function edit(int $budgetId): void
    {
        $budget = Budget::where('user_id', Auth::id())->findOrFail($budgetId);
        $this->budgetId = $budget->id;
        $this->category_id = $budget->category_id;
        $this->month = $budget->month;
        $this->year = $budget->year;
        $this->amount = (string) $budget->amount;

        $this->dispatch('open-budget-modal');
    }

    public function delete(int $budgetId): void
    {
        Budget::where('user_id', Auth::id())->where('id', $budgetId)->delete();
        session()->flash('status', 'Budget removed.');
    }

    public function copyFromPreviousMonth(): void
    {
        $userId = Auth::id();

        // Compute source month/year (one month before filter)
        $sourceDate = Carbon::create($this->filterYear, $this->filterMonth, 1)->subMonth();
        $sourceMonth = (int) $sourceDate->month;
        $sourceYear = (int) $sourceDate->year;

        $sourceBudgets = Budget::where('user_id', $userId)
            ->where('month', $sourceMonth)
            ->where('year', $sourceYear)
            ->get();

        if ($sourceBudgets->isEmpty()) {
            session()->flash('copy_status', 'No budgets found for '.
                $sourceDate->format('F Y').
                ' to copy.',
            );

            return;
        }

        // Category IDs that already have a budget in the target month
        $existingCategoryIds = Budget::where('user_id', $userId)
            ->where('month', $this->filterMonth)
            ->where('year', $this->filterYear)
            ->pluck('category_id')
            ->all();

        $copied = 0;
        $skipped = 0;

        foreach ($sourceBudgets as $sourceBudget) {
            if (in_array($sourceBudget->category_id, $existingCategoryIds)) {
                $skipped++;

                continue;
            }

            Budget::create([
                'user_id' => $userId,
                'category_id' => $sourceBudget->category_id,
                'month' => $this->filterMonth,
                'year' => $this->filterYear,
                'amount' => $sourceBudget->amount,
            ]);

            $copied++;
        }

        $targetLabel = Carbon::create($this->filterYear, $this->filterMonth, 1)->format('F Y');
        $sourceLabel = $sourceDate->format('F Y');

        $message = sprintf('Copied %d budget(s) from %s to %s.', $copied, $sourceLabel, $targetLabel);
        if ($skipped > 0) {
            $message .= sprintf(' %d skipped (already existed).', $skipped);
        }

        session()->flash('copy_status', $message);
    }

    public function resetForm(): void
    {
        $this->budgetId = null;
        $this->category_id = null;
        $this->amount = '0.00';

        $this->resetValidation();
        $this->resetErrorBag();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function budgetExists(array $data): bool
    {
        return Budget::where('user_id', $data['user_id'])
            ->where('category_id', $data['category_id'])
            ->where('month', $data['month'])
            ->where('year', $data['year'])
            ->when($this->budgetId, fn ($query) => $query->where('id', '!=', $this->budgetId))
            ->exists();
    }

    /**
     * @return array<string, \Illuminate\Validation\Rules\Exists[]|string[]|string[]>
     */
    protected function rules(): array
    {
        return [
            'category_id' => [
                'required',
                Rule::exists('categories', 'id')
                    ->where('user_id', Auth::id())
                    ->where('type', 'expense')
                    ->whereNull('parent_id'),
            ],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'amount' => ['required', 'numeric', 'min:0'],
        ];
    }
}
