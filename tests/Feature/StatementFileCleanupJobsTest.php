<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeleteStatementFileJob;
use App\Jobs\SweepStatementFileCleanupJob;
use App\Models\BankProfile;
use App\Models\BankStatementImport;
use App\Models\User;
use App\Support\BankStatement\StatementFileCleaner;
use App\Support\BankStatementConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

final class StatementFileCleanupJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_job_exposes_the_expected_retry_policy(): void
    {
        $job = new DeleteStatementFileJob(42);

        $this->assertSame(42, $job->importId);
        $this->assertSame(5, $job->tries);
        $this->assertSame([60, 300, 900, 3600], $job->backoff);
    }

    public function test_delete_job_is_a_no_op_when_the_import_no_longer_exists(): void
    {
        $cleaner = Mockery::mock(StatementFileCleaner::class);
        $cleaner->shouldNotReceive('delete');

        new DeleteStatementFileJob(999999)->handle($cleaner);

        $this->addToAssertionCount(1);
    }

    public function test_delete_job_is_a_no_op_when_cleanup_already_completed(): void
    {
        $import = $this->makeImport([
            'file_cleanup_status' => BankStatementConfig::CLEANUP_DELETED,
        ]);
        $cleaner = Mockery::mock(StatementFileCleaner::class);
        $cleaner->shouldNotReceive('delete');

        new DeleteStatementFileJob($import->id)->handle($cleaner);

        $this->addToAssertionCount(1);
    }

    public function test_delete_job_passes_an_unfinished_import_to_the_cleaner(): void
    {
        $import = $this->makeImport([
            'file_cleanup_status' => BankStatementConfig::CLEANUP_FAILED,
        ]);
        $cleaner = Mockery::mock(StatementFileCleaner::class);
        $cleaner->shouldReceive('delete')
            ->once()
            ->with(Mockery::on(fn (BankStatementImport $candidate): bool => $candidate->is($import)));

        new DeleteStatementFileJob($import->id)->handle($cleaner);

        $this->addToAssertionCount(1);
    }

    public function test_sweep_filters_by_both_commit_and_cleanup_status(): void
    {
        Queue::fake();
        $pending = $this->makeImport([
            'status' => BankStatementConfig::STATUS_COMMITTED,
            'file_cleanup_status' => BankStatementConfig::CLEANUP_PENDING,
        ]);
        $failed = $this->makeImport([
            'status' => BankStatementConfig::STATUS_COMMITTED,
            'file_cleanup_status' => BankStatementConfig::CLEANUP_FAILED,
        ]);
        $this->makeImport([
            'status' => BankStatementConfig::STATUS_COMMITTED,
            'file_cleanup_status' => BankStatementConfig::CLEANUP_DELETED,
        ]);
        $this->makeImport([
            'status' => BankStatementConfig::STATUS_PARSED,
            'file_cleanup_status' => BankStatementConfig::CLEANUP_PENDING,
        ]);

        new SweepStatementFileCleanupJob()->handle();

        Queue::assertPushed(DeleteStatementFileJob::class, 2);
        Queue::assertPushed(DeleteStatementFileJob::class, fn (DeleteStatementFileJob $job): bool => $job->importId === $pending->id);
        Queue::assertPushed(DeleteStatementFileJob::class, fn (DeleteStatementFileJob $job): bool => $job->importId === $failed->id);
    }

    public function test_sweep_uses_batches_of_exactly_one_hundred_rows(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create();
        BankStatementImport::factory()->count(100)->for($user)->for($profile, 'bankProfile')->committed()->create();

        $selects = 0;
        DB::listen(function ($query) use (&$selects): void {
            if (str_contains($query->sql, 'from `bank_statement_imports`') && str_contains($query->sql, 'order by `id` asc')) {
                $selects++;
            }
        });

        new SweepStatementFileCleanupJob()->handle();

        Queue::assertPushed(DeleteStatementFileJob::class, 100);
        $this->assertSame(2, $selects);
    }

    public function test_sweep_does_not_request_a_second_batch_for_ninety_nine_rows(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create();
        BankStatementImport::factory()->count(99)->for($user)->for($profile, 'bankProfile')->committed()->create();

        $selects = 0;
        DB::listen(function ($query) use (&$selects): void {
            if (str_contains($query->sql, 'from `bank_statement_imports`') && str_contains($query->sql, 'order by `id` asc')) {
                $selects++;
            }
        });

        new SweepStatementFileCleanupJob()->handle();

        Queue::assertPushed(DeleteStatementFileJob::class, 99);
        $this->assertSame(1, $selects);
    }

    /** @param array<string, mixed> $attributes */
    private function makeImport(array $attributes): BankStatementImport
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create();

        return BankStatementImport::factory()
            ->for($user)
            ->for($profile, 'bankProfile')
            ->create($attributes);
    }
}
