<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BankStatementImport;
use App\Support\BankStatement\BankStatementImportProcessor;
use App\Support\BankStatementConfig;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class ParseBankStatementJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = BankStatementConfig::JOB_MAX_TRIES;

    public int $timeout = BankStatementConfig::JOB_TIMEOUT_SECONDS;

    public string $processingToken;

    public function __construct(public int $importId, ?string $processingToken = null)
    {
        $this->processingToken = $processingToken ?? (string) Str::uuid();
    }

    /**
     * Execute the job.
     *
     * The guard skips only terminal success states (parsed/committed). A retry of
     * this queued job keeps its processing token and may reclaim the parsing row;
     * another queued job cannot. Non-retriable validation and storage failures are
     * handled inside the processor, which marks the import failed and returns false.
     */
    public function handle(): void
    {
        $import = BankStatementImport::find($this->importId);

        if (!$import) {
            throw new ModelNotFoundException('Bank statement import not found');
        }

        if ($import->isParsed() || $import->isCommitted()) {
            logger()->info('Import already processed, skipping', [
                'import_id' => $this->importId,
                'status' => $import->status,
            ]);

            return;
        }

        $bankStatementImportProcessor = new BankStatementImportProcessor($import, $this->processingToken);
        $success = $bankStatementImportProcessor->process();

        if ($success) {
            logger()->info('Bank statement parsed successfully', ['import_id' => $this->importId]);
        } else {
            logger()->error('Bank statement parsing failed (non-retriable)', ['import_id' => $this->importId]);
        }
    }

    /**
     * Handle a job failure after all retries are exhausted.
     */
    public function failed(Throwable $throwable): void
    {
        $import = BankStatementImport::find($this->importId);

        $import?->update([
            'status' => BankStatementConfig::STATUS_FAILED,
            'processing_token' => null,
            'processing_started_at' => null,
            'parse_errors' => [['row' => 0, 'message' => 'Processing failed after all retry attempts.']],
        ]);

        logger()->error('Bank statement parsing job failed permanently', [
            'import_id' => $this->importId,
            'error' => $throwable->getMessage(),
        ]);
    }
}
