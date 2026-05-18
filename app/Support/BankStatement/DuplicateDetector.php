<?php

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
     * Detect and mark duplicates in transaction collection
     *
     * @param list<array{date: Carbon|string, description: string, amount: float, external_id?: string|null}> $transactions
     * @return list<array{date: Carbon|string, description: string, amount: float, external_id?: string|null, hash: string, is_duplicate: bool}>
     */
    public function detectDuplicates(array $transactions): array
    {
        return collect($transactions)->map(function (array $transaction): array {
            $hash = $this->generateTransactionHash(
                $this->userId,
                $transaction['date'],
                $transaction['amount'],
                $transaction['description'],
            );

            $transaction['hash'] = $hash;
            $transaction['is_duplicate'] = $this->isDuplicate($hash);

            return $transaction;
        })->values()->all();
    }

    /**
     * Generate unique hash for transaction
     */
    public function generateTransactionHash(int $userId, Carbon|string $date, float $amount, string $description): string
    {
        $dateString = is_string($date) ? $date : $date->toDateString();
        $amountString = number_format($amount, BankStatementConfig::AMOUNT_DECIMAL_PLACES, '.', '');

        $hashString = $userId.'|'.$dateString.'|'.$amountString.'|'.$description;

        return hash(BankStatementConfig::HASH_ALGORITHM, $hashString);
    }

    /**
     * Check if transaction hash already exists
     */
    private function isDuplicate(string $hash): bool
    {
        // Check against existing committed transactions
        $existsInTransactions = Transaction::where('user_id', $this->userId)
            ->where('hash', $hash)
            ->exists();

        if ($existsInTransactions) {
            return true;
        }

        // Check against previously imported transactions (by current or original hash)
        return ImportedTransaction::whereHas('bankStatementImport', function ($query): void {
            $query->where('user_id', $this->userId);
        })
            ->where(function ($query) use ($hash): void {
                $query->where('hash', $hash)->orWhere('original_hash', $hash);
            })
            ->exists();
    }

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
