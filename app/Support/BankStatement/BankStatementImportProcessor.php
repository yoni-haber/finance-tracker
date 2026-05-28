<?php

declare(strict_types=1);

namespace App\Support\BankStatement;

use App\Models\BankStatementImport;
use App\Support\BankStatementConfig;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

readonly class BankStatementImportProcessor
{
    public function __construct(
        private BankStatementImport $bankStatementImport,
    ) {}

    /**
     * Process the bank statement import
     */
    public function process(): bool
    {
        if ($this->bankStatementImport->isParsed() || $this->bankStatementImport->isCommitted()) {
            return true;
        }

        // Atomically claim the import by transitioning to parsing.
        // Only STATUS_UPLOADED and STATUS_FAILED are claimable — STATUS_PARSING means
        // another worker already holds the claim, and we must not proceed concurrently.
        // STATUS_FAILED is included so a re-dispatched job can recover after total failure.
        $claimed = BankStatementImport::where('id', $this->bankStatementImport->id)
            ->whereIn('status', [BankStatementConfig::STATUS_UPLOADED, BankStatementConfig::STATUS_FAILED])
            ->update(['status' => BankStatementConfig::STATUS_PARSING]);

        $this->bankStatementImport->refresh();

        if (!$claimed) {
            // Another worker already claimed it, or it's in a non-processable state.
            if ($this->bankStatementImport->isParsed()) {
                return true;
            }

            return $this->bankStatementImport->isCommitted();
        }

        $filePath = Storage::disk('local')->path(sprintf('statements/%s.csv', $this->bankStatementImport->id));

        if (!$this->bankStatementImport->bankProfile) {
            logger()->error('Bank statement parsing failed', [
                'import_id' => $this->bankStatementImport->id,
                'error' => 'Bank profile is required for parsing',
            ]);
            $this->bankStatementImport->update(['status' => BankStatementConfig::STATUS_FAILED]);

            return false;
        }

        // Step 1: Read CSV file
        $csvFileReader = new CsvFileReader($filePath, $this->bankStatementImport->bankProfile);
        try {
            $rows = $csvFileReader->readRows();
        } catch (Exception $exception) {
            logger()->error('Bank statement parsing failed', [
                'import_id' => $this->bankStatementImport->id,
                'error' => 'CSV file not found - '.$exception->getMessage(),
            ]);
            $this->bankStatementImport->update(['status' => BankStatementConfig::STATUS_FAILED]);

            return false;
        }

        // Step 2: Parse rows into transactions
        $transactionRowParser = new TransactionRowParser($this->bankStatementImport->bankProfile);
        $transactions = $this->parseRows($rows->all(), $transactionRowParser);

        // Step 3: Add hashes and detect duplicates
        $duplicateDetector = new DuplicateDetector($this->bankStatementImport->user_id);

        /** @var Collection<int, array{date: Carbon, description: string, amount: float, external_id: null, hash: string, is_duplicate: bool}> $transactions */
        $transactions = collect($duplicateDetector->detectDuplicates($transactions->all()));

        // Step 4: Save imported transactions and mark parsed — both in one transaction
        // so a crash between the two operations cannot leave the import in an inconsistent state.
        $this->saveImportedTransactions($transactions);

        return true;
    }

    /**
     * Parse CSV rows into transaction data
     *
     * @param array<int, array<int, string|null>> $rows
     * @return Collection<int, array{date: Carbon, description: string, amount: float, external_id: null}>
     */
    private function parseRows(array $rows, TransactionRowParser $transactionRowParser): Collection
    {
        return collect($rows)->map(function (array $row) use ($transactionRowParser): ?array {
            try {
                return $transactionRowParser->parseRow($row);
            } catch (Exception $exception) {
                logger()->warning('Failed to parse CSV row', [
                    'import_id' => $this->bankStatementImport->id,
                    'row' => $row,
                    'error' => $exception->getMessage(),
                ]);

                return null;
            }
        })->filter();
    }

    /**
     * Save imported transactions to database and mark the import as parsed,
     * all within a single transaction so the two operations are atomic.
     * Any existing rows are deleted first so re-processing after STATUS_FAILED
     * cannot produce duplicate staged transactions.
     *
     * @param Collection<int, array{date: Carbon, description: string, amount: float, external_id: null, hash: string, is_duplicate: bool}> $transactions
     *
     * @throws Throwable
     */
    private function saveImportedTransactions(Collection $transactions): void
    {
        DB::transaction(function () use ($transactions): void {
            // Clear any rows from a previous failed attempt before re-inserting.
            $this->bankStatementImport->importedTransactions()->delete();

            $transactions->chunk(BankStatementConfig::TRANSACTION_CHUNK_SIZE)
                ->each(function ($chunk): void {
                    $data = $chunk->map(fn ($transaction): array => [
                        'import_id' => $this->bankStatementImport->id,
                        'date' => $transaction['date'],
                        'description' => $transaction['description'],
                        'amount' => $transaction['amount'],
                        'hash' => $transaction['hash'],
                        'original_hash' => $transaction['hash'],
                        'is_duplicate' => $transaction['is_duplicate'],
                        'external_id' => $transaction['external_id'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->toArray();

                    $this->bankStatementImport->importedTransactions()->insert($data);
                });

            $this->bankStatementImport->update(['status' => BankStatementConfig::STATUS_PARSED]);
        });
    }
}
