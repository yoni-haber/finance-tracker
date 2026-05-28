<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\BankProfile;
use App\Models\BankStatementImport;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BankStatement\BankStatementImportProcessor;
use App\Support\BankStatement\DuplicateDetector;
use App\Support\BankStatementConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

final class BankStatementImportProcessorTest extends TestCase
{
    use RefreshDatabase;

    public function test_parses_single_amount_column_csv(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create([
            'statement_type' => 'bank',
            'config' => [
                'columns' => ['date' => 0, 'description' => 1, 'amount' => 2],
                'date_format' => 'd/m/Y',
                'has_header' => true,
            ],
        ]);

        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();

        // Create test CSV content with header
        $csvContent = "Date,Description,Amount\n01/01/2026,Test Transaction,100.50\n02/01/2026,Another Transaction,-50.25";
        Storage::fake('local');
        Storage::put(sprintf('statements/%d.csv', $import->id), $csvContent);

        $bankStatementImportProcessor = new BankStatementImportProcessor($import);
        $result = $bankStatementImportProcessor->process();

        $this->assertTrue($result);
        $fresh = $import->fresh();
        $this->assertNotNull($fresh);
        $this->assertEquals(BankStatementConfig::STATUS_PARSED, $fresh->status);

        $transactions = $import->importedTransactions;
        $this->assertCount(2, $transactions);

        $firstTransaction = $transactions->first();
        $this->assertInstanceOf(\App\Models\ImportedTransaction::class, $firstTransaction);
        $this->assertEquals('2026-01-01', $firstTransaction->date->toDateString());
        $this->assertEquals('TEST TRANSACTION', $firstTransaction->description);
        $this->assertEqualsWithDelta(100.50, $firstTransaction->amount, PHP_FLOAT_EPSILON);
        $this->assertFalse($firstTransaction->is_duplicate);
    }

    public function test_parses_debit_credit_columns_csv(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create([
            'statement_type' => 'bank',
            'config' => [
                'columns' => ['date' => 0, 'description' => 1, 'debit' => 2, 'credit' => 3],
                'date_format' => 'd/m/Y',
                'has_header' => true,
            ],
        ]);

        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();

        $csvContent = "Date,Description,Debit,Credit\n01/01/2026,Income Transaction,,100.50\n02/01/2026,Expense Transaction,50.25,";
        Storage::fake('local');
        Storage::put(sprintf('statements/%d.csv', $import->id), $csvContent);

        $bankStatementImportProcessor = new BankStatementImportProcessor($import);
        $result = $bankStatementImportProcessor->process();

        $this->assertTrue($result);

        $transactions = $import->importedTransactions;
        $this->assertCount(2, $transactions);

        $incomeTransaction = $transactions->where('amount', '>', 0)->first();
        $expenseTransaction = $transactions->where('amount', '<', 0)->first();

        $this->assertInstanceOf(\App\Models\ImportedTransaction::class, $incomeTransaction);
        $this->assertInstanceOf(\App\Models\ImportedTransaction::class, $expenseTransaction);
        $this->assertEqualsWithDelta(100.50, $incomeTransaction->amount, PHP_FLOAT_EPSILON);
        $this->assertEquals(-50.25, $expenseTransaction->amount);
    }

    public function test_applies_credit_card_amount_transformation(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create([
            'statement_type' => 'credit_card',
            'config' => [
                'columns' => ['date' => 0, 'description' => 1, 'amount' => 2],
                'date_format' => 'd/m/Y',
                'has_header' => true,
            ],
        ]);

        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();

        $csvContent = "Date,Description,Amount\n01/01/2026,Purchase,100.50\n02/01/2026,Payment,-200.00";
        Storage::fake('local');
        Storage::put(sprintf('statements/%d.csv', $import->id), $csvContent);

        $bankStatementImportProcessor = new BankStatementImportProcessor($import);
        $result = $bankStatementImportProcessor->process();

        $this->assertTrue($result);

        $transactions = $import->importedTransactions;
        $this->assertCount(2, $transactions);

        $purchase = $transactions->where('description', 'PURCHASE')->first();
        $payment = $transactions->where('description', 'PAYMENT')->first();

        $this->assertInstanceOf(\App\Models\ImportedTransaction::class, $purchase);
        $this->assertInstanceOf(\App\Models\ImportedTransaction::class, $payment);
        // Credit card: positive CSV amount = purchase = expense (negative)
        $this->assertEquals(-100.50, $purchase->amount);
        // Credit card: negative CSV amount = payment = income (positive)
        $this->assertEqualsWithDelta(200.00, $payment->amount, PHP_FLOAT_EPSILON);
    }

