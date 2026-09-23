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
            $table->string('error_message')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_imports', function (Blueprint $table): void {
            $table->dropColumn('error_message');
        });
    }
};
