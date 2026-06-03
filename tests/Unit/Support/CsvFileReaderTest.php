<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\BankProfile;
use App\Support\BankStatement\CsvFileReader;
use Exception;
use Tests\TestCase;

final class CsvFileReaderTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $tempFile) {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        parent::tearDown();
    }

    private function createCsvFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csv_test_');
        $this->assertNotFalse($path);
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    /** @param array<string, mixed> $config */
    private function makeBankProfile(array $config = []): BankProfile
    {
        return new BankProfile(['config' => $config]);
    }

    public function test_throws_exception_when_file_does_not_exist(): void
    {
        $csvFileReader = new CsvFileReader('/nonexistent/path/file.csv');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('CSV file not found: /nonexistent/path/file.csv');

        $csvFileReader->readRows();
    }

    public function test_skips_header_row_by_default_without_bank_profile(): void
    {
        $path = $this->createCsvFile("Header1,Header2\nval1,val2\n");
        $csvFileReader = new CsvFileReader($path);

        $rows = $csvFileReader->readRows();

        $this->assertCount(1, $rows);
        $this->assertSame(['val1', 'val2'], $rows->first());
    }

    public function test_skips_header_row_when_bank_profile_has_header_true(): void
    {
        $path = $this->createCsvFile("Header1,Header2\nval1,val2\n");
        $bankProfile = $this->makeBankProfile(['has_header' => true]);
        $csvFileReader = new CsvFileReader($path, $bankProfile);

        $rows = $csvFileReader->readRows();

        $this->assertCount(1, $rows);
        $this->assertSame(['val1', 'val2'], $rows->first());
    }

    public function test_includes_first_row_when_bank_profile_has_header_false(): void
    {
        $path = $this->createCsvFile("row1col1,row1col2\nrow2col1,row2col2\n");
        $bankProfile = $this->makeBankProfile(['has_header' => false]);
        $csvFileReader = new CsvFileReader($path, $bankProfile);

        $rows = $csvFileReader->readRows();

        $this->assertCount(2, $rows);
        $this->assertSame(['row1col1', 'row1col2'], $rows->first());
        $this->assertSame(['row2col1', 'row2col2'], $rows->last());
    }

    public function test_uses_default_has_header_when_bank_profile_config_missing_key(): void
    {
        $path = $this->createCsvFile("Header1,Header2\nval1,val2\n");
        $bankProfile = $this->makeBankProfile([]);
        $csvFileReader = new CsvFileReader($path, $bankProfile);

        $rows = $csvFileReader->readRows();

        $this->assertCount(1, $rows);
        $this->assertSame(['val1', 'val2'], $rows->first());
    }

    public function test_returns_empty_collection_for_empty_file(): void
    {
        $path = $this->createCsvFile('');
        $csvFileReader = new CsvFileReader($path);

        $rows = $csvFileReader->readRows();

        $this->assertTrue($rows->isEmpty());
    }

    public function test_returns_empty_collection_when_file_has_only_header(): void
    {
        $path = $this->createCsvFile("Header1,Header2\n");
        $csvFileReader = new CsvFileReader($path);

        $rows = $csvFileReader->readRows();

        $this->assertTrue($rows->isEmpty());
    }

    public function test_skips_empty_rows(): void
    {
        $path = $this->createCsvFile("Header1,Header2\nval1,val2\n\n\nval3,val4\n");
        $csvFileReader = new CsvFileReader($path);

        $rows = $csvFileReader->readRows();

        $this->assertCount(2, $rows);
        $this->assertSame(['val1', 'val2'], $rows->get(0));
        $this->assertSame(['val3', 'val4'], $rows->get(1));
    }

    public function test_skips_rows_with_only_null_or_empty_values(): void
    {
        $path = $this->createCsvFile("Header1,Header2\n,\nval1,val2\n");
        $csvFileReader = new CsvFileReader($path);

        $rows = $csvFileReader->readRows();

        $this->assertCount(1, $rows);
        $this->assertSame(['val1', 'val2'], $rows->first());
    }

    public function test_reads_multiple_data_rows(): void
    {
        $csv = "H1,H2,H3\na,b,c\nd,e,f\ng,h,i\n";
        $path = $this->createCsvFile($csv);
        $csvFileReader = new CsvFileReader($path);

        $rows = $csvFileReader->readRows();

        $this->assertCount(3, $rows);
        $this->assertSame(['a', 'b', 'c'], $rows->get(0));
        $this->assertSame(['d', 'e', 'f'], $rows->get(1));
        $this->assertSame(['g', 'h', 'i'], $rows->get(2));
    }

    public function test_handles_quoted_fields_with_commas(): void
    {
        $path = $this->createCsvFile("H1,H2\n\"hello, world\",value\n");
        $csvFileReader = new CsvFileReader($path);

        $rows = $csvFileReader->readRows();

        $this->assertCount(1, $rows);
        $this->assertSame(['hello, world', 'value'], $rows->first());
    }

    public function test_returns_collection_type(): void
    {
        $path = $this->createCsvFile("H1\nval1\n");
        $csvFileReader = new CsvFileReader($path);

        $rows = $csvFileReader->readRows();

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $rows);
    }

    public function test_single_column_csv(): void
    {
        $path = $this->createCsvFile("Header\nvalue1\nvalue2\n");
        $csvFileReader = new CsvFileReader($path);

        $rows = $csvFileReader->readRows();

        $this->assertCount(2, $rows);
        $this->assertSame(['value1'], $rows->get(0));
        $this->assertSame(['value2'], $rows->get(1));
    }

    public function test_no_header_reads_all_rows_including_first(): void
    {
        $path = $this->createCsvFile("first,row\nsecond,row\nthird,row\n");
        $bankProfile = $this->makeBankProfile(['has_header' => false]);
        $csvFileReader = new CsvFileReader($path, $bankProfile);

        $rows = $csvFileReader->readRows();

        $this->assertCount(3, $rows);
        $this->assertSame(['first', 'row'], $rows->get(0));
    }
}
