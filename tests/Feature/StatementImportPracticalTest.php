<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Statements\StatementImportManager;
use App\Livewire\Statements\StatementImportReview;
use App\Models\BankProfile;
use App\Models\BankStatementImport;
use App\Models\Category;
use App\Models\ImportedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BankStatement\BankStatementImportProcessor;
use App\Support\BankStatementConfig;
use App\Support\StatementImportCommitter;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

final class StatementImportPracticalTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_bad_row_rejects_the_whole_import_and_shows_the_reason(): void
    {
        $user = User::factory()->create();
        $profile = $this->profile($user);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();
        Storage::fake('local');
        Storage::put(BankStatementConfig::statementPath($import->id), "Date,Description,Amount\n01/01/2026,Valid,12.34\n02/01/2026,Bad,not-money");

        $this->assertFalse(new BankStatementImportProcessor($import)->process());
        $this->assertSame(BankStatementConfig::STATUS_FAILED, $import->fresh()?->status);
        $this->assertSame('Data row 2: Amount is missing, zero, or invalid.', $import->fresh()?->error_message);
        $this->assertDatabaseCount('imported_transactions', 0);

        Livewire::actingAs($user)->test(StatementImportManager::class)
            ->assertSee('Data row 2: Amount is missing, zero, or invalid.');
    }

    public function test_invalid_calendar_date_and_empty_statement_are_not_accepted(): void
    {
        $user = User::factory()->create();
        $profile = $this->profile($user);
        Storage::fake('local');

        $invalid = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();
        Storage::put(BankStatementConfig::statementPath($invalid->id), "Date,Description,Amount\n31/02/2026,Impossible,10.00");
        $this->assertFalse(new BankStatementImportProcessor($invalid)->process());
        $this->assertSame('Data row 1: Date is missing or invalid.', $invalid->fresh()?->error_message);

        $empty = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();
        Storage::put(BankStatementConfig::statementPath($empty->id), "Date,Description,Amount\n");
        $this->assertFalse(new BankStatementImportProcessor($empty)->process());
        $this->assertSame('The statement contains no data rows.', $empty->fresh()?->error_message);
    }

    public function test_repeated_rows_in_one_file_are_marked_duplicate(): void
    {
        $user = User::factory()->create();
        $profile = $this->profile($user);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();
        Storage::fake('local');
        Storage::put(BankStatementConfig::statementPath($import->id), "Date,Description,Amount\n01/01/2026,Same,10.00\n01/01/2026,Same,10.00");

        $this->assertTrue(new BankStatementImportProcessor($import)->process());
        $rows = $import->importedTransactions()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertFalse($rows[0]->is_duplicate);
        $this->assertTrue($rows[1]->is_duplicate);
    }

    public function test_duplicate_can_be_included_explicitly(): void
    {
        $user = User::factory()->create();
        $profile = $this->profile($user);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->parsed()->create();
        $duplicate = ImportedTransaction::factory()->for($import, 'bankStatementImport')->duplicate()->create();

        Livewire::actingAs($user)->test(StatementImportReview::class, ['importId' => $import->id])
            ->assertSee('Include anyway')
            ->call('includeDuplicate', $duplicate->id)
            ->assertSee('Ready to import');

        $this->assertFalse($duplicate->fresh()?->is_duplicate);
    }

    public function test_type_change_clears_incompatible_category_and_rejects_invalid_type(): void
    {
        $user = User::factory()->create();
        $profile = $this->profile($user);
        $category = Category::factory()->for($user)->expense()->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->parsed()->create();
        $row = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create([
            'amount' => '-10.00',
            'category_id' => $category->id,
        ]);

        Livewire::actingAs($user)->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('updateType', $row->id, Transaction::TYPE_INCOME)
            ->assertHasNoErrors()
            ->call('updateType', $row->id, 'transfer')
            ->assertHasErrors(['type']);

        $this->assertNull($row->fresh()?->category_id);
        $this->assertSame('10.00', $row->fresh()?->amount);
    }

    public function test_commit_rolls_back_when_source_file_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $profile = $this->profile($user);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->parsed()->create();
        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create();
        $path = BankStatementConfig::statementPath($import->id);
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('exists')->once()->with($path)->andReturn(true);
        $filesystem->shouldReceive('delete')->once()->with($path)->andReturn(false);
        Storage::shouldReceive('disk')->once()->with(BankStatementConfig::statementsDisk())->andReturn($filesystem);

        $this->assertFalse(new StatementImportCommitter($import)->commit());
        $this->assertSame(BankStatementConfig::STATUS_PARSED, $import->fresh()?->status);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_cancellation_keeps_import_when_source_file_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $profile = $this->profile($user);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->parsed()->create();
        $row = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create();
        $path = BankStatementConfig::statementPath($import->id);
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('exists')->once()->with($path)->andReturn(true);
        $filesystem->shouldReceive('delete')->once()->with($path)->andReturn(false);
        Storage::shouldReceive('disk')->once()->with(BankStatementConfig::statementsDisk())->andReturn($filesystem);

        Livewire::actingAs($user)->test(StatementImportManager::class)
            ->call('cancelImport')
            ->assertSet('currentImport.id', $import->id)
            ->assertSee('Failed to delete import. Please try again.');

        $this->assertDatabaseHas('bank_statement_imports', ['id' => $import->id]);
        $this->assertDatabaseHas('imported_transactions', ['id' => $row->id]);
    }

    private function profile(User $user): BankProfile
    {
        return BankProfile::factory()->for($user)->create([
            'config' => [
                'columns' => ['date' => 0, 'description' => 1, 'amount' => 2],
                'date_format' => 'd/m/Y',
                'has_header' => true,
            ],
        ]);
    }
}
