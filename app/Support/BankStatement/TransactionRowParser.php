<?php

declare(strict_types=1);

namespace App\Support\BankStatement;

use App\Models\BankProfile;
use App\Support\BankStatementConfig;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Str;
use InvalidArgumentException;

readonly class TransactionRowParser
{
    /** @var array<string, mixed> */
    private array $config;

    private string $statementType;

    /** @param array<string, mixed>|BankProfile $bankProfile */
    public function __construct(
        BankProfile|array $bankProfile,
        ?string $statementType = null,
    ) {
        $this->config = $bankProfile instanceof BankProfile ? $bankProfile->config : $bankProfile;
        $this->statementType = $bankProfile instanceof BankProfile ? $bankProfile->statement_type : ($statementType ?? BankStatementConfig::STATEMENT_TYPE_BANK);
    }

    /**
     * Parse a single CSV row into transaction data
     *
     * @param array<int, string|null> $row
     * @return array{date: Carbon, description: string, amount: float, external_id: null}|null
     */
    public function parseRow(array $row): ?array
    {
        try {
            return $this->parseRowStrict($row);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Parse a row or throw a user-facing validation error.
     *
     * @param array<int, string|null> $row
     * @return array{date: Carbon, description: string, amount: float, external_id: null}
     */
    public function parseRowStrict(array $row): array
    {
        $columns = $this->config['columns'] ?? [];

        if (!is_array($columns)) {
            throw new InvalidArgumentException('The profile column mapping is invalid.');
        }

        $date = $this->extractDate($row, $columns['date'] ?? null);
        $description = $this->extractDescription($row, $columns['description'] ?? null);
        $amount = $this->extractAmount($row, $columns);

        if (!$date instanceof Carbon) {
            throw new InvalidArgumentException('Date is missing or does not match a supported format.');
        }

        if (!$description) {
            throw new InvalidArgumentException('Description is missing.');
        }

        if ($amount === null || abs($amount) < 0.005) {
            throw new InvalidArgumentException('Amount is missing, zero, or invalid.');
        }

        // Apply statement type logic
        if ($this->statementType === BankStatementConfig::STATEMENT_TYPE_CREDIT_CARD) {
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
        if (isset($this->config['date_format']) && is_string($this->config['date_format'])) {
            array_unshift($formats, $this->config['date_format']);
        }

        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat($format, $dateString);
                if (!$date instanceof Carbon) {
                    continue;
                }

                $errors = Carbon::getLastErrors();

                if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                    continue;
                }

                if ($date->format($format) !== $dateString) {
                    continue;
                }

                return $date->startOfDay();
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
            return $this->parseAmountString($row[$amountIndex] ?? '');
        }

        // Separate debit/credit columns
        if ($debitIndex !== null || $creditIndex !== null) {
            $debitRaw = $debitIndex !== null ? trim((string) ($row[$debitIndex] ?? '')) : '';
            $creditRaw = $creditIndex !== null ? trim((string) ($row[$creditIndex] ?? '')) : '';
            $debit = $debitRaw === '' ? 0.0 : $this->parseAmountString($debitRaw);
            $credit = $creditRaw === '' ? 0.0 : $this->parseAmountString($creditRaw);

            if (($debitRaw !== '' && $debit === null) || ($creditRaw !== '' && $credit === null)) {
                return null;
            }

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
            $amountString = '-' . $matches[1];
        }

        return is_numeric($amountString) ? (float) $amountString : null;
    }
}
