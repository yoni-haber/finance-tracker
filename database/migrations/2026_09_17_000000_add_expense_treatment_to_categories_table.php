<?php

declare(strict_types=1);

use App\Models\Category;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->string('expense_treatment')->nullable()->after('type');
        });

        DB::table('categories')
            ->where('type', Category::TYPE_EXPENSE)
            ->whereNull('parent_id')
            ->update(['expense_treatment' => Category::TREATMENT_SPENDING]);
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn('expense_treatment');
        });
    }
};
