<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BankProfile;
use App\Models\BankStatementImport;
use App\Models\User;
use App\Support\BankStatement\BankStatementImportProcessor;
use App\Support\BankStatementConfig;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

final class StatementImportProcessingStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_claim_and_legacy_profile_snapshot_are_persisted_before_reading_the_file(): void
    {
        $user = User::factory()->create();
        $config = [
            'columns' => ['date' => 0, 'description' => 1, 'amount' => 2],
            'date_format' => 'd/m/Y',
            'has_header' => true,
        ];
        $profile = BankProfile::factory()->for($user)->creditCard()->create(['config' => $config]);
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create([
            'status' => BankStatementConfig::STATUS_UPLOADED,
            'statement_type' => BankStatementConfig::STATEMENT_TYPE_BANK,
            'profile_config' => null,
        ]);
        $token = '00000000-0000-4000-8000-000000000026';
        $path = BankStatementConfig::statementPath($import->id);

        /** @var Filesystem&Mockery\MockInterface $mock */
        $mock = Mockery::mock(Filesystem::class);
        $mock->shouldReceive('exists')->once()->with($path)->andReturn(true);
        $mock->shouldReceive('readStream')->once()->with($path)->andReturnUsing(function () use ($import, $token, $config) {
            $claimed = $import->fresh();
            $this->assertNotNull($claimed);
            $this->assertSame(BankStatementConfig::STATUS_PARSING, $claimed->status);
            $this->assertSame($token, $claimed->processing_token);
            $this->assertNotNull($claimed->processing_started_at);
            $this->assertEquals($config, $claimed->profile_config);
            $this->assertSame(BankStatementConfig::STATEMENT_TYPE_CREDIT_CARD, $claimed->statement_type);

            $stream = fopen('php://temp', 'w+');
            $this->assertIsResource($stream);
            fwrite($stream, "Date,Description,Amount\n01/01/2026,Purchase,12.34");
            rewind($stream);

            return $stream;
        });
        Storage::shouldReceive('disk')->once()->with(BankStatementConfig::statementsDisk())->andReturn($mock);

        $this->assertTrue(new BankStatementImportProcessor($import, $token)->process());
        $this->assertSame(BankStatementConfig::STATUS_PARSED, $import->fresh()?->status);
        $this->assertSame('-12.34', $import->importedTransactions()->sole()->amount);
    }
}
