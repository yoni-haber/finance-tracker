<?php

namespace App\Support\BankStatement;

use App\Models\BankProfile;
use App\Support\BankStatementConfig;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Str;

readonly class TransactionRowParser
{
    public function __construct(
        private BankProfile $bankProfile,
    ) {}

    /**
     * Parse a single CSV row into transaction data
     *
     * @param array<int, string|null> $row
     * @return array{date: Carbon, description: string, amount: float, external_id: null}|null
     */
    public function parseRow(array $row): ?array
    {
        $columns = $this->bankProfile->config['columns'] ?? [];

        $date = $this->extractDate($row, $columns['date'] ?? null);
        $description = $this->extractDescription($row, $columns['description'] ?? null);
        $amount = $this->extractAmount($row, $columns);

        if (!$date instanceof Carbon || !$description || $amount === null) {
            return null;
        }

        // Apply statement type logic
        if ($this->bankProfile->isCreditCardStatement()) {
            $amount = -$amount; // Flip sign for credit cards
        }

        return [
            'date' => $date,
            'description' => $description,
            'amount' => $amount,
            'external_id' => null,
        ];
    }

    /**
     * Extract date from row
     *
     * @param array<int, mixed> $row
     */
    private function extractDate(array $row, ?int $dateIndex): ?Carbon
    {
        if ($dateIndex === null) {
            return null;
        }

        $dateString = trim($row[$dateIndex] ?? '');
        if ($dateString === '' || $dateString === '0') {
            return null;
        }

        return $this->parseDate($dateString);
    }

    /**
     * Extract description from row
     *
     * @param array<int, mixed> $row
     */
    private function extractDescription(array $row, ?int $descriptionIndex): ?string
    {
        if ($descriptionIndex === null) {
            return null;
        }

        $description = $this->normaliseDescription(trim($row[$descriptionIndex] ?? ''));

        return $description === '' || $description === '0' ? null : $description;
    }

    /**
     * Extract amount from row
     *
     * @param array<int, string|null> $row
     * @param array<string, mixed> $columns
     */
    private function extractAmount(array $row, array $columns): ?float
    {
        $amountIndex = $columns['amount'] ?? null;
        $debitIndex = $columns['debit'] ?? null;
        $creditIndex = $columns['credit'] ?? null;

        return $this->parseAmount($row, $amountIndex, $debitIndex, $creditIndex);
    }

    /**
     * Parse date from string using supported formats
     */
    private function parseDate(string $dateString): ?Carbon
    {
        $formats = BankStatementConfig::SUPPORTED_DATE_FORMATS;

        // Try profile-specific format first
        if (isset($this->bankProfile->config['date_format'])) {
            array_unshift($formats, $this->bankProfile->config['date_format']);
        }

        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, $dateString);
            } catch (Exception) {
                continue;
            }
        }

        return null;
    }

    /**
     * Normalise description text
     */
    private function normaliseDescription(string $description): string
    {
        return Str::squish(Str::upper($description));
    }

    /**
     * Parse amount from row data
     *
     * @param array<int, mixed> $row
     */
    private function parseAmount(array $row, ?int $amountIndex, ?int $debitIndex, ?int $creditIndex): ?float
    {
        // Single amount column
        if ($amountIndex !== null) {
            return $this->parseAmountString(trim($row[$amountIndex] ?? ''));
        }

        // Separate debit/credit columns
        if ($debitIndex !== null || $creditIndex !== null) {
            $debit = $debitIndex !== null ?
                ($this->parseAmountString(trim($row[$debitIndex] ?? '')) ?? 0) : 0;

            $credit = $creditIndex !== null ?
                ($this->parseAmountString(trim($row[$creditIndex] ?? '')) ?? 0) : 0;

            return $credit - $debit;
        }

        return null;
    }

    /**
     * Parse amount string to float
     */
    private function parseAmountString(string $amountString): ?float
    {
        if ($amountString === '' || $amountString === '0') {
            return null;
        }

        // Remove common currency symbols and whitespace
        $amountString = preg_replace('/[£$€¥,\s]/', '', $amountString);

        // Handle negative amounts in parentheses
        if (preg_match('/^\((.+)\)$/', (string) $amountString, $matches)) {
            $amountString = '-'.$matches[1];
        }

        return is_numeric($amountString) ? (float) $amountString : null;
    }
}
