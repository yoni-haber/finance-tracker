<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Statements\StatementImportReview;
use App\Models\BankProfile;
use App\Models\BankStatementImport;
use App\Models\Category;
use App\Models\ImportedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BankStatement\DuplicateDetector;
use App\Support\BankStatementConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class StatementImportReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_successfully_with_valid_import(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->assertStatus(200);
    }

    public function test_redirects_if_import_not_found(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => 99999])
            ->assertRedirect(route('statements.import'));
    }

    public function test_redirects_if_user_does_not_own_import(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $profile = BankProfile::factory()->create();
        $import = BankStatementImport::factory()->for($user1)->for($profile, 'bankProfile')->create();

        Livewire::actingAs($user2)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->assertRedirect(route('statements.import'));
    }

    public function test_displays_imported_transactions(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create([
            'date' => '2026-01-01',
            'description' => 'Test Transaction',
            'amount' => 100.50,
            'is_duplicate' => false,
        ]);

        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create([
            'date' => '2026-01-02',
            'description' => 'Duplicate Transaction',
            'amount' => 50.00,
            'is_duplicate' => true,
        ]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->assertSee('Test Transaction')
            ->assertSee('Duplicate Transaction')
            ->assertSee('£100.50')
            ->assertSee('£50.00')
            ->assertSee('1 Jan 2026')
            ->assertSee('2 Jan 2026');
    }

    public function test_calculates_summary_statistics(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        // Create mix of transactions
        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => 100.00, 'is_duplicate' => false]);
        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => -50.00, 'is_duplicate' => false]);
        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => 75.00, 'is_duplicate' => true]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->assertSee('3') // Total count
            ->assertSee('Total transactions')
            ->assertSee('2') // New count
            ->assertSee('Ready to import') // Unique CTA only shown when new_transactions > 0
            ->assertSee('1') // Duplicate count
            ->assertSee('Duplicates (skipped)');
    }

    public function test_edits_transaction_successfully(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->income()->create(['name' => 'Test Category']);
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        $transaction = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create([
            'date' => '2026-01-01',
            'description' => 'Original Description',
            'amount' => 100.00,
        ]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('editTransaction', $transaction->id)
            ->assertSet('editingTransactionId', $transaction->id)
            ->set('editForm.description', 'Updated Description')
            ->set('editForm.amount', '150.00')
            ->set('editForm.type', Transaction::TYPE_INCOME)
            ->set('editForm.category_id', $category->id)
            ->call('updateTransaction')
            ->assertSet('editingTransactionId', null);

        $transaction->refresh();
        $this->assertEquals('UPDATED DESCRIPTION', $transaction->description);
        $this->assertEqualsWithDelta(150.00, $transaction->amount, PHP_FLOAT_EPSILON);
        $this->assertEquals($category->id, $transaction->category_id);
    }

    public function test_applies_transaction_type_correctly_for_bank_statements(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        $transaction = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => 100.00]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('editTransaction', $transaction->id)
            ->set('editForm.amount', '100.00')
            ->set('editForm.type', Transaction::TYPE_EXPENSE) // Change to expense
            ->call('updateTransaction');

        $transaction->refresh();
        $this->assertEquals(-100.00, $transaction->amount); // Should be negative for bank expense
    }

    public function test_applies_transaction_type_correctly_for_credit_card_statements(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'credit_card']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        $transaction = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => -100.00]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('editTransaction', $transaction->id)
            ->set('editForm.amount', '100.00')
            ->set('editForm.type', Transaction::TYPE_INCOME) // Change to income
            ->call('updateTransaction');

        $transaction->refresh();
        $this->assertEqualsWithDelta(100.00, $transaction->amount, PHP_FLOAT_EPSILON); // Should be positive for credit card income
    }

    public function test_updates_transaction_category(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->income()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        // Positive amount on a bank statement = income, matching the income category above.
        $transaction = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => 50.00]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('updateCategory', $transaction->id, $category->id);

        $transaction->refresh();
        $this->assertEquals($category->id, $transaction->category_id);
    }

    public function test_updates_transaction_type(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        $transaction = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => 100.00]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('updateType', $transaction->id, Transaction::TYPE_EXPENSE);

        $transaction->refresh();
        $this->assertEquals(-100.00, $transaction->amount); // Should flip to negative for expense
    }

    public function test_commits_import_successfully(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();
        $profile = BankProfile::factory()->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create([
            'amount' => 100.00,
            'is_duplicate' => false,
            'category_id' => $category->id,
        ]);

        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create([
            'amount' => 50.00,
            'is_duplicate' => true, // Should not be committed
        ]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('commitImport')
            ->assertRedirect(route('statements.import'));

        // Check import status
        $import->refresh();
        $this->assertEquals(BankStatementConfig::STATUS_COMMITTED, $import->status);

        // Check transactions were created
        $transactions = Transaction::where('user_id', $user->id)->get();
        $this->assertCount(1, $transactions); // Only non-duplicate

        $transaction = $transactions->first();
        $this->assertEqualsWithDelta(100.00, $transaction->amount, PHP_FLOAT_EPSILON);
        $this->assertTrue($transaction->category->is($category));
    }

    public function test_cancels_commit_confirmation(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->assertSee('Import');
    }

    public function test_validates_edit_form_fields(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        $transaction = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create();

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('editTransaction', $transaction->id)
            ->set('editForm.description', '') // Required
            ->set('editForm.amount', 'invalid') // Not numeric
            ->set('editForm.date', 'invalid-date') // Invalid date
            ->call('updateTransaction')
            ->assertHasErrors(['editForm.description', 'editForm.amount', 'editForm.date']);
    }

    public function test_prevents_commit_if_import_not_parsed(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_UPLOADED]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->assertRedirect(route('statements.import'));
    }

    public function test_shows_proper_transaction_types_based_on_amounts(): void
    {
        $user = User::factory()->create();
        $bankProfile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $ccProfile = BankProfile::factory()->create(['statement_type' => 'credit_card']);

        $bankImport = BankStatementImport::factory()->for($user)->for($bankProfile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);
        $ccImport = BankStatementImport::factory()->for($user)->for($ccProfile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        ImportedTransaction::factory()->for($bankImport, 'bankStatementImport')->create(['amount' => 100.00]);
        ImportedTransaction::factory()->for($bankImport, 'bankStatementImport')->create(['amount' => -50.00]);
        ImportedTransaction::factory()->for($ccImport, 'bankStatementImport')->create(['amount' => 75.00]);
        ImportedTransaction::factory()->for($ccImport, 'bankStatementImport')->create(['amount' => -25.00]);

        // Bank statement: positive = income, negative = expense
        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $bankImport->id])
            ->assertSee('Income') // For positive amount
            ->assertSee('Expense'); // For negative amount

        // Credit card: positive = income, negative = expense (amounts already transformed)
        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $ccImport->id])
            ->assertSee('Income') // For positive amount
            ->assertSee('Expense'); // For negative amount
    }

    public function test_back_to_import_redirects_correctly(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->parsed()->create();

        // Add at least one transaction to avoid edge cases
        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create();

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('backToImport')
            ->assertRedirect(route('statements.import'));
    }

    public function test_regenerates_hash_when_transaction_updated(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        $transaction = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create([
            'date' => '2026-01-01',
            'description' => 'Original Description',
            'amount' => 100.00,
        ]);

        $originalHash = $transaction->hash;

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('editTransaction', $transaction->id)
            ->set('editForm.description', 'Updated Description')
            ->set('editForm.amount', '150.00')
            ->set('editForm.type', Transaction::TYPE_INCOME)
            ->call('updateTransaction');

        $transaction->refresh();
        $this->assertNotEquals($originalHash, $transaction->hash);
        $this->assertNotEmpty($transaction->hash);
    }

    public function test_hash_uses_normalized_description_after_edit(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        $transaction = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create([
            'date' => '2026-01-01',
            'description' => 'ORIGINAL',
            'amount' => 100.00,
        ]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('editTransaction', $transaction->id)
            ->set('editForm.description', 'my purchase') // lowercase input
            ->set('editForm.amount', '100.00')
            ->set('editForm.type', Transaction::TYPE_INCOME)
            ->call('updateTransaction');

        $transaction->refresh();

        // Description should be uppercased
        $this->assertEquals('MY PURCHASE', $transaction->description);

        // Hash must match what would be generated from the normalized (uppercased) description
        $duplicateDetector = new DuplicateDetector($user->id);
        $expectedHash = $duplicateDetector->generateTransactionHash($user->id, '2026-01-01', 100.00, 'MY PURCHASE');
        $this->assertEquals($expectedHash, $transaction->hash);
    }

    public function test_description_internal_whitespace_is_collapsed_on_edit(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        $transaction = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create([
            'date' => '2026-01-01',
            'description' => 'ORIGINAL',
            'amount' => 100.00,
        ]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('editTransaction', $transaction->id)
            ->set('editForm.description', '  tesco   extra  ') // extra internal and surrounding whitespace
            ->set('editForm.amount', '100.00')
            ->set('editForm.type', Transaction::TYPE_INCOME)
            ->call('updateTransaction');

        $transaction->refresh();

        // Str::squish collapses internal whitespace; should match parser output
        $this->assertEquals('TESCO EXTRA', $transaction->description);

        // Hash must be identical to what the parser would produce for the same raw description
        $duplicateDetector = new DuplicateDetector($user->id);
        $expectedHash = $duplicateDetector->generateTransactionHash($user->id, '2026-01-01', 100.00, 'TESCO EXTRA');
        $this->assertEquals($expectedHash, $transaction->hash);
    }

    public function test_category_validation_is_scoped_to_authenticated_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherCategory = Category::factory()->for($otherUser)->create();

        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);
        $transaction = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => 50.00]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('editTransaction', $transaction->id)
            ->set('editForm.category_id', $otherCategory->id)
            ->call('updateTransaction')
            ->assertHasErrors(['editForm.category_id']);
    }

    public function test_confirm_delete_sets_deleting_transaction_id(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);
        $transaction = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['is_duplicate' => false]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('confirmDeleteTransaction', $transaction->id)
            ->assertSet('deletingTransactionId', $transaction->id)
            ->assertDispatched('open-delete-modal');
    }

    public function test_delete_transaction_removes_record_and_closes_modal(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);
        $transaction = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['is_duplicate' => false]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('confirmDeleteTransaction', $transaction->id)
            ->call('deleteTransaction')
            ->assertSet('deletingTransactionId', null)
            ->assertDispatched('close-delete-modal');

        $this->assertDatabaseMissing('imported_transactions', ['id' => $transaction->id]);
    }

    public function test_cancel_edit_clears_state(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);
        $transaction = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => 50.00]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('editTransaction', $transaction->id)
            ->assertSet('editingTransactionId', $transaction->id)
            ->call('cancelEdit')
            ->assertSet('editingTransactionId', null)
            ->assertSet('editForm', []);
    }

    public function test_delete_transaction_with_null_deleting_id_only_closes_modal(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);
        $transaction = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create();

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('deleteTransaction') // no confirmDeleteTransaction called first
            ->assertSet('deletingTransactionId', null)
            ->assertDispatched('close-delete-modal');

        $this->assertDatabaseHas('imported_transactions', ['id' => $transaction->id]);
    }

    public function test_update_category_to_null_clears_it(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);
        $transaction = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['category_id' => $category->id]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('updateCategory', $transaction->id, null);

        $this->assertNull($transaction->fresh()->category_id);
    }

    public function test_update_category_rejects_another_users_category(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherCategory = Category::factory()->for($otherUser)->create();

        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);
        $transaction = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['category_id' => null]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('updateCategory', $transaction->id, $otherCategory->id)
            ->assertHasErrors(['categoryId']);

        $this->assertNull($transaction->fresh()->category_id);
    }

    public function test_mount_redirects_when_import_is_not_parsed(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_UPLOADED]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->assertRedirect(route('statements.import'));
    }

    public function test_update_type_to_income_makes_amount_positive(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        $transaction = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => -100.00]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('updateType', $transaction->id, Transaction::TYPE_INCOME);

        $this->assertEqualsWithDelta(100.00, $transaction->fresh()->amount, PHP_FLOAT_EPSILON);
    }

    public function test_determine_transaction_type_for_credit_card_positive_amount_is_income(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'credit_card']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        $transaction = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => 50.00]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('editTransaction', $transaction->id)
            ->assertSet('editForm.type', Transaction::TYPE_INCOME);
    }

    public function test_commit_import_adds_error_when_import_not_parsed_at_call_time(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create();

        $testable = Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id]);

        // Change status after mount so the guard inside commitImport() is hit directly
        $import->update(['status' => BankStatementConfig::STATUS_UPLOADED]);

        $testable->call('commitImport')
            ->assertHasErrors(['commit']);
    }

    public function test_update_category_rejects_category_with_mismatched_type(): void
    {
        $user = User::factory()->create();

        // Expense category, but the transaction will be income (positive on bank statement).
        $expenseCategory = Category::factory()->for($user)->expense()->create();

        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create([
            'status' => BankStatementConfig::STATUS_PARSED,
        ]);

        // Positive amount on a bank statement = income.
        $transaction = ImportedTransaction::factory()
            ->for($import, 'bankStatementImport')
            ->create(['amount' => 75.00]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('updateCategory', $transaction->id, $expenseCategory->id)
            ->assertHasErrors();

        // Category should not have been persisted.
        $transaction->refresh();
        $this->assertNull($transaction->category_id);
    }
}
