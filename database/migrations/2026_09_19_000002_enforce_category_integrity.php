<?php

declare(strict_types=1);

use App\Support\CategoryIntegrityAuditor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        $auditor = app(CategoryIntegrityAuditor::class);
        $issues = $auditor->issues();

        if ($issues !== []) {
            throw new RuntimeException(
                'Category integrity migration aborted; no data was changed. ' .
                'Run `php artisan categories:audit` and resolve: ' . $auditor->summary($issues),
            );
        }

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropForeign(['parent_id']);
            $table->unsignedBigInteger('parent_lookup_id')
                ->storedAs('COALESCE(parent_id, 0)')
                ->after('parent_id');
            $table->unique(
                ['user_id', 'type', 'parent_lookup_id', 'name'],
                'categories_scope_name_unique',
            );
            $table->unique(['id', 'user_id', 'type'], 'categories_id_user_type_unique');
            $table->unique(['id', 'user_id'], 'categories_id_user_unique');
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->foreign(['parent_id', 'user_id', 'type'], 'categories_parent_scope_foreign')
                ->references(['id', 'user_id', 'type'])
                ->on('categories')
                ->restrictOnDelete();
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropForeign(['category_id']);
            $table->foreign(['category_id', 'user_id', 'type'], 'transactions_category_scope_foreign')
                ->references(['id', 'user_id', 'type'])
                ->on('categories')
                ->restrictOnDelete();
        });

        Schema::table('budgets', function (Blueprint $table): void {
            $table->dropForeign(['category_id']);
            $table->foreign(['category_id', 'user_id'], 'budgets_category_scope_foreign')
                ->references(['id', 'user_id'])
                ->on('categories')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table): void {
            $table->dropForeign('budgets_category_scope_foreign');
            $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropForeign('transactions_category_scope_foreign');
            $table->foreign('category_id')->references('id')->on('categories')->nullOnDelete();
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropForeign('categories_parent_scope_foreign');
            $table->foreign('parent_id')->references('id')->on('categories')->nullOnDelete();
            $table->dropUnique('categories_id_user_unique');
            $table->dropUnique('categories_id_user_type_unique');
            $table->dropUnique('categories_scope_name_unique');
            $table->dropColumn('parent_lookup_id');
        });
    }
};
