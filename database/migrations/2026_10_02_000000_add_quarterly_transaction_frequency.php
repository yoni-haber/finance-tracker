<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $blueprint): void {
            $blueprint->enum('frequency', ['weekly', 'monthly', 'quarterly', 'yearly'])->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('transactions')->where('frequency', 'quarterly')->exists()) {
            throw new RuntimeException('Convert or remove quarterly schedules before rolling back quarterly support.');
        }

        Schema::table('transactions', function (Blueprint $blueprint): void {
            $blueprint->enum('frequency', ['weekly', 'monthly', 'yearly'])->nullable()->change();
        });
    }
};