    public function test_detects_duplicates_against_existing_transactions(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create([
            'statement_type' => 'bank',
            'config' => [
                'columns' => ['date' => 0, 'description' => 1, 'amount' => 2],
                'date_format' => 'd/m/Y',
                'has_header' => true,
            ],
        ]);

        // Create existing transaction with hash
        $existingTransaction = Transaction::factory()->for($user)->create([
            'date' => '2026-01-01',
            'description' => 'EXISTING TRANSACTION',
            'amount' => 100.50,
        ]);

        // Generate hash for the existing transaction
        $duplicateDetector = new DuplicateDetector($user->id);
        $description = $existingTransaction->description;
        $this->assertNotNull($description);
        $hash = $duplicateDetector->generateTransactionHash($user->id, $existingTransaction->date, (float) $existingTransaction->amount, $description);
        $existingTransaction->update(['hash' => $hash]);

        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();

        $csvContent = "Date,Description,Amount\n01/01/2026,Existing Transaction,100.50\n02/01/2026,New Transaction,75.00";
        Storage::fake('local');
        Storage::put(sprintf('statements/%d.csv', $import->id), $csvContent);

        $bankStatementImportProcessor = new BankStatementImportProcessor($import);
        $result = $bankStatementImportProcessor->process();

        $this->assertTrue($result);

        $transactions = $import->importedTransactions;
        $this->assertCount(2, $transactions);

        $duplicate = $transactions->where('description', 'EXISTING TRANSACTION')->first();
        $newTransaction = $transactions->where('description', 'NEW TRANSACTION')->first();

        $this->assertInstanceOf(\App\Models\ImportedTransaction::class, $duplicate);
        $this->assertInstanceOf(\App\Models\ImportedTransaction::class, $newTransaction);
        $this->assertTrue($duplicate->is_duplicate);
        $this->assertFalse($newTransaction->is_duplicate);
    }

    public function test_handles_different_date_formats(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create([
            'statement_type' => 'bank',
            'config' => [
                'columns' => ['date' => 0, 'description' => 1, 'amount' => 2],
                'date_format' => 'Y-m-d',
                'has_header' => true,
            ],
        ]);

        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();

        $csvContent = "Date,Description,Amount\n2026-01-04,Test Transaction,100.00";
        Storage::fake('local');
        Storage::put(sprintf('statements/%d.csv', $import->id), $csvContent);

        $bankStatementImportProcessor = new BankStatementImportProcessor($import);
        $result = $bankStatementImportProcessor->process();

        $this->assertTrue($result);

        $transaction = $import->importedTransactions->first();
        $this->assertInstanceOf(\App\Models\ImportedTransaction::class, $transaction);
        $this->assertEquals('2026-01-04', $transaction->date->toDateString());
    }

    public function test_fails_gracefully_with_invalid_csv(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create([
            'config' => [
                'columns' => ['date' => 0, 'description' => 1, 'amount' => 2],
                'date_format' => 'd/m/Y',
                'has_header' => false,
            ],
        ]);

        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();

        Storage::fake('local');
        // Create a CSV file that will cause a fundamental parsing error
        Storage::put(sprintf('statements/%d.csv', $import->id), 'This is not a valid CSV file at all - it will cause parsing issues');

        $bankStatementImportProcessor = new BankStatementImportProcessor($import);
        $result = $bankStatementImportProcessor->process();

        // Even invalid CSV shouldn't crash - it should return true but create no transactions
        $this->assertTrue($result);
        $fresh = $import->fresh();
        $this->assertNotNull($fresh);
        $this->assertEquals(BankStatementConfig::STATUS_PARSED, $fresh->status);

        // Should not create any transactions
        $this->assertCount(0, $import->importedTransactions);
    }

    public function test_fails_when_file_missing(): void
    {
        Log::spy();

        $user = User::factory()->create();
        $profile = BankProfile::factory()->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();

        Storage::fake('local'); // File doesn't exist

        $bankStatementImportProcessor = new BankStatementImportProcessor($import);
        $result = $bankStatementImportProcessor->process();

        $this->assertFalse($result);
        $fresh = $import->fresh();
        $this->assertNotNull($fresh);
        $this->assertEquals(BankStatementConfig::STATUS_FAILED, $fresh->status);

        // Assert that the error was logged with the expected message
        Log::shouldHaveReceived('error')
            ->once()
            ->with('Bank statement parsing failed', Mockery::on(fn ($context): bool => $context['import_id'] === $import->id
                && str_contains((string) $context['error'], 'CSV file not found')));
    }

    public function test_is_idempotent(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create([
            'config' => [
                'columns' => ['date' => 0, 'description' => 1, 'amount' => 2],
                'date_format' => 'd/m/Y',
                'has_header' => true,
            ],
        ]);

        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();

        $csvContent = "Date,Description,Amount\n01/01/2026,Test Transaction,100.50";
        Storage::fake('local');
        Storage::put(sprintf('statements/%d.csv', $import->id), $csvContent);

        $bankStatementImportProcessor = new BankStatementImportProcessor($import);

        // Parse twice
        $result1 = $bankStatementImportProcessor->process();
        $result2 = $bankStatementImportProcessor->process();

        $this->assertTrue($result1);
        $this->assertTrue($result2);

        // Should not create duplicate imported transactions
        $fresh = $import->fresh();
        $this->assertNotNull($fresh);
        $this->assertCount(1, $fresh->importedTransactions);
    }

