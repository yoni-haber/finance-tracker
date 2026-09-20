<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('budgets', function (Blueprint $table): void {
            $table->index(['user_id', 'year', 'month'], 'budgets_user_period_idx');
        });

        Schema::table('net_worth_line_items', function (Blueprint $table): void {
            $table->index(['net_worth_entry_id', 'type'], 'net_worth_items_entry_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table): void {
            $table->dropIndex('budgets_user_period_idx');
        });

        Schema::table('net_worth_line_items', function (Blueprint $table): void {
            $table->dropIndex('net_worth_items_entry_type_idx');
        });
    }
};
