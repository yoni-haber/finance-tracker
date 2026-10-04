<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('planned_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('note')->nullable();
            $table->decimal('amount', 12, 2);
            $table->date('first_due_on');
            $table->string('frequency', 12);
            $table->unsignedInteger('completed_occurrences')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'frequency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planned_payments');
    }
};