    public function test_fails_when_bank_profile_missing(): void
    {
        Log::spy();

        $user = User::factory()->create();
        $import = BankStatementImport::factory()->for($user)->create([
            'bank_profile_id' => null,
        ]);

        $csvContent = "Date,Description,Amount\n01/01/2026,Test Transaction,100.50";
        Storage::fake('local');
        Storage::put(sprintf('statements/%d.csv', $import->id), $csvContent);

        $bankStatementImportProcessor = new BankStatementImportProcessor($import);
        $result = $bankStatementImportProcessor->process();

        $this->assertFalse($result);
        $fresh = $import->fresh();
        $this->assertNotNull($fresh);
        $this->assertEquals(BankStatementConfig::STATUS_FAILED, $fresh->status);
        $this->assertCount(0, $import->importedTransactions);

        Log::shouldHaveReceived('error')
            ->once()
            ->with('Bank statement parsing failed', [
                'import_id' => $import->id,
                'error' => 'Bank profile is required for parsing',
            ]);
    }

    public function test_skips_processing_when_status_is_parsing(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create([
            'config' => [
                'columns' => ['date' => 0, 'description' => 1, 'amount' => 2],
                'date_format' => 'd/m/Y',
                'has_header' => true,
            ],
        ]);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create([
            'status' => BankStatementConfig::STATUS_PARSING,
        ]);

        Storage::fake('local');
        Storage::put(sprintf('statements/%d.csv', $import->id), "Date,Description,Amount\n01/01/2026,Test,100.00");

        $bankStatementImportProcessor = new BankStatementImportProcessor($import);
        $result = $bankStatementImportProcessor->process();

        // STATUS_PARSING is treated as "already claimed by another worker" — skip, not an error.
        $this->assertFalse($result);
        $fresh = $import->fresh();
        $this->assertNotNull($fresh);
        $this->assertEquals(BankStatementConfig::STATUS_PARSING, $fresh->status);
        $this->assertCount(0, $fresh->importedTransactions);
    }

    public function test_can_reprocess_after_failed_status(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create([
            'config' => [
                'columns' => ['date' => 0, 'description' => 1, 'amount' => 2],
                'date_format' => 'd/m/Y',
                'has_header' => true,
            ],
        ]);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create([
            'status' => BankStatementConfig::STATUS_FAILED,
        ]);

        Storage::fake('local');
        Storage::put(sprintf('statements/%d.csv', $import->id), "Date,Description,Amount\n01/01/2026,Test,100.00");

        $bankStatementImportProcessor = new BankStatementImportProcessor($import);
        $result = $bankStatementImportProcessor->process();

        $this->assertTrue($result);
        $fresh = $import->fresh();
        $this->assertNotNull($fresh);
        $this->assertEquals(BankStatementConfig::STATUS_PARSED, $fresh->status);
        $this->assertCount(1, $fresh->importedTransactions);
    }

    public function test_reprocessing_after_failed_clears_orphaned_rows(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create([
            'config' => [
                'columns' => ['date' => 0, 'description' => 1, 'amount' => 2],
                'date_format' => 'd/m/Y',
                'has_header' => true,
            ],
        ]);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create([
            'status' => BankStatementConfig::STATUS_FAILED,
        ]);

        // Simulate orphaned rows left by a previous failed attempt
        $import->importedTransactions()->create([
            'date' => '2026-01-01',
            'description' => 'ORPHANED ROW',
            'amount' => 99.00,
            'hash' => 'orphaned-hash',
            'original_hash' => 'orphaned-hash',
            'is_duplicate' => false,
            'is_committed' => false,
        ]);

        Storage::fake('local');
        Storage::put(sprintf('statements/%d.csv', $import->id), "Date,Description,Amount\n01/01/2026,Test,100.00");

        $bankStatementImportProcessor = new BankStatementImportProcessor($import);
        $bankStatementImportProcessor->process();

        // Orphaned rows cleared; only the freshly parsed row should exist.
        $freshImport = $import->fresh();
        $this->assertNotNull($freshImport);
        $transactions = $freshImport->importedTransactions;
        $this->assertCount(1, $transactions);
        $firstTx = $transactions->first();
        $this->assertInstanceOf(\App\Models\ImportedTransaction::class, $firstTx);
        $this->assertEquals('TEST', $firstTx->description);
    }

    public function test_handles_missing_amount_columns(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create([
            'config' => [
                'columns' => ['date' => 0, 'description' => 1], // No amount, debit, or credit columns
                'date_format' => 'd/m/Y',
                'has_header' => true,
            ],
        ]);

        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();

        $csvContent = "Date,Description\n01/01/2026,Transaction Without Amount";
        Storage::fake('local');
        Storage::put(sprintf('statements/%d.csv', $import->id), $csvContent);

        $bankStatementImportProcessor = new BankStatementImportProcessor($import);
        $result = $bankStatementImportProcessor->process();

        $this->assertTrue($result);
        $fresh = $import->fresh();
        $this->assertNotNull($fresh);
        $this->assertEquals(BankStatementConfig::STATUS_PARSED, $fresh->status);

        // Should not create any transactions because amount is null
        $this->assertCount(0, $import->importedTransactions);
    }
}
