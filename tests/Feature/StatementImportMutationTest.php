<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Statements\StatementImportReview;
use App\Models\BankProfile;
use App\Models\BankStatementImport;
use App\Models\Category;
use App\Models\ImportedTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class StatementImportMutationTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_selection_skips_income_and_expense_probe_queries(): void
    {
        $user = User::factory()->create();
        $import = $this->parsedImport($user);
        $probeCount = 0;
        DB::listen(static function ($query) use (&$probeCount): void {
            if (str_contains($query->sql, 'amount') && str_contains($query->sql, 'exists')) {
                $probeCount++;
            }
        });

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->assertViewHas('selectedCount', 0);

        $this->assertSame(0, $probeCount);
    }

    public function test_selection_rejects_duplicate_rows_without_override(): void
    {
        $user = User::factory()->create();
        $import = $this->parsedImport($user);
        $duplicate = ImportedTransaction::factory()->for($import, 'bankStatementImport')->duplicate()->create();

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('toggleTransactionSelection', $duplicate->id);
    }

    public function test_selection_removes_the_first_exception_without_dropping_later_ids(): void
    {
        $user = User::factory()->create();
        $import = $this->parsedImport($user);
        $first = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create();
        $second = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create();

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->set('selectionExceptionIds', [$first->id, $second->id])
            ->call('toggleTransactionSelection', $first->id)
            ->assertSet('selectionExceptionIds', [$second->id])
            ->assertViewHas('selectedCount', 1);
    }

    public function test_bulk_assignment_rejects_mixed_zero_and_fractional_expense_rows(): void
    {
        $user = User::factory()->create();
        $import = $this->parsedImport($user);
        $incomeCategory = Category::factory()->for($user)->income()->create();
        $zero = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => '0.00']);
        $expense = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => '-0.50']);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->set('selectAllTransactions', false)
            ->set('selectionExceptionIds', [$zero->id, $expense->id])
            ->call('bulkAssignCategory', $incomeCategory->id)
            ->assertHasErrors(['bulk_assign']);

        $this->assertNull($zero->fresh()?->category_id);
        $this->assertNull($expense->fresh()?->category_id);
    }

    public function test_bulk_assignment_accepts_fractional_income_and_reports_one_transaction(): void
    {
        $user = User::factory()->create();
        $import = $this->parsedImport($user);
        $category = Category::factory()->for($user)->income()->create();
        $income = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => '0.50']);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('bulkAssignCategory', $category->id)
            ->assertHasNoErrors()
            ->assertSet('selectAllTransactions', false)
            ->assertSee('Category assigned to 1 transaction.');

        $this->assertSame($category->id, $income->fresh()?->category_id);
    }

    public function test_bulk_assignment_reports_two_transactions_in_plural(): void
    {
        $user = User::factory()->create();
        $import = $this->parsedImport($user);
        $category = Category::factory()->for($user)->expense()->create();
        $first = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => '-0.50']);
        $second = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['amount' => '-0.25']);

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('bulkAssignCategory', $category->id)
            ->assertHasNoErrors()
            ->assertSee('Category assigned to 2 transactions.');

        $this->assertSame($category->id, $first->fresh()?->category_id);
        $this->assertSame($category->id, $second->fresh()?->category_id);
    }

    public function test_single_delete_resets_review_page(): void
    {
        $user = User::factory()->create();
        $import = $this->parsedImport($user);
        $row = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create();

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->set('paginators.page', 2)
            ->call('confirmDeleteTransaction', $row->id)
            ->call('deleteTransaction')
            ->assertSet('paginators.page', 1);

        $this->assertDatabaseMissing('imported_transactions', ['id' => $row->id]);
    }

    public function test_bulk_delete_resets_review_page(): void
    {
        $user = User::factory()->create();
        $import = $this->parsedImport($user);
        $row = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create();

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->set('paginators.page', 2)
            ->call('bulkDeleteTransactions')
            ->assertSet('paginators.page', 1);

        $this->assertDatabaseMissing('imported_transactions', ['id' => $row->id]);
    }

    private function parsedImport(User $user): BankStatementImport
    {
        $profile = BankProfile::factory()->for($user)->create();

        return BankStatementImport::factory()
            ->for($user)
            ->for($profile, 'bankProfile')
            ->parsed()
            ->create();
    }
}
