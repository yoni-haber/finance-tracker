<?php

declare(strict_types=1);

namespace App\Support\BankStatement;

use App\Models\BankProfile;
use App\Support\BankStatementConfig;
use Exception;
use Generator;
use Illuminate\Support\Collection;
use SplFileObject;

readonly class CsvFileReader
{
    /** @param array<string, mixed>|BankProfile|null $bankProfile */
    public function __construct(
        private string $filePath,
        private BankProfile|array|null $bankProfile = null,
    ) {}

    /**
     * Lazily yield non-empty data rows together with their physical CSV line number.
     *
     * @return Generator<int, array{number: int, data: array<int, string|null>}>
     */
    public function rows(): Generator
    {
        if (!file_exists($this->filePath)) {
            throw new Exception('CSV file not found: ' . $this->filePath);
        }

        $file = new SplFileObject($this->filePath, 'r');
        $hasHeader = $this->hasHeader();
        $lineNumber = 0;

        while (!$file->eof()) {
            $row = $file->fgetcsv(separator: ',', enclosure: '"', escape: '');
            $lineNumber++;

            if ($lineNumber === 1 && $hasHeader) {
                continue;
            }

            if (!$row || array_filter($row, fn ($value): bool => trim((string) $value) !== '') === []) {
                continue;
            }

            yield ['number' => $lineNumber, 'data' => $row];
        }
    }

    /**
     * Read CSV file and return filtered rows
     *
     * @return Collection<int, array<int, string|null>>
     *
     * @throws Exception
     */
    public function readRows(): Collection
    {
        return collect($this->rows())->pluck('data')->values();
    }

    private function hasHeader(): bool
    {
        if ($this->bankProfile instanceof BankProfile) {
            return (bool) ($this->bankProfile->config['has_header'] ?? BankStatementConfig::CSV_HAS_HEADER_DEFAULT);
        }

        return (bool) (($this->bankProfile['has_header'] ?? null) ?? BankStatementConfig::CSV_HAS_HEADER_DEFAULT);
    }
}
