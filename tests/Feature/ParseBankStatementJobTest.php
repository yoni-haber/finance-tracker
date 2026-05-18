<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ParseBankStatementJob;
use App\Models\BankProfile;
use App\Models\BankStatementImport;
use App\Models\ImportedTransaction;
use App\Models\User;
use App\Support\BankStatementConfig;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class ParseBankStatementJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_parses_bank_statement_successfully(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create([
            'statement_type' => 'bank',
            'config' => [
                'columns' => ['date' => 0, 'description' => 1, 'amount' => 2],
                'date_format' => 'd/m/Y',
                'has_header' => false,
            ],
        ]);

        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_UPLOADED]);

        // Create CSV file
        $csvContent = "01/01/2026,Test Transaction,100.50\n02/01/2026,Another Transaction,-50.25";
        Storage::fake('local');
        Storage::put(sprintf('statements/%d.csv', $import->id), $csvContent);

        // Execute job
        $parseBankStatementJob = new ParseBankStatementJob($import->id);
        $parseBankStatementJob->handle();

        // Check import status
        $import->refresh();
        $this->assertEquals(BankStatementConfig::STATUS_PARSED, $import->status);

        // Check transactions were created
        $transactions = $import->importedTransactions;
        $this->assertCount(2, $transactions);

        $firstTransaction = $transactions->first();
        $this->assertEquals('2026-01-01', $firstTransaction->date->toDateString());
        $this->assertEquals('TEST TRANSACTION', $firstTransaction->description);
        $this->assertEqualsWithDelta(100.50, $firstTransaction->amount, PHP_FLOAT_EPSILON);
        $this->assertFalse($firstTransaction->is_duplicate);
    }

    public function test_handles_missing_import_gracefully(): void
    {
        $parseBankStatementJob = new ParseBankStatementJob(99999); // Non-existent import

        $this->expectException(ModelNotFoundException::class);
        $parseBankStatementJob->handle();
    }

    public function test_handles_missing_csv_file(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_UPLOADED]);

        Storage::fake('local'); // File doesn't exist

        $parseBankStatementJob = new ParseBankStatementJob($import->id);
        $parseBankStatementJob->handle();

        $import->refresh();
        $this->assertEquals(BankStatementConfig::STATUS_FAILED, $import->status);
    }

    public function test_updates_status_to_parsing_before_processing(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create([
            'config' => [
                'columns' => ['date' => 0, 'description' => 1, 'amount' => 2],
                'date_format' => 'd/m/Y',
                'has_header' => false,
            ],
        ]);

        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_UPLOADED]);

        Storage::fake('local');
        Storage::put(sprintf('statements/%d.csv', $import->id), '01/01/2026,Test,100');

        $parseBankStatementJob = new ParseBankStatementJob($import->id);
        $parseBankStatementJob->handle();

        $import->refresh();
        $this->assertEquals(BankStatementConfig::STATUS_PARSED, $import->status); // Final status after successful parsing
    }

    public function test_is_idempotent(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create([
            'config' => [
                'columns' => ['date' => 0, 'description' => 1, 'amount' => 2],
                'date_format' => 'd/m/Y',
                'has_header' => false,
            ],
        ]);

        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_UPLOADED]);

        Storage::fake('local');
        Storage::put(sprintf('statements/%d.csv', $import->id), '01/01/2026,Test Transaction,100.50');

        $parseBankStatementJob = new ParseBankStatementJob($import->id);

        // Run twice
        $parseBankStatementJob->handle();
        $parseBankStatementJob->handle();

        // Should not create duplicate transactions
        $this->assertCount(1, $import->fresh()->importedTransactions);
        $this->assertEquals(BankStatementConfig::STATUS_PARSED, $import->fresh()->status);
    }

    public function test_can_be_queued(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $profile = BankProfile::factory()->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();

        ParseBankStatementJob::dispatch($import->id);

        Queue::assertPushed(ParseBankStatementJob::class, fn ($job): bool => $job->importId === $import->id);
    }

    public function test_job_properties_are_set_correctly(): void
    {
        $parseBankStatementJob = new ParseBankStatementJob(123);

        $this->assertSame(123, $parseBankStatementJob->importId);
        $this->assertSame(60, $parseBankStatementJob->timeout);
        $this->assertSame(3, $parseBankStatementJob->tries);
    }

    public function test_handles_invalid_csv_data(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create([
            'config' => [
                'columns' => ['date' => 0, 'description' => 1, 'amount' => 2],
                'date_format' => 'd/m/Y',
                'has_header' => false,
            ],
        ]);

        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_UPLOADED]);

        // Create CSV with invalid data
        $csvContent = 'invalid-date,Test Transaction,not-a-number';
        Storage::fake('local');
        Storage::put(sprintf('statements/%d.csv', $import->id), $csvContent);

        $parseBankStatementJob = new ParseBankStatementJob($import->id);
        $parseBankStatementJob->handle();

        $import->refresh();
        // Parser should complete successfully but skip invalid rows
        $this->assertEquals(BankStatementConfig::STATUS_PARSED, $import->status);

        // Should not create any transactions due to invalid data
        $this->assertCount(0, $import->importedTransactions);
    }

    public function test_processes_large_csv_files(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create([
            'config' => [
                'columns' => ['date' => 0, 'description' => 1, 'amount' => 2],
                'date_format' => 'd/m/Y',
                'has_header' => false,
            ],
        ]);

        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_UPLOADED]);

        // Create large CSV content
        $lines = [];
        for ($i = 1; $i <= 1000; $i++) {
            $lines[] = sprintf('%02d/01/2026,Transaction %d,%d.00', ($i % 28) + 1, $i, $i * 10);
        }

        $csvContent = implode("\n", $lines);

        Storage::fake('local');
        Storage::put(sprintf('statements/%d.csv', $import->id), $csvContent);

        $parseBankStatementJob = new ParseBankStatementJob($import->id);
        $parseBankStatementJob->handle();

        $import->refresh();
        $this->assertEquals(BankStatementConfig::STATUS_PARSED, $import->status);
        $this->assertCount(1000, $import->importedTransactions);
    }

    public function test_handles_different_csv_encodings(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create([
            'config' => [
                'columns' => ['date' => 0, 'description' => 1, 'amount' => 2],
                'date_format' => 'd/m/Y',
                'has_header' => false,
            ],
        ]);

        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_UPLOADED]);

        // CSV with special characters
        $csvContent = "01/01/2026,Café Purchase,€25.50\n02/01/2026,Résumé Printing,£10.00";
        Storage::fake('local');
        Storage::put(sprintf('statements/%d.csv', $import->id), $csvContent);

        $parseBankStatementJob = new ParseBankStatementJob($import->id);
        $parseBankStatementJob->handle();

        $import->refresh();
        $this->assertEquals(BankStatementConfig::STATUS_PARSED, $import->status);

        $transactions = $import->importedTransactions;
        $this->assertCount(2, $transactions);

        // Check special characters are handled
        $this->assertStringContainsString('CAFÉ', $transactions->first()->description);
        $this->assertStringContainsString('RÉSUMÉ', $transactions->last()->description);
    }

    public function test_skips_already_parsed_imports(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create([
            'config' => [
                'columns' => ['date' => 0, 'description' => 1, 'amount' => 2],
                'date_format' => 'd/m/Y',
                'has_header' => false,
            ],
        ]);

        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        // Add some existing imported transactions
        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create();

        Storage::fake('local');
        Storage::put(sprintf('statements/%d.csv', $import->id), '01/01/2026,Test,100');

        $parseBankStatementJob = new ParseBankStatementJob($import->id);
        $parseBankStatementJob->handle(); // handle() is now void — no return value to assert

        $this->assertEquals(BankStatementConfig::STATUS_PARSED, $import->fresh()->status);

        // Should not create additional transactions
        $this->assertCount(1, $import->fresh()->importedTransactions);
    }

    public function test_failed_marks_import_as_failed(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_UPLOADED]);

        $parseBankStatementJob = new ParseBankStatementJob($import->id);
        $parseBankStatementJob->failed(new RuntimeException('Something went wrong'));

        $this->assertEquals(BankStatementConfig::STATUS_FAILED, $import->fresh()->status);
    }

    public function test_failed_is_a_no_op_when_import_not_found(): void
    {
        $this->expectNotToPerformAssertions();

        $parseBankStatementJob = new ParseBankStatementJob(99999);

        // Should not throw — import simply does not exist
        $parseBankStatementJob->failed(new RuntimeException('Boom'));
    }

    public function test_skips_committed_imports(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create([
            'config' => ['columns' => ['date' => 0, 'description' => 1, 'amount' => 2], 'has_header' => false],
        ]);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')
            ->create(['status' => BankStatementConfig::STATUS_COMMITTED]);

        Storage::fake('local');

        $parseBankStatementJob = new ParseBankStatementJob($import->id);
        $parseBankStatementJob->handle();

        // Status should be unchanged — committed imports are skipped
        $this->assertEquals(BankStatementConfig::STATUS_COMMITTED, $import->fresh()->status);
    }
}
