<?php

declare(strict_types=1);

namespace App\Livewire\Statements;

use App\Models\BankStatementImport;
use App\Models\Category;
use App\Models\ImportedTransaction;
use App\Models\Transaction;
use App\Support\BankStatement\DuplicateDetector;
use App\Support\StatementImportCommitter;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Review Import')]
class StatementImportReview extends Component
{
    public BankStatementImport $import;

    public ?int $editingTransactionId = null;

    public ?int $deletingTransactionId = null;

    /**
     * @var array{
     *     description?: string,
     *     amount?: string,
     *     date?: string,
     *     type?: string,
     *     category_id?: int|null
     * }
     */
    public array $editForm = [];

    public function mount(int $importId): void
    {
        try {
            $this->import = BankStatementImport::with(['bankProfile'])
                ->forUser((int) Auth::id())
                ->findOrFail($importId);
        } catch (ModelNotFoundException) {
            $this->redirectRoute('statements.import');

            return;
        }

        if (!$this->import->isParsed()) {
            session()->flash('error', 'Import is not ready for review.');
            $this->redirectRoute('statements.import', navigate: true);
        }
    }

    public function render(): View
    {
        $transactions = $this->import->importedTransactions()
            ->orderBy('date', 'desc')
            ->orderBy('created_at')
            ->get();

        $summary = [
            'total' => $transactions->count(),
            'duplicates' => $transactions->where('is_duplicate', true)->count(),
            'new_transactions' => $transactions->where('is_duplicate', false)->count(),
            'total_amount' => $transactions->where('is_duplicate', false)->sum('amount'),
        ];

        return view('livewire.statements.import-review', [
            'transactions' => $transactions,
            'summary' => $summary,
            'categories' => Category::forUser((int) Auth::id())
                ->parents()
                ->with('children')
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'editForm.description' => 'required|string|max:500',
            'editForm.amount' => 'required|numeric|min:0.01',
            'editForm.date' => 'required|date',
            'editForm.type' => 'required|in:income,expense',
            'editForm.category_id' => [
                'nullable',
                Rule::exists('categories', 'id')->where('user_id', Auth::id()),
                function ($attribute, $value, $fail): void {
                    if ($value) {
                        /** @var Category|null $category */
                        $category = Category::find($value);
                        if ($category && $category->type !== ($this->editForm['type'] ?? null)) {
                            $fail(sprintf('This category is for %s transactions.', $category->type));
                        }
                    }
                },
            ],
        ];
    }

    public function editTransaction(int $transactionId): void
    {
        /** @var ImportedTransaction $importedTransaction */
        $importedTransaction = $this->import->importedTransactions()->findOrFail($transactionId);

        $this->editingTransactionId = $transactionId;
        $this->editForm = [
            'description' => $importedTransaction->description,
            'amount' => (string) abs((float) $importedTransaction->amount),
            'date' => $importedTransaction->date->toDateString(),
            'type' => $this->determineTransactionType($importedTransaction),
            'category_id' => $importedTransaction->category_id,
        ];
    }

    public function updateTransaction(): void
    {
        $this->validate();

        /** @var ImportedTransaction $importedTransaction */
        $importedTransaction = $this->import->importedTransactions()->findOrFail($this->editingTransactionId);

        $normalizedDescription = Str::squish(Str::upper($this->editForm['description'] ?? ''));

        $amount = ($this->editForm['type'] ?? '') === Transaction::TYPE_EXPENSE
            ? -abs((float) ($this->editForm['amount'] ?? 0))
            : abs((float) ($this->editForm['amount'] ?? 0));

        $importedTransaction->update([
            'description' => $normalizedDescription,
            'amount' => $amount,
            'date' => $this->editForm['date'] ?? '',
            'category_id' => $this->editForm['category_id'] ?? null,
        ]);

        // Regenerate hash using the saved (normalised) values so it matches future imports
        $duplicateDetector = new DuplicateDetector($this->import->user_id);
        $hash = $duplicateDetector->generateTransactionHash(
            $this->import->user_id,
            $this->editForm['date'] ?? '',
            $amount,
            $normalizedDescription,
        );
        $isDuplicate = $duplicateDetector->isDuplicateExcluding($hash, $importedTransaction->id);
        $importedTransaction->update([
            'hash' => $hash,
            'is_duplicate' => $isDuplicate,
        ]);

        $this->cancelEdit();
        session()->flash('status', 'Transaction updated successfully.');
    }

    public function updateCategory(int $transactionId, ?int $categoryId): void
    {
        if ($categoryId !== null) {
            $category = Category::where('id', $categoryId)->where('user_id', Auth::id())->first();

            if (!$category) {
                throw ValidationException::withMessages([
                    'categoryId' => 'The selected category is invalid.',
                ]);
            }

            /** @var ImportedTransaction $transaction */
            $transaction = $this->import->importedTransactions()->findOrFail($transactionId);
            $transactionType = $this->determineTransactionType($transaction);

            if ($category->type !== $transactionType) {
                throw ValidationException::withMessages([
                    'categoryId' => sprintf('This category is for %s transactions, but this is a %s transaction.', $category->type, $transactionType),
                ]);
            }

            $transaction->update(['category_id' => $categoryId]);

            return;
        }

        /** @var ImportedTransaction $transaction */
        $transaction = $this->import->importedTransactions()->findOrFail($transactionId);
        $transaction->update(['category_id' => null]);
    }

    public function updateType(int $transactionId, string $type): void
    {
        /** @var ImportedTransaction $importedTransaction */
        $importedTransaction = $this->import->importedTransactions()->findOrFail($transactionId);

        $amount = $type === Transaction::TYPE_EXPENSE
            ? -abs((float) $importedTransaction->amount)
            : abs((float) $importedTransaction->amount);

        $importedTransaction->update(['amount' => $amount]);

        // Regenerate hash using the explicit $amount var, not the post-update model attribute
        $duplicateDetector = new DuplicateDetector($this->import->user_id);
        $hash = $duplicateDetector->generateTransactionHash(
            $this->import->user_id,
            $importedTransaction->date,
            $amount,
            $importedTransaction->description,
        );
        $isDuplicate = $duplicateDetector->isDuplicateExcluding($hash, $importedTransaction->id);
        $importedTransaction->update([
            'hash' => $hash,
            'is_duplicate' => $isDuplicate,
        ]);
    }

    public function confirmDeleteTransaction(int $transactionId): void
    {
        $this->deletingTransactionId = $transactionId;
        $this->dispatch('open-delete-modal');
    }

    public function deleteTransaction(): void
    {
        if ($this->deletingTransactionId) {
            $this->import->importedTransactions()->findOrFail($this->deletingTransactionId)->delete();
            $this->deletingTransactionId = null;
            session()->flash('status', 'Transaction removed from import.');
        }

        $this->dispatch('close-delete-modal');
    }

    private function determineTransactionType(ImportedTransaction $importedTransaction): string
    {
        if ($this->import->isCreditCardStatement()) {
            return $importedTransaction->amount < 0 ? Transaction::TYPE_EXPENSE : Transaction::TYPE_INCOME;
        }

        return $importedTransaction->amount >= 0 ? Transaction::TYPE_INCOME : Transaction::TYPE_EXPENSE;
    }

    public function commitImport(): void
    {
        if (!$this->import->isParsed()) {
            $this->addError('commit', 'Import is not ready to be committed.');

            return;
        }

        try {
            $statementImportCommitter = new StatementImportCommitter($this->import);
            $success = $statementImportCommitter->commit();

            if ($success) {
                session()->flash('status', 'Transactions imported successfully.');

                $this->redirectRoute('statements.import');
            } else {
                $this->addError('commit', 'Failed to import transactions. Please try again.');
            }
        } catch (Exception $exception) {
            logger()->error('Failed to commit import', [
                'import_id' => $this->import->id,
                'error' => $exception->getMessage(),
            ]);

            $this->addError('commit', 'Failed to import transactions. Please try again.');
        }
    }

    public function backToImport(): void
    {
        $this->redirectRoute('statements.import');
    }

    public function cancelEdit(): void
    {
        $this->editingTransactionId = null;
        $this->editForm = [];
        $this->resetValidation();
    }
}
