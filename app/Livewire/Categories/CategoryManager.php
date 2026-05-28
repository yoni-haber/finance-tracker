<?php

declare(strict_types=1);

namespace App\Livewire\Categories;

use App\Models\Category;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Categories')]
class CategoryManager extends Component
{
    public string $name = '';

    public string $type = Category::TYPE_EXPENSE;

    public ?int $parentId = null;

    public ?int $categoryId = null;

    public ?int $deletingId = null;

    public string $deletingName = '';

    public bool $deletingHasChildren = false;

    public function render(): View
    {
        $userId = (int) Auth::id();

        $incomeParents = Category::forUser($userId)
            ->income()
            ->parents()
            ->withCount('transactions')
            ->with(['children' => fn ($q) => $q->withCount('transactions')])
            ->orderBy('name')
            ->get();

        $expenseParents = Category::forUser($userId)
            ->expense()
            ->parents()
            ->withCount('transactions')
            ->with(['children' => fn ($q) => $q->withCount('transactions')])
            ->orderBy('name')
            ->get();

        // Available parents for the form's parent selector (filtered by selected type).
        $parentOptions = Category::forUser($userId)
            ->where('type', $this->type)
            ->parents()
            ->orderBy('name')
            ->get();

        return view('livewire.categories.manager', ['incomeParents' => $incomeParents, 'expenseParents' => $expenseParents, 'parentOptions' => $parentOptions]);
    }

    /**
     * @return array<string, string[]|In[]|string[]>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in([Category::TYPE_INCOME, Category::TYPE_EXPENSE])],
            'parentId' => ['nullable', 'integer'],
        ];
    }

    public function save(): void
    {
        $this->validate($this->rules());

        $userId = (int) Auth::id();

        // Validate parent: must be own expense/income parent (no parent itself).
        if ($this->parentId !== null) {
            $parent = Category::forUser($userId)
                ->parents()
                ->where('type', $this->type)
                ->find($this->parentId);

            if (!$parent) {
                $this->addError('parentId', 'Invalid parent category.');

                return;
            }
        }

        // Enforce uniqueness in PHP (MySQL NULLs are always distinct in unique indexes).
        $nameExists = Category::forUser($userId)
            ->where('name', $this->name)
            ->where('type', $this->type)
            ->where('parent_id', $this->parentId)
            ->when($this->categoryId, fn ($q) => $q->where('id', '!=', $this->categoryId))
            ->exists();

        if ($nameExists) {
            $this->addError('name', 'A category with this name already exists.');

            return;
        }

        $data = [
            'user_id' => $userId,
            'name' => $this->name,
            'type' => $this->type,
            'parent_id' => $this->parentId,
        ];

        if ($this->categoryId) {
            $category = Category::forUser($userId)->find($this->categoryId);

            if (!$category) {
                $this->addError('save', 'Category not found.');

                return;
            }

            $category->update($data);
        } else {
            Category::create($data);
        }

        $this->resetForm();
        session()->flash('status', 'Category saved.');
        $this->dispatch('close-category-modal');
    }

    public function openModal(): void
    {
        $this->resetForm();
        $this->dispatch('open-category-modal');
    }

    public function edit(int $categoryId): void
    {
        $category = Category::forUser((int) Auth::id())->findOrFail($categoryId);

        $this->categoryId = $category->id;
        $this->name = $category->name;
        $this->type = $category->type;
        $this->parentId = $category->parent_id;

        $this->dispatch('open-category-modal');
    }

    public function confirmDelete(int $categoryId): void
    {
        $category = Category::forUser((int) Auth::id())
            ->with('children')
            ->find($categoryId);

        if (!$category) {
            return;
        }

        if ($category->hasTransactions()) {
            session()->flash('status', 'Cannot delete — category has transactions. Rename it instead.');

            return;
        }

        if ($category->hasBudgets()) {
            session()->flash('status', 'Cannot delete — category has budgets. Remove the budgets first.');

            return;
        }

        $this->deletingId = $category->id;
        $this->deletingName = $category->name;
        $this->deletingHasChildren = $category->children->isNotEmpty();

        $this->dispatch('open-delete-category-modal');
    }

    public function delete(): void
    {
        if (!$this->deletingId) {
            return;
        }

        $category = Category::forUser((int) Auth::id())
            ->with('children')
            ->find($this->deletingId);

        $this->deletingId = null;

        if (!$category) {
            $this->dispatch('close-delete-category-modal');

            return;
        }

        // Re-check guards in case state changed since the confirmation was shown.
        if ($category->hasTransactions()) {
            session()->flash('status', 'Cannot delete — category has transactions. Rename it instead.');
            $this->dispatch('close-delete-category-modal');

            return;
        }

        if ($category->hasBudgets()) {
            session()->flash('status', 'Cannot delete — category has budgets. Remove the budgets first.');
            $this->dispatch('close-delete-category-modal');

            return;
        }

        $category->children->each->delete();
        $category->delete();

        session()->flash('status', 'Category removed.');
        $this->dispatch('close-delete-category-modal');
    }

    /** Clear the parent selector when the type changes in the form. */
    public function updatedType(): void
    {
        $this->parentId = null;
    }

    public function resetForm(): void
    {
        $this->categoryId = null;
        $this->name = '';
        $this->type = Category::TYPE_EXPENSE;
        $this->parentId = null;

        $this->resetValidation();
        $this->resetErrorBag();
    }
}
