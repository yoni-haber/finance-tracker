<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\BankProfile;
use App\Models\BankStatementImport;
use App\Models\Category;
use App\Models\ImportedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BankStatementConfig;
use App\Support\StatementImportCommitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class StatementImportCommitterTest extends TestCase
{
    use RefreshDatabase;

    public function test_commits_non_duplicate_transactions(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();
        $profile = BankProfile::factory()->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        ImportedTransaction::factory()->for($import)->create([
            'date' => '2026-01-01',
            'description' => 'Test Transaction',
            'amount' => 100.50,
            'is_duplicate' => false,
            'category_id' => $category->id,
        ]);

        ImportedTransaction::factory()->for($import)->create([
            'date' => '2026-01-02',
            'description' => 'Duplicate Transaction',
            'amount' => 50.00,
            'is_duplicate' => true,
        ]);

        $statementImportCommitter = new StatementImportCommitter($import);
        $result = $statementImportCommitter->commit();

        $this->assertTrue($result);
        $this->assertEquals(BankStatementConfig::STATUS_COMMITTED, $import->fresh()->status);

        $transactions = Transaction::where('user_id', $user->id)->get();
        $this->assertCount(1, $transactions);

        $transaction = $transactions->first();
        $this->assertEquals('2026-01-01', $transaction->date->toDateString());
        $this->assertEquals('Test Transaction', $transaction->description);
        $this->assertEqualsWithDelta(100.50, $transaction->amount, PHP_FLOAT_EPSILON);
        $this->assertTrue($transaction->category->is($category));

        $committedImported = ImportedTransaction::where('is_committed', true)->get();
        $this->assertCount(1, $committedImported);
    }

    public function test_handles_category_assignment_from_category_id(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();
        $profile = BankProfile::factory()->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        ImportedTransaction::factory()->for($import)->create([
            'amount' => 100.00,
            'is_duplicate' => false,
            'category_id' => $category->id,
        ]);

        ImportedTransaction::factory()->for($import)->create([
            'amount' => 50.00,
            'is_duplicate' => false,
            'category_id' => null,
        ]);

        $statementImportCommitter = new StatementImportCommitter($import);
        $statementImportCommitter->commit();

        $transactions = Transaction::where('user_id', $user->id)->orderBy('amount', 'desc')->get();
        $this->assertCount(2, $transactions);

        $this->assertTrue($transactions->first()->category->is($category));
        $this->assertNull($transactions->last()->category_id);
    }

    public function test_determines_transaction_type_based_on_amount_and_statement_type(): void
    {
        $user = User::factory()->create();

        // Test bank statement
        $bankProfile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $bankImport = BankStatementImport::factory()->for($user)->for($bankProfile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        ImportedTransaction::factory()->for($bankImport)->create([
            'amount' => 100.00, // Positive = income
            'is_duplicate' => false,
        ]);

        ImportedTransaction::factory()->for($bankImport)->create([
            'amount' => -50.00, // Negative = expense
            'is_duplicate' => false,
        ]);

        // Test credit card statement
        $ccProfile = BankProfile::factory()->create(['statement_type' => 'credit_card']);
        $ccImport = BankStatementImport::factory()->for($user)->for($ccProfile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        ImportedTransaction::factory()->for($ccImport)->create([
            'amount' => 75.00, // Positive = income (payment/refund)
            'is_duplicate' => false,
        ]);

        ImportedTransaction::factory()->for($ccImport)->create([
            'amount' => -25.00, // Negative = expense (purchase)
            'is_duplicate' => false,
        ]);

        $bankCommitter = new StatementImportCommitter($bankImport);
        $ccCommitter = new StatementImportCommitter($ccImport);

        $bankCommitter->commit();
        $ccCommitter->commit();

        $transactions = Transaction::where('user_id', $user->id)->get();
        $this->assertCount(4, $transactions);

        $bankIncome = $transactions->where('amount', 100.00)->where('type', Transaction::TYPE_INCOME)->first();
        $bankExpense = $transactions->where('amount', 50.00)->where('type', Transaction::TYPE_EXPENSE)->first();
        $ccIncome = $transactions->where('amount', 75.00)->where('type', Transaction::TYPE_INCOME)->first();
        $ccExpense = $transactions->where('amount', 25.00)->where('type', Transaction::TYPE_EXPENSE)->first();

        // Bank: positive amount = income, negative amount = expense (now stored as positive)
        $this->assertNotNull($bankIncome);
        $this->assertNotNull($bankExpense);

        // Credit card: amounts stored as positive, type determined by imported amount sign
        $this->assertNotNull($ccIncome);
        $this->assertNotNull($ccExpense);
    }

    public function test_credit_card_expenses_are_stored_as_positive_amounts(): void
    {
        $user = User::factory()->create();
        $ccProfile = BankProfile::factory()->create(['statement_type' => 'credit_card']);
        $ccImport = BankStatementImport::factory()
            ->for($user)
            ->for($ccProfile, 'bankProfile')
            ->create(['status' => BankStatementConfig::STATUS_PARSED]);

        // Create imported transactions as they would come from the parser (already flipped)
        ImportedTransaction::factory()->for($ccImport)->create([
            'amount' => -100.00, // Expense (purchase) - negative in imported_transactions
            'description' => 'PURCHASE',
            'is_duplicate' => false,
        ]);

        ImportedTransaction::factory()->for($ccImport)->create([
            'amount' => 50.00, // Income (payment) - positive in imported_transactions
            'description' => 'PAYMENT',
            'is_duplicate' => false,
        ]);

        $statementImportCommitter = new StatementImportCommitter($ccImport);
        $result = $statementImportCommitter->commit();

        $this->assertTrue($result);

        $transactions = Transaction::where('user_id', $user->id)->get();
        $this->assertCount(2, $transactions);

        $expense = $transactions->where('type', Transaction::TYPE_EXPENSE)->first();
        $income = $transactions->where('type', Transaction::TYPE_INCOME)->first();

        // CRITICAL: Both amounts should be positive in the transactions table
        $this->assertEqualsWithDelta(100.00, $expense->amount, PHP_FLOAT_EPSILON); // Was -100, should be 100
        $this->assertEquals(Transaction::TYPE_EXPENSE, $expense->type);
        $this->assertEquals('PURCHASE', $expense->description);

        $this->assertEqualsWithDelta(50.00, $income->amount, PHP_FLOAT_EPSILON); // Should remain 50
        $this->assertEquals(Transaction::TYPE_INCOME, $income->type);
        $this->assertEquals('PAYMENT', $income->description);
    }

    public function test_fails_if_import_not_in_parsed_status(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_UPLOADED]);

        $statementImportCommitter = new StatementImportCommitter($import);
        $result = $statementImportCommitter->commit();

        $this->assertFalse($result);
        $this->assertEquals(BankStatementConfig::STATUS_UPLOADED, $import->fresh()->status);
    }

    public function test_is_idempotent(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        ImportedTransaction::factory()->for($import)->create([
            'amount' => 100.00,
            'is_duplicate' => false,
        ]);

        $statementImportCommitter = new StatementImportCommitter($import);

        // Commit twice
        $result1 = $statementImportCommitter->commit();
        $result2 = $statementImportCommitter->commit();

        $this->assertTrue($result1);
        $this->assertTrue($result2);

        // Should not create duplicate transactions
        $transactions = Transaction::where('user_id', $user->id)->get();
        $this->assertCount(1, $transactions);
        $this->assertEquals(BankStatementConfig::STATUS_COMMITTED, $import->fresh()->status);
    }

    public function test_rolls_back_on_failure(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        // category_id 99999 does not exist — Transaction::create() will fail the FK constraint
        ImportedTransaction::factory()->for($import)->create([
            'amount' => 100.00,
            'is_duplicate' => false,
            'category_id' => 99999,
        ]);

        $statementImportCommitter = new StatementImportCommitter($import);
        $result = $statementImportCommitter->commit();

        $this->assertFalse($result);
        $this->assertCount(0, Transaction::where('user_id', $user->id)->get());
        $this->assertEquals(BankStatementConfig::STATUS_PARSED, $import->fresh()->status);
    }

    public function test_get_summary_returns_correct_counts(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        ImportedTransaction::factory()->for($import)->create(['is_duplicate' => false, 'is_committed' => true, 'amount' => 100.00]);
        ImportedTransaction::factory()->for($import)->create(['is_duplicate' => false, 'is_committed' => false, 'amount' => 50.00]);
        ImportedTransaction::factory()->for($import)->create(['is_duplicate' => true, 'is_committed' => false, 'amount' => 75.00]);

        $statementImportCommitter = new StatementImportCommitter($import);
        $summary = $statementImportCommitter->getSummary();

        $this->assertEquals(3, $summary['total']);
        $this->assertEquals(1, $summary['duplicates']);
        $this->assertEquals(2, $summary['new_transactions']);
        $this->assertEqualsWithDelta(150.00, $summary['total_amount'], PHP_FLOAT_EPSILON); // only non-duplicates
        $this->assertEquals(1, $summary['committed']);
    }

    public function test_deletes_csv_file_after_successful_commit(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        // Place a fake CSV file where the committer expects it
        Storage::put(sprintf('statements/%d.csv', $import->id), 'fake,csv,content');
        Storage::assertExists(sprintf('statements/%d.csv', $import->id));

        ImportedTransaction::factory()->for($import)->create(['is_duplicate' => false, 'amount' => 10.00]);

        $statementImportCommitter = new StatementImportCommitter($import);
        $result = $statementImportCommitter->commit();

        $this->assertTrue($result);
        Storage::assertMissing(sprintf('statements/%d.csv', $import->id));
    }

    public function test_commit_succeeds_when_no_csv_file_exists(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        ImportedTransaction::factory()->for($import)->create(['is_duplicate' => false, 'amount' => 10.00]);

        $statementImportCommitter = new StatementImportCommitter($import);
        $result = $statementImportCommitter->commit();

        $this->assertTrue($result); // no file to delete is fine
        $this->assertEquals(BankStatementConfig::STATUS_COMMITTED, $import->fresh()->status);
    }

    public function test_commit_succeeds_even_when_csv_delete_throws(): void
    {
        Log::spy();
        Storage::fake('local');
        Storage::shouldReceive('exists')->andReturn(true);
        Storage::shouldReceive('delete')->andThrow(new RuntimeException('Disk error'));

        $user = User::factory()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        ImportedTransaction::factory()->for($import)->create(['is_duplicate' => false, 'amount' => 10.00]);

        $statementImportCommitter = new StatementImportCommitter($import);
        $result = $statementImportCommitter->commit();

        $this->assertTrue($result);
        Log::shouldHaveReceived('warning')->once();
    }
}
