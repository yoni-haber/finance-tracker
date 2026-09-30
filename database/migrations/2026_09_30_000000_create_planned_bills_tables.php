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
            $table->unique(['id', 'user_id'], 'transactions_id_user_unique');
        });

        Schema::create('planned_bills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->text('note')->nullable();
            $table->decimal('estimated_amount', 12, 2);
            $table->date('next_due_date');
            $table->unsignedTinyInteger('anchor_day');
            $table->enum('frequency', ['quarterly', 'yearly']);
            $table->timestamps();

            $table->index(['user_id', 'next_due_date']);
            $table->unique(['id', 'user_id']);
        });

        Schema::create('planned_bill_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('planned_bill_id');
            $table->foreignId('user_id');
            $table->date('expected_date');
            $table->foreignId('transaction_id');
            $table->decimal('previous_estimated_amount', 12, 2)->nullable();
            $table->timestamps();

            $table->unique(['planned_bill_id', 'expected_date']);
            $table->unique('transaction_id');
            $table->foreign(['planned_bill_id', 'user_id'])
                ->references(['id', 'user_id'])->on('planned_bills')->cascadeOnDelete();
            $table->foreign(['transaction_id', 'user_id'])
                ->references(['id', 'user_id'])->on('transactions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planned_bill_payments');
        Schema::dropIfExists('planned_bills');
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropUnique('transactions_id_user_unique');
        });
    }
};
