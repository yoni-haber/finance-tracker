<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\BankProfile;
use App\Models\BankStatementImport;
use App\Models\ImportedTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ImportedTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_imported_transaction_belongs_to_bank_statement_import(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();
        $transaction = ImportedTransaction::factory()->for($import)->create();

        $this->assertInstanceOf(BankStatementImport::class, $transaction->bankStatementImport);
        $this->assertTrue($transaction->bankStatementImport->is($import));
    }

    public function test_imported_transaction_has_required_fillable_fields(): void
    {
        $importedTransaction = new ImportedTransaction();
        $expectedFillable = [
            'import_id',
            'date',
            'description',
            'amount',
            'external_id',
            'category_id',
            'hash',
            'original_hash',
            'is_duplicate',
            'is_committed',
        ];

        $this->assertSame($expectedFillable, $importedTransaction->getFillable());
    }

    public function test_imported_transaction_casts_fields_correctly(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();

        $transaction = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create([
            'date' => '2026-01-04',
            'amount' => 123.45,
            'is_duplicate' => true,
            'is_committed' => false,
        ]);

        $this->assertInstanceOf(Carbon::class, $transaction->date);
        $this->assertSame('123.45', $transaction->amount);
        $this->assertTrue($transaction->is_duplicate);
        $this->assertFalse($transaction->is_committed);
    }

    public function test_duplicate_scope_filters_correctly(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();

        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['is_duplicate' => true]);
        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['is_duplicate' => false]);
        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['is_duplicate' => true]);

        $this->assertCount(2, ImportedTransaction::where('is_duplicate', true)->get());
        $this->assertCount(1, ImportedTransaction::where('is_duplicate', false)->get());
    }

    public function test_committed_scope_filters_correctly(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();

        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['is_committed' => true]);
        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['is_committed' => false]);
        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['is_committed' => true]);

        $this->assertCount(2, ImportedTransaction::where('is_committed', true)->get());
        $this->assertCount(1, ImportedTransaction::where('is_committed', false)->get());
    }

    public function test_hash_uniqueness_within_import(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create();
        $import1 = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();
        $import2 = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();

        $hash = 'unique_transaction_hash';

        // Should be able to have same hash in different imports
        ImportedTransaction::factory()->for($import1, 'bankStatementImport')->create(['hash' => $hash]);
        ImportedTransaction::factory()->for($import2, 'bankStatementImport')->create(['hash' => $hash]);

        $this->assertCount(2, ImportedTransaction::where('hash', $hash)->get());
    }

    public function test_scope_not_duplicate(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();

        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['is_duplicate' => true]);
        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['is_duplicate' => false]);

        $notDuplicateTransactions = ImportedTransaction::notDuplicate()->get();

        $this->assertCount(1, $notDuplicateTransactions);
        $this->assertFalse($notDuplicateTransactions->first()->is_duplicate);
    }

    public function test_scope_not_committed(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();

        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['is_committed' => true]);
        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['is_committed' => false]);

        $notCommittedTransactions = ImportedTransaction::notCommitted()->get();

        $this->assertCount(1, $notCommittedTransactions);
        $this->assertFalse($notCommittedTransactions->first()->is_committed);
    }

    public function test_scope_committable(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();

        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['is_duplicate' => true, 'is_committed' => false]);
        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['is_duplicate' => false, 'is_committed' => true]);
        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['is_duplicate' => false, 'is_committed' => false]);

        $committableTransactions = ImportedTransaction::committable()->get();

        $this->assertCount(1, $committableTransactions);
        $this->assertFalse($committableTransactions->first()->is_duplicate);
        $this->assertFalse($committableTransactions->first()->is_committed);
    }
}
