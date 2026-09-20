<?php

declare(strict_types=1);

namespace App\Support\BankStatement;

use App\Models\ImportedTransaction;
use App\Models\Transaction;
use App\Support\BankStatementConfig;
use Carbon\Carbon;

readonly class DuplicateDetector
{
    public function __construct(
        private int $userId,
    ) {}

    /**
     * Detect duplicates in one batch. The caller keeps $seenHashes across batches,
     * which catches repeated rows in the same file without per-row database queries.
     *
     * @param list<array{date: Carbon|string, description: string, amount: float, external_id?: string|null}> $transactions
     * @param array<string, true> $seenHashes
     * @return list<array{date: Carbon|string, description: string, amount: float, external_id?: string|null, hash: string, is_duplicate: bool, duplicate_reason: string|null}>
     */
    public function detectDuplicates(array $transactions, array &$seenHashes = [], ?int $excludeImportId = null): array
    {
        $withHashes = array_map(function (array $transaction): array {
            $hash = $this->generateTransactionHash(
                $this->userId,
                $transaction['date'],
                $transaction['amount'],
                $transaction['description'],
            );

            $transaction['hash'] = $hash;

            return $transaction;
        }, $transactions);

        $hashes = array_values(array_unique(array_column($withHashes, 'hash')));
        $committedHashes = Transaction::where('user_id', $this->userId)
            ->whereIn('hash', $hashes)
            ->pluck('hash')
            ->mapWithKeys(fn (string $hash): array => [$hash => true])
            ->all();

        $importedQuery = ImportedTransaction::whereHas('bankStatementImport', function ($query): void {
            $query->where('user_id', $this->userId);
        })->where(function ($query) use ($hashes): void {
            $query->whereIn('hash', $hashes)->orWhereIn('original_hash', $hashes);
        });

        if ($excludeImportId !== null) {
            $importedQuery->where('import_id', '!=', $excludeImportId);
        }

        $importedHashes = [];
        foreach ($importedQuery->get(['hash', 'original_hash']) as $importedTransaction) {
            $importedHashes[$importedTransaction->hash] = true;
            if ($importedTransaction->original_hash !== null) {
                $importedHashes[$importedTransaction->original_hash] = true;
            }
        }

        return array_values(array_map(function (array $transaction) use (&$seenHashes, $committedHashes, $importedHashes): array {
            $hash = $transaction['hash'];
            $reason = isset($committedHashes[$hash])
                ? 'existing_transaction'
                : (isset($importedHashes[$hash]) ? 'previous_import' : (isset($seenHashes[$hash]) ? 'same_file' : null));

            $transaction['is_duplicate'] = $reason !== null;
            $transaction['duplicate_reason'] = $reason;
            $seenHashes[$hash] = true;

            return $transaction;
        }, $withHashes));
    }

    /**
     * Generate unique hash for transaction
     */
    public function generateTransactionHash(int $userId, Carbon|string $date, float $amount, string $description): string
    {
        $dateString = is_string($date) ? $date : $date->toDateString();
        $amountString = number_format($amount, BankStatementConfig::AMOUNT_DECIMAL_PLACES, '.', '');

        $hashString = $userId . '|' . $dateString . '|' . $amountString . '|' . $description;

        return hash(BankStatementConfig::HASH_ALGORITHM, $hashString);
    }

    /**
     * Check if transaction hash already exists
     */
    public function isDuplicateExcluding(string $hash, ?int $excludeImportedTransactionId = null): bool
    {
        // Existing committed transactions
        $existsInTransactions = Transaction::where('user_id', $this->userId)
            ->where('hash', $hash)
            ->exists();

        if ($existsInTransactions) {
            return true;
        }

        // Imported transactions (exclude current one, check both hash and original_hash)
        $query = ImportedTransaction::whereHas('bankStatementImport', function ($query): void {
            $query->where('user_id', $this->userId);
        })->where(function ($query) use ($hash): void {
            $query->where('hash', $hash)->orWhere('original_hash', $hash);
        });

        if ($excludeImportedTransactionId !== null) {
            $query->where('id', '!=', $excludeImportedTransactionId);
        }

        return $query->exists();
    }
}
