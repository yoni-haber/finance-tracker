<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BankStatementImport;
use App\Support\BankStatement\StatementFileCleaner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeleteStatementFileJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public int $importId) {}

    public function handle(StatementFileCleaner $statementFileCleaner): void
    {
        $import = BankStatementImport::find($this->importId);
        if (!$import || $import->file_cleanup_status === \App\Support\BankStatementConfig::CLEANUP_DELETED) {
            return;
        }

        $statementFileCleaner->delete($import);
    }
}
