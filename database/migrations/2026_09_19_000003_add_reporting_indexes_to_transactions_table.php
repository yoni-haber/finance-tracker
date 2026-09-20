<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->index(['user_id', 'date'], 'transactions_user_date_index');
            $table->index(
                ['user_id', 'is_recurring', 'date', 'recurring_until'],
                'transactions_user_recurrence_range_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex('transactions_user_date_index');
            $table->dropIndex('transactions_user_recurrence_range_index');
        });
    }
};
