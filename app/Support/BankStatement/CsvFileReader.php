<?php

namespace App\Support\BankStatement;

use App\Models\BankProfile;
use App\Support\BankStatementConfig;
use Exception;
use Illuminate\Support\Collection;
use SplFileObject;

readonly class CsvFileReader
{
    public function __construct(
        private string $filePath,
        private ?BankProfile $bankProfile = null,
    ) {}

    /**
     * Read CSV file and return filtered rows
     */
    public function readRows(): Collection
    {
        if (!file_exists($this->filePath)) {
            throw new Exception('CSV file not found: '.$this->filePath);
        }

        $file = new SplFileObject($this->filePath, 'r');

        $rows = collect();
        $isFirstRow = true;
        $hasHeader = $this->bankProfile instanceof BankProfile ? ($this->bankProfile->config['has_header'] ?? BankStatementConfig::CSV_HAS_HEADER_DEFAULT) : BankStatementConfig::CSV_HAS_HEADER_DEFAULT;

        while (!$file->eof()) {
            $row = $file->fgetcsv(separator: ',', enclosure: '"', escape: '');

            if ($isFirstRow && $hasHeader) {
                $isFirstRow = false;

                continue;
            }

            $isFirstRow = false;
            if (!$row) {
                continue;
            }

            if (array_filter($row) === []) {
                continue;
            }

            $rows->push($row);
        }

        return $rows;
    }
}
