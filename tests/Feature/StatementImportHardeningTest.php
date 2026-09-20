<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeleteStatementFileJob;
use App\Jobs\SweepStatementFileCleanupJob;
use App\Livewire\Statements\StatementImportReview;
use App\Models\BankProfile;
use App\Models\BankStatementImport;
use App\Models\ImportedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BankStatement\BankStatementImportProcessor;
use App\Support\BankStatementConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

final class StatementImportHardeningTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{columns: array{date: int, description: int, amount: int}, date_format: string, has_header: bool} */
    private function config(): array
    {
        return [
            'columns' => ['date' => 0, 'description' => 1, 'amount' => 2],
            'date_format' => 'd/m/Y',
            'has_header' => true,
        ];
    }

    public function test_processing_uses_the_upload_snapshot_after_profile_changes(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create(['config' => $this->config()]);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create([
            'profile_config' => $this->config(),
        ]);

        $profile->update(['config' => [
            'columns' => ['date' => 2, 'description' => 0, 'amount' => 1],
            'has_header' => false,
        ]]);
        Storage::put(BankStatementConfig::statementPath($import->id), "Date,Description,Amount\n01/01/2026,Snapshot Row,12.34");

        $this->assertTrue(new BankStatementImportProcessor($import)->process());
        $this->assertDatabaseHas('imported_transactions', [
            'import_id' => $import->id,
            'description' => 'SNAPSHOT ROW',
            'amount' => '12.34',
        ]);
    }

    public function test_one_invalid_row_rejects_the_whole_file_and_records_counts(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create(['config' => $this->config()]);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create([
            'profile_config' => $this->config(),
        ]);
        Storage::put(BankStatementConfig::statementPath($import->id), "Date,Description,Amount\n01/01/2026,Valid,10.00\nnot-a-date,Invalid,20.00\n\n");

        $this->assertFalse(new BankStatementImportProcessor($import)->process());

        $fresh = $import->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(BankStatementConfig::STATUS_FAILED, $fresh->status);
        $this->assertSame(2, $fresh->total_rows);
        $this->assertSame(1, $fresh->valid_rows);
        $this->assertSame(1, $fresh->rejected_rows);
        $errors = $fresh->parse_errors;
        $this->assertIsArray($errors);
        $this->assertNotEmpty($errors);
        $this->assertSame(3, $errors[0]['row']);
        $this->assertCount(0, $fresh->importedTransactions);
    }

    public function test_repeated_rows_in_one_upload_are_marked_as_same_file_duplicates(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create(['config' => $this->config()]);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create([
            'profile_config' => $this->config(),
        ]);
        Storage::put(BankStatementConfig::statementPath($import->id), "Date,Description,Amount\n01/01/2026,Repeated,10.00\n01/01/2026,Repeated,10.00");

        $this->assertTrue(new BankStatementImportProcessor($import)->process());

        $rows = $import->importedTransactions()->orderBy('id')->get();
        $first = $rows->get(0);
        $second = $rows->get(1);
        $this->assertInstanceOf(ImportedTransaction::class, $first);
        $this->assertInstanceOf(ImportedTransaction::class, $second);
        $this->assertFalse($first->is_duplicate);
        $this->assertTrue($second->is_duplicate);
        $this->assertSame('same_file', $second->duplicate_reason);
    }

    public function test_another_jobs_token_cannot_reclaim_a_parsing_import(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create(['config' => $this->config()]);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create([
            'status' => BankStatementConfig::STATUS_PARSING,
            'profile_config' => $this->config(),
            'processing_token' => '00000000-0000-4000-8000-000000000001',
        ]);
        Storage::put(BankStatementConfig::statementPath($import->id), "Date,Description,Amount\n01/01/2026,Row,10.00");

        $result = new BankStatementImportProcessor($import, '00000000-0000-4000-8000-000000000002')->process();

        $this->assertFalse($result);
        $import->refresh();
        $this->assertSame(BankStatementConfig::STATUS_PARSING, $import->status);
        $this->assertCount(0, $import->importedTransactions);
    }

    public function test_review_paginates_and_commit_honours_selection_exceptions(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->parsed()->create();
        ImportedTransaction::factory()->for($import, 'bankStatementImport')->count(51)->income(10)->create();
        $importedTransaction = $import->importedTransactions()->firstOrFail();

        $testable = Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id]);

        $testable->assertViewHas('transactions', fn ($transactions): bool => $transactions->count() === 50 && $transactions->total() === 51);
        $testable
            ->call('toggleTransactionSelection', $importedTransaction->id)
            ->call('commitImport')
            ->assertRedirect(route('statements.import'));

        $this->assertCount(50, Transaction::where('user_id', $user->id)->get());
        $this->assertFalse((bool) $importedTransaction->fresh()?->is_committed);
    }

    public function test_duplicate_override_allows_explicit_commit(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->parsed()->create();
        $duplicate = ImportedTransaction::factory()->for($import, 'bankStatementImport')->duplicate()->income(15)->create();

        Livewire::actingAs($user)
            ->test(StatementImportReview::class, ['importId' => $import->id])
            ->call('toggleDuplicateOverride', $duplicate->id)
            ->call('commitImport');

        $this->assertDatabaseHas('transactions', ['user_id' => $user->id, 'amount' => '15.00']);
    }

    public function test_cleanup_sweep_dispatches_only_unfinished_committed_imports(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create();
        $pending = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->committed()->create();
        BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->committed()->create([
            'file_cleanup_status' => BankStatementConfig::CLEANUP_DELETED,
        ]);

        new SweepStatementFileCleanupJob()->handle();

        Queue::assertPushed(DeleteStatementFileJob::class, 1);
        Queue::assertPushed(DeleteStatementFileJob::class, fn (DeleteStatementFileJob $deleteStatementFileJob): bool => $deleteStatementFileJob->importId === $pending->id);
    }
}
