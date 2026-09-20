<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_imports', function (Blueprint $table): void {
            $table->json('profile_config')->nullable()->after('statement_type');
            $table->uuid('processing_token')->nullable()->after('profile_config');
            $table->timestamp('processing_started_at')->nullable()->after('processing_token');
            $table->unsignedInteger('total_rows')->default(0)->after('processing_started_at');
            $table->unsignedInteger('valid_rows')->default(0)->after('total_rows');
            $table->unsignedInteger('rejected_rows')->default(0)->after('valid_rows');
            $table->json('parse_errors')->nullable()->after('rejected_rows');
            $table->string('file_cleanup_status')->default('pending')->after('parse_errors');
            $table->timestamp('file_deleted_at')->nullable()->after('file_cleanup_status');

            $table->index(['processing_token', 'status'], 'bank_imports_processing_claim_idx');
            $table->index(['file_cleanup_status', 'status'], 'bank_imports_cleanup_idx');
        });

        Schema::table('imported_transactions', function (Blueprint $table): void {
            $table->string('duplicate_reason')->nullable()->after('is_duplicate');
            $table->boolean('duplicate_override')->default(false)->after('duplicate_reason');
        });
    }

    public function down(): void
    {
        Schema::table('imported_transactions', function (Blueprint $table): void {
            $table->dropColumn(['duplicate_reason', 'duplicate_override']);
        });

        Schema::table('bank_statement_imports', function (Blueprint $table): void {
            $table->dropIndex('bank_imports_processing_claim_idx');
            $table->dropIndex('bank_imports_cleanup_idx');
            $table->dropColumn([
                'profile_config',
                'processing_token',
                'processing_started_at',
                'total_rows',
                'valid_rows',
                'rejected_rows',
                'parse_errors',
                'file_cleanup_status',
                'file_deleted_at',
            ]);
        });
    }
};
