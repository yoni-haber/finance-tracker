<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BankProfile;
use App\Models\BankStatementImport;
use App\Models\User;
use App\Support\BankStatement\StatementFileCleaner;
use App\Support\BankStatementConfig;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class StatementFileCleanerTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_an_existing_file_records_completed_cleanup(): void
    {
        Storage::fake('local');
        $bankStatementImport = $this->makeImport();
        $path = BankStatementConfig::statementPath($bankStatementImport->id);
        Storage::disk('local')->put($path, 'sensitive data');

        new StatementFileCleaner()->delete($bankStatementImport);

        Storage::disk('local')->assertMissing($path);
        $fresh = $bankStatementImport->fresh();
        $this->assertInstanceOf(BankStatementImport::class, $fresh);
        $this->assertSame(BankStatementConfig::CLEANUP_DELETED, $fresh->file_cleanup_status);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->file_deleted_at);
    }

    public function test_an_already_missing_file_is_still_recorded_as_deleted(): void
    {
        Storage::fake('local');
        $bankStatementImport = $this->makeImport(['file_cleanup_status' => BankStatementConfig::CLEANUP_FAILED]);

        new StatementFileCleaner()->delete($bankStatementImport);

        $fresh = $bankStatementImport->fresh();
        $this->assertInstanceOf(BankStatementImport::class, $fresh);
        $this->assertSame(BankStatementConfig::CLEANUP_DELETED, $fresh->file_cleanup_status);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->file_deleted_at);
    }

    public function test_a_false_delete_result_records_failure_and_throws(): void
    {
        $bankStatementImport = $this->makeImport();
        /** @var Filesystem&MockInterface $mock */
        $mock = Mockery::mock(Filesystem::class);
        $mock->shouldReceive('exists')->once()->with(BankStatementConfig::statementPath($bankStatementImport->id))->andReturn(true);
        $mock->shouldReceive('delete')->once()->with(BankStatementConfig::statementPath($bankStatementImport->id))->andReturn(false);
        Storage::shouldReceive('disk')->once()->with(BankStatementConfig::statementsDisk())->andReturn($mock);

        try {
            new StatementFileCleaner()->delete($bankStatementImport);
            $this->fail('Expected cleanup failure was not thrown.');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('The uploaded statement file could not be deleted.', $runtimeException->getMessage());
            $this->assertNotInstanceOf(Throwable::class, $runtimeException->getPrevious());
        }

        $this->assertSame(BankStatementConfig::CLEANUP_FAILED, $bankStatementImport->fresh()?->file_cleanup_status);
    }

    public function test_a_filesystem_exception_is_wrapped_and_records_failure(): void
    {
        $bankStatementImport = $this->makeImport();
        $previous = new RuntimeException('storage unavailable', 17);
        /** @var Filesystem&MockInterface $mock */
        $mock = Mockery::mock(Filesystem::class);
        $mock->shouldReceive('exists')->once()->andThrow($previous);
        Storage::shouldReceive('disk')->once()->with(BankStatementConfig::statementsDisk())->andReturn($mock);

        try {
            new StatementFileCleaner()->delete($bankStatementImport);
            $this->fail('Expected cleanup failure was not thrown.');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('The uploaded statement file could not be deleted.', $runtimeException->getMessage());
            $this->assertSame(17, $runtimeException->getCode());
            $this->assertSame($previous, $runtimeException->getPrevious());
        }

        $this->assertSame(BankStatementConfig::CLEANUP_FAILED, $bankStatementImport->fresh()?->file_cleanup_status);
    }

    /** @param array<string, mixed> $attributes */
    private function makeImport(array $attributes = []): BankStatementImport
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create();

        return BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create($attributes);
    }
}
