<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BankStatementImport;
use App\Support\BankStatementConfig;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SweepStatementFileCleanupJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        BankStatementImport::query()
            ->where('status', BankStatementConfig::STATUS_COMMITTED)
            ->where('file_cleanup_status', '!=', BankStatementConfig::CLEANUP_DELETED)
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($imports): void {
                foreach ($imports as $import) {
                    DeleteStatementFileJob::dispatch((int) $import->id);
                }
            });
    }
}
