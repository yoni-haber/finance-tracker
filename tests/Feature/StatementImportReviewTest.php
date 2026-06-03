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
        $this->assertNotNull($transaction);
        $this->assertEqualsWithDelta(100.00, $transaction->amount, PHP_FLOAT_EPSILON);
        $this->assertNotNull($transaction->category);
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

        $freshTx = $transaction->fresh();
        $this->assertNotNull($freshTx);
        $this->assertNull($freshTx->category_id);
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

        $freshTx = $transaction->fresh();
        $this->assertNotNull($freshTx);
        $this->assertNull($freshTx->category_id);
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

        $freshTx = $transaction->fresh();
        $this->assertNotNull($freshTx);
        $this->assertEqualsWithDelta(100.00, $freshTx->amount, PHP_FLOAT_EPSILON);
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

    public function test_can_bulk_assign_category_to_selected_transactions(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->income()->create(['name' => 'Salary']);
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        // Positive amount on a bank statement = income
        $tx1 = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => 100.00, 'is_duplicate' => false]);
        $tx2 = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => 200.00, 'is_duplicate' => false]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->set('selectedTransactionIds', [$tx1->id, $tx2->id])
            ->call('bulkAssignCategory', $category->id)
            ->assertHasNoErrors()
            ->assertSet('selectedTransactionIds', []);

        $fresh1 = $tx1->fresh();
        $fresh2 = $tx2->fresh();
        $this->assertNotNull($fresh1);
        $this->assertNotNull($fresh2);
        $this->assertEquals($category->id, $fresh1->category_id);
        $this->assertEquals($category->id, $fresh2->category_id);
    }

    public function test_bulk_assign_category_clears_selection_on_success(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->income()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        $tx = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => 50.00, 'is_duplicate' => false]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->set('selectedTransactionIds', [$tx->id])
            ->call('bulkAssignCategory', $category->id)
            ->assertSet('selectedTransactionIds', []);
    }

    public function test_bulk_assign_category_fails_when_selected_transactions_have_mixed_types(): void
    {
        $user = User::factory()->create();
        $incomeCategory = Category::factory()->for($user)->income()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        // Bank statement: positive = income, negative = expense
        $incomeTx = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => 100.00, 'is_duplicate' => false]);
        $expenseTx = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => -50.00, 'is_duplicate' => false]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->set('selectedTransactionIds', [$incomeTx->id, $expenseTx->id])
            ->call('bulkAssignCategory', $incomeCategory->id)
            ->assertHasErrors(['bulk_assign']);

        $freshIncome = $incomeTx->fresh();
        $freshExpense = $expenseTx->fresh();
        $this->assertNotNull($freshIncome);
        $this->assertNotNull($freshExpense);
        $this->assertNull($freshIncome->category_id);
        $this->assertNull($freshExpense->category_id);
    }

    public function test_bulk_assign_category_fails_when_category_type_does_not_match_transactions(): void
    {
        $user = User::factory()->create();
        $incomeCategory = Category::factory()->for($user)->income()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        // Negative amounts on bank statement = expense
        $tx1 = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => -100.00, 'is_duplicate' => false]);
        $tx2 = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => -50.00, 'is_duplicate' => false]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->set('selectedTransactionIds', [$tx1->id, $tx2->id])
            ->call('bulkAssignCategory', $incomeCategory->id) // income category on expense transactions
            ->assertHasErrors(['bulk_assign']);

        $fresh1 = $tx1->fresh();
        $fresh2 = $tx2->fresh();
        $this->assertNotNull($fresh1);
        $this->assertNotNull($fresh2);
        $this->assertNull($fresh1->category_id);
        $this->assertNull($fresh2->category_id);
    }

    public function test_bulk_assign_category_rejects_another_users_category(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherCategory = Category::factory()->for($otherUser)->income()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        $tx = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => 100.00, 'is_duplicate' => false]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->set('selectedTransactionIds', [$tx->id])
            ->call('bulkAssignCategory', $otherCategory->id)
            ->assertHasErrors(['bulk_assign']);

        $freshTx = $tx->fresh();
        $this->assertNotNull($freshTx);
        $this->assertNull($freshTx->category_id);
    }

    public function test_confirm_bulk_delete_dispatches_open_modal_event(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);
        $tx = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['is_duplicate' => false]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->set('selectedTransactionIds', [$tx->id])
            ->call('confirmBulkDelete')
            ->assertDispatched('open-bulk-delete-modal');
    }

    public function test_bulk_delete_removes_selected_transactions(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        $tx1 = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['is_duplicate' => false]);
        $tx2 = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['is_duplicate' => false]);
        $tx3 = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['is_duplicate' => false]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->set('selectedTransactionIds', [$tx1->id, $tx2->id])
            ->call('bulkDeleteTransactions')
            ->assertSet('selectedTransactionIds', [])
            ->assertDispatched('close-bulk-delete-modal');

        $this->assertDatabaseMissing('imported_transactions', ['id' => $tx1->id]);
        $this->assertDatabaseMissing('imported_transactions', ['id' => $tx2->id]);
        $this->assertDatabaseHas('imported_transactions', ['id' => $tx3->id]);
    }

    public function test_bulk_delete_only_deletes_transactions_belonging_to_this_import(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import1 = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);
        $import2 = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        $tx1 = ImportedTransaction::factory()->for($import1, 'bankStatementImport')->create(['is_duplicate' => false]);
        $txOther = ImportedTransaction::factory()->for($import2, 'bankStatementImport')->create(['is_duplicate' => false]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import1->id])
            ->set('selectedTransactionIds', [$tx1->id, $txOther->id]) // $txOther belongs to a different import
            ->call('bulkDeleteTransactions');

        $this->assertDatabaseMissing('imported_transactions', ['id' => $tx1->id]);
        $this->assertDatabaseHas('imported_transactions', ['id' => $txOther->id]); // should not be touched
    }

    public function test_per_row_category_dropdown_only_shows_matching_type_categories(): void
    {
        $user = User::factory()->create();
        Category::factory()->for($user)->income()->create(['name' => 'My Salary']);
        Category::factory()->for($user)->expense()->create(['name' => 'My Groceries']);
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        // Positive bank amount = income
        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => 100.00, 'is_duplicate' => false]);

        $testable = Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id]);

        $testable->assertSee('My Salary');
        $testable->assertDontSee('My Groceries');
    }

    public function test_edit_form_category_dropdown_filters_by_edit_form_type(): void
    {
        $user = User::factory()->create();
        Category::factory()->for($user)->income()->create(['name' => 'My Salary']);
        Category::factory()->for($user)->expense()->create(['name' => 'My Groceries']);
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        $transaction = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => -50.00, 'is_duplicate' => false]);

        // Open edit form — transaction is expense (negative bank amount)
        $testable = Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('editTransaction', $transaction->id);

        // editForm.type is 'expense' — only expense categories should appear
        $testable->assertSee('My Groceries');
        $testable->assertDontSee('My Salary');
    }

    public function test_bulk_toolbar_filters_categories_when_selection_is_uniform_type(): void
    {
        $user = User::factory()->create();
        Category::factory()->for($user)->income()->create(['name' => 'My Salary']);
        Category::factory()->for($user)->expense()->create(['name' => 'My Groceries']);
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        // Two expense transactions (negative bank amounts)
        $tx1 = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => -30.00, 'is_duplicate' => false]);
        $tx2 = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => -20.00, 'is_duplicate' => false]);

        // Select both — all expense, so bulkSelectionType = 'expense'
        $testable = Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->set('selectedTransactionIds', [$tx1->id, $tx2->id]);

        $testable->assertSee('My Groceries');
        $testable->assertDontSee('My Salary');
    }

    public function test_edit_rejects_category_with_mismatched_type(): void
    {
        $user = User::factory()->create();
        $incomeCategory = Category::factory()->for($user)->income()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        // Negative bank amount = expense transaction.
        $tx = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => -50.00, 'is_duplicate' => false]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('editTransaction', $tx->id)
            ->set('editForm.category_id', $incomeCategory->id)
            ->call('updateTransaction')
            ->assertHasErrors(['editForm.category_id']);
    }

    public function test_render_computes_bulk_selection_type_when_transactions_selected(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        $tx1 = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => -30.00, 'is_duplicate' => false]);
        $tx2 = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => -20.00, 'is_duplicate' => false]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->set('selectedTransactionIds', [$tx1->id, $tx2->id])
            ->assertViewHas('bulkSelectionType', Transaction::TYPE_EXPENSE);
    }

    public function test_commit_import_handles_exception_gracefully(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        // Create a transaction with an invalid category_id to trigger a DB exception in the committer.
        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create([
            'is_duplicate' => false,
            'amount' => 100.00,
            'category_id' => 99999,
        ]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('commitImport')
            ->assertHasErrors(['commit']);

        // Import should remain in parsed status since the commit failed.
        $fresh = $import->fresh();
        $this->assertNotNull($fresh);
        $this->assertEquals(BankStatementConfig::STATUS_PARSED, $fresh->status);
    }

    public function test_bulk_delete_with_empty_selection_does_nothing(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        $tx = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['is_duplicate' => false]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->set('selectedTransactionIds', [])
            ->call('bulkDeleteTransactions')
            ->assertDispatched('close-bulk-delete-modal');

        // Transaction should still exist — nothing was selected.
        $this->assertDatabaseHas('imported_transactions', ['id' => $tx->id]);
    }

    public function test_determine_transaction_type_returns_correct_types_for_credit_card_statement(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);

        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create([
            'status' => BankStatementConfig::STATUS_PARSED,
            'statement_type' => BankStatementConfig::STATEMENT_TYPE_CREDIT_CARD,
        ]);

        $transaction1 = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create([
            'date' => '2026-01-01',
            'description' => 'Original Description 1',
            'amount' => 100.00, // positive -> income
        ]);

        $transaction2 = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create([
            'date' => '2026-01-01',
            'description' => 'Original Description 2',
            'amount' => 0.00, // zero -> income
        ]);

        $transaction3 = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create([
            'date' => '2026-01-01',
            'description' => 'Original Description 3',
            'amount' => -100.00, // negative -> expense
        ]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('editTransaction', $transaction1->id)
            ->assertSet('editingTransactionId', $transaction1->id)
            ->assertSet('editForm.type', Transaction::TYPE_INCOME)

            ->call('editTransaction', $transaction2->id)
            ->assertSet('editingTransactionId', $transaction2->id)
            ->assertSet('editForm.type', Transaction::TYPE_INCOME)

            ->call('editTransaction', $transaction3->id)
            ->assertSet('editingTransactionId', $transaction3->id)
            ->assertSet('editForm.type', Transaction::TYPE_EXPENSE);
    }

    public function test_bulk_assign_category_returns_when_selected_ids_is_empty(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->income()->create();
        $profile = BankProfile::factory()->create(['statement_type' => 'bank']);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create(['status' => BankStatementConfig::STATUS_PARSED]);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->set('selectedTransactionIds', [])
            ->call('bulkAssignCategory', $category->id)
            ->assertDontSee('Category assigned to');
    }
}
