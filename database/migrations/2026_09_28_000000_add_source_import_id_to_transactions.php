<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('transactions', 'source_import_id')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table): void {
            $table->foreignId('source_import_id')
                ->nullable()
                ->after('type')
                ->constrained('bank_statement_imports')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('transactions', 'source_import_id')) {
            return;
        }

        Schema::table('transactions', fn (Blueprint $table) => $table->dropConstrainedForeignId('source_import_id'));
    }
};
