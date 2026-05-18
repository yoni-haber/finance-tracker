<?php

use App\Models\Transaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', [Transaction::TYPE_INCOME, Transaction::TYPE_EXPENSE]);
            $table->decimal('amount', 12, 2);
            $table->date('date');
            $table->boolean('is_recurring')->default(false);
            $table->enum('frequency', ['weekly', 'monthly', 'yearly'])->nullable();
            $table->date('recurring_until')->nullable();
            $table->text('description')->nullable();
            $table->string('hash')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
