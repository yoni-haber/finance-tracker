<?php

declare(strict_types=1);

namespace App\Support\BankStatement;

use App\Models\BankStatementImport;
use App\Support\BankStatementConfig;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class StatementFileCleaner
{
    public function delete(BankStatementImport $bankStatementImport): void
    {
        $disk = Storage::disk(BankStatementConfig::statementsDisk());
        $path = BankStatementConfig::statementPath((int) $bankStatementImport->id);

        try {
            $exists = $disk->exists($path);
            $deleted = !$exists || $disk->delete($path);
        } catch (Throwable $throwable) {
            $bankStatementImport->update(['file_cleanup_status' => BankStatementConfig::CLEANUP_FAILED]);

            throw new RuntimeException('The uploaded statement file could not be deleted.', $throwable->getCode(), previous: $throwable);
        }

        if (!$deleted) {
            $bankStatementImport->update(['file_cleanup_status' => BankStatementConfig::CLEANUP_FAILED]);

            throw new RuntimeException('The uploaded statement file could not be deleted.');
        }

        $bankStatementImport->update([
            'file_cleanup_status' => BankStatementConfig::CLEANUP_DELETED,
            'file_deleted_at' => now(),
        ]);
    }
}
