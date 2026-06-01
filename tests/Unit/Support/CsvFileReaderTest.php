<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\BankStatement\CsvFileReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CsvFileReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_rows_uses_default_header_config_when_bank_profile_is_null(): void
    {
        // Create a temporary CSV file with a header row.
        $csvContent = "Date,Description,Amount\n01/01/2026,Test Transaction,100.50\n02/01/2026,Another Transaction,50.25";
        $tempFile = tempnam(sys_get_temp_dir(), 'csv_test_');
        file_put_contents($tempFile, $csvContent);

        try {
            // Null bankProfile should use BankStatementConfig::CSV_HAS_HEADER_DEFAULT (true).
            $reader = new CsvFileReader($tempFile, null);
            $rows = $reader->readRows();

            // Header row should be skipped (default is true), so only 2 data rows.
            $this->assertCount(2, $rows);
            $this->assertEquals('01/01/2026', $rows->first()[0]);
        } finally {
            unlink($tempFile);
        }
    }
}
