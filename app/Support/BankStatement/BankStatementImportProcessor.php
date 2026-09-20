<?php

declare(strict_types=1);

namespace App\Support\BankStatement;

use App\Models\BankStatementImport;
use App\Support\BankStatementConfig;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

readonly class BankStatementImportProcessor
{
    private string $processingToken;

    public function __construct(
        private BankStatementImport $bankStatementImport,
        ?string $processingToken = null,
    ) {
        $this->processingToken = $processingToken ?? (string) Str::uuid();
    }

    /** @throws Throwable */
    public function process(): bool
    {
        if ($this->bankStatementImport->isParsed() || $this->bankStatementImport->isCommitted()) {
            return true;
        }

        if (!$this->claim()) {
            return in_array($this->bankStatementImport->status, [
                BankStatementConfig::STATUS_PARSED,
                BankStatementConfig::STATUS_COMMITTED,
            ], true);
        }

        $config = $this->profileConfig();
        if ($config === null) {
            return $this->fail('The bank profile used for this import is no longer available.');
        }

        $path = BankStatementConfig::statementPath((int) $this->bankStatementImport->id);
        $disk = Storage::disk(BankStatementConfig::statementsDisk());

        if (!$disk->exists($path)) {
            return $this->fail('The uploaded statement file could not be found.');
        }

        $localPath = $this->copyToLocalTempFile($disk, $path);

        try {
            $reader = new CsvFileReader($localPath, $config);
            $parser = new TransactionRowParser($config, $this->bankStatementImport->statement_type);
            $validation = $this->validateRows($reader, $parser);

            if ($validation['total'] === 0) {
                $validation['errors'][] = ['row' => 0, 'message' => 'The statement contains no data rows.'];
                $validation['rejected']++;
            }

            if ($validation['rejected'] > 0) {
                $this->bankStatementImport->update([
                    'status' => BankStatementConfig::STATUS_FAILED,
                    'processing_token' => null,
                    'processing_started_at' => null,
                    'total_rows' => $validation['total'],
                    'valid_rows' => $validation['valid'],
                    'rejected_rows' => $validation['rejected'],
                    'parse_errors' => array_slice($validation['errors'], 0, BankStatementConfig::MAX_PARSE_ERRORS),
                ]);

                return false;
            }

            $this->stageRows($reader, $parser, $validation['total']);

            return true;
        } finally {
            if (is_file($localPath)) {
                @unlink($localPath);
            }
        }
    }

    private function claim(): bool
    {
        $claimed = BankStatementImport::whereKey($this->bankStatementImport->id)
            ->where(function ($query): void {
                $query->where('status', BankStatementConfig::STATUS_UPLOADED)
                    ->orWhere(function ($query): void {
                        $query->where('status', BankStatementConfig::STATUS_PARSING)
                            ->where('processing_token', $this->processingToken);
                    });
            })
            ->update([
                'status' => BankStatementConfig::STATUS_PARSING,
                'processing_token' => $this->processingToken,
                'processing_started_at' => now(),
                'parse_errors' => null,
            ]);

        $this->bankStatementImport->refresh();

        return $claimed === 1;
    }

    /** @return array<string, mixed>|null */
    private function profileConfig(): ?array
    {
        if (is_array($this->bankStatementImport->profile_config)) {
            return $this->bankStatementImport->profile_config;
        }

        $profile = $this->bankStatementImport->bankProfile;
        if (!$profile) {
            return null;
        }

        $config = $profile->config;
        $this->bankStatementImport->update([
            'profile_config' => $config,
            // Legacy unprocessed imports did not snapshot either value reliably.
            'statement_type' => $profile->statement_type,
        ]);
        $this->bankStatementImport->statement_type = $profile->statement_type;

        return $config;
    }

    /** @return array{total: int, valid: int, rejected: int, errors: list<array{row: int, message: string}>} */
    private function validateRows(CsvFileReader $reader, TransactionRowParser $parser): array
    {
        $total = 0;
        $valid = 0;
        $rejected = 0;
        $errors = [];

        foreach ($reader->rows() as $csvRow) {
            $total++;

            try {
                $parser->parseRowStrict($csvRow['data']);
                $valid++;
            } catch (InvalidArgumentException $exception) {
                $rejected++;
                if (count($errors) < BankStatementConfig::MAX_PARSE_ERRORS) {
                    $errors[] = ['row' => $csvRow['number'], 'message' => $exception->getMessage()];
                }
            }
        }

        return compact('total', 'valid', 'rejected', 'errors');
    }

    /** @throws Throwable */
    private function stageRows(CsvFileReader $reader, TransactionRowParser $parser, int $total): void
    {
        DB::transaction(function () use ($reader, $parser, $total): void {
            $this->bankStatementImport->importedTransactions()->delete();

            $detector = new DuplicateDetector($this->bankStatementImport->user_id);
            $seenHashes = [];
            $chunk = [];

            foreach ($reader->rows() as $csvRow) {
                $chunk[] = $parser->parseRowStrict($csvRow['data']);

                if (count($chunk) >= BankStatementConfig::TRANSACTION_CHUNK_SIZE) {
                    $this->insertChunk($detector->detectDuplicates($chunk, $seenHashes, $this->bankStatementImport->id));
                    $chunk = [];
                }
            }

            if ($chunk !== []) {
                $this->insertChunk($detector->detectDuplicates($chunk, $seenHashes, $this->bankStatementImport->id));
            }

            $this->bankStatementImport->update([
                'status' => BankStatementConfig::STATUS_PARSED,
                'processing_token' => null,
                'processing_started_at' => null,
                'total_rows' => $total,
                'valid_rows' => $total,
                'rejected_rows' => 0,
                'parse_errors' => null,
            ]);
        });
    }

    /** @param list<array<string, mixed>> $transactions */
    private function insertChunk(array $transactions): void
    {
        $now = now();
        $data = array_map(fn (array $transaction): array => [
            'import_id' => $this->bankStatementImport->id,
            'date' => $transaction['date'],
            'description' => $transaction['description'],
            'amount' => $transaction['amount'],
            'hash' => $transaction['hash'],
            'original_hash' => $transaction['hash'],
            'is_duplicate' => $transaction['is_duplicate'],
            'duplicate_reason' => $transaction['duplicate_reason'],
            'duplicate_override' => false,
            'external_id' => $transaction['external_id'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $transactions);

        $this->bankStatementImport->importedTransactions()->insert($data);
    }

    private function fail(string $message): bool
    {
        logger()->error('Bank statement parsing failed', ['import_id' => $this->bankStatementImport->id, 'error' => $message]);

        $this->bankStatementImport->update([
            'status' => BankStatementConfig::STATUS_FAILED,
            'processing_token' => null,
            'processing_started_at' => null,
            'parse_errors' => [['row' => 0, 'message' => $message]],
        ]);

        return false;
    }

    private function copyToLocalTempFile(Filesystem $filesystem, string $path): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'statement_');
        if ($tempPath === false) {
            throw new RuntimeException('Unable to create a temporary file for statement parsing.');
        }

        $stream = $filesystem->readStream($path);
        if (!is_resource($stream)) {
            @unlink($tempPath);

            throw new RuntimeException('Unable to read statement file from disk: ' . $path);
        }

        try {
            if (file_put_contents($tempPath, $stream) === false) {
                throw new RuntimeException('Unable to copy the statement file for parsing.');
            }
        } finally {
            fclose($stream);
        }

        return $tempPath;
    }
}
