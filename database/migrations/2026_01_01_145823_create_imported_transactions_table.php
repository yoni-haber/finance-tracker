<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('imported_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('bank_statement_imports')->onDelete('cascade');
            $table->date('date');
            $table->string('description');
            $table->decimal('amount', 10, 2);
            $table->string('external_id')->nullable();
            // Proper nullable category assignment; no FK constraint since categories may be
            // deleted before an import is committed, and this is a staging table.
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('hash');
            // Stores the hash computed from the raw CSV data at parse time.
            // Unlike `hash`, this is never modified when the user edits a transaction on
            // the review page, so re-uploading the same CSV can always detect the duplicate.
            $table->string('original_hash')->nullable();
            $table->boolean('is_duplicate')->default(false);
            $table->boolean('is_committed')->default(false);
            $table->timestamps();

            $table->index('import_id');
            $table->index('hash');
            $table->index('original_hash');
            $table->index(['import_id', 'is_duplicate', 'is_committed'], 'imported_txn_commit_filter_idx');
            $table->index(['hash', 'is_duplicate'], 'imported_txn_hash_duplicate_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imported_transactions');
    }
};
