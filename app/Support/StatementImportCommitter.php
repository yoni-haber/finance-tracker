<?php

declare(strict_types=1);

namespace App\Support;

use App\Jobs\DeleteStatementFileJob;
use App\Models\BankStatementImport;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\BankStatement\StatementFileCleaner;
use Exception;
use Illuminate\Support\Facades\DB;
use RuntimeException;

readonly class StatementImportCommitter
{
    public function __construct(
        private BankStatementImport $bankStatementImport,
        private bool $selectAllTransactions = true,
        /** @var list<int> */
        private array $selectionExceptionIds = [],
    ) {}

    /**
     * Commit imported transactions to create real Transaction records
     */
    public function commit(): bool
    {
        $this->bankStatementImport->refresh(); // Refresh to get latest status

        // Skip if already committed (idempotency)
        if ($this->bankStatementImport->isCommitted()) {
            return true;
        }

        if (!$this->bankStatementImport->isParsed()) {
            return false;
        }

        try {
            DB::transaction(function (): void {
                $importedTransactionsQuery = $this->bankStatementImport->importedTransactions()->committable();
                $exceptionIds = array_values(array_unique(array_map(intval(...), $this->selectionExceptionIds)));
                if ($exceptionIds !== []) {
                    $this->selectAllTransactions
                        ? $importedTransactionsQuery->whereNotIn('id', $exceptionIds)
                        : $importedTransactionsQuery->whereIn('id', $exceptionIds);
                } elseif (!$this->selectAllTransactions) {
                    $importedTransactionsQuery->whereRaw('1 = 0');
                }

                $importedTransactions = $importedTransactionsQuery->lockForUpdate()->get();

                foreach ($importedTransactions as $importedTransaction) {
                    // For credit cards, parser has already flipped signs for business logic,
                    // but Transaction table should store positive amounts for expenses
                    $amount = (float) $importedTransaction->amount;

                    if ($this->bankStatementImport->isCreditCardStatement()) {
                        // Credit card: flip back to positive amounts, determine type from original CSV logic
                        $isExpense = $amount < 0; // Negative imported amount = expense
                        $amount = abs($amount); // Store positive amount
                        $type = $isExpense ? Transaction::TYPE_EXPENSE : Transaction::TYPE_INCOME;
                    } else {
                        // Bank statement: amounts are as-is
                        $type = $amount >= 0 ? Transaction::TYPE_INCOME : Transaction::TYPE_EXPENSE;
                        $amount = abs($amount); // Ensure positive amounts for consistency
                    }

                    if ($importedTransaction->category_id !== null) {
                        $categoryIsValid = Category::whereKey($importedTransaction->category_id)
                            ->where('user_id', $this->bankStatementImport->user_id)
                            ->where('type', $type)
                            ->exists();

                        if (!$categoryIsValid) {
                            throw new RuntimeException(sprintf('Transaction %d has an invalid category.', $importedTransaction->id));
                        }
                    }

                    // Create real transaction
                    Transaction::create([
                        'user_id' => $this->bankStatementImport->user_id,
                        'date' => $importedTransaction->date,
                        'description' => $importedTransaction->description,
                        'amount' => $amount,
                        'type' => $type,
                        'category_id' => $importedTransaction->category_id,
                        'is_recurring' => false,
                        'frequency' => null,
                        'recurring_until' => null,
                        // Use original_hash so re-uploading the same CSV detects the duplicate
                        // even if the user edited the description before committing.
                        'hash' => $importedTransaction->original_hash ?? $importedTransaction->hash,
                    ]);

                    // Mark imported transaction as committed
                    $importedTransaction->update(['is_committed' => true]);
                }

                // Update import status
                $this->bankStatementImport->update(['status' => BankStatementConfig::STATUS_COMMITTED]);

            });

            try {
                app(StatementFileCleaner::class)->delete($this->bankStatementImport);
            } catch (Exception $exception) {
                logger()->warning('Statement file cleanup queued for retry', [
                    'import_id' => $this->bankStatementImport->id,
                    'error' => $exception->getMessage(),
                ]);
                try {
                    DeleteStatementFileJob::dispatch($this->bankStatementImport->id);
                } catch (Exception $dispatchException) {
                    logger()->error('Unable to dispatch statement file cleanup retry', [
                        'import_id' => $this->bankStatementImport->id,
                        'error' => $dispatchException->getMessage(),
                    ]);
                }
            }

            return true;
        } catch (Exception $exception) {
            logger()->error('Failed to commit statement import', [
                'import_id' => $this->bankStatementImport->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get summary statistics for the import
     *
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        $transactions = $this->bankStatementImport->importedTransactions;

        return [
            'total' => $transactions->count(),
            'duplicates' => $transactions->where('is_duplicate', true)->count(),
            'new_transactions' => $transactions->where('is_duplicate', false)->count(),
            'total_amount' => $transactions->where('is_duplicate', false)->sum('amount'),
            'committed' => $transactions->where('is_committed', true)->count(),
        ];
    }
}
