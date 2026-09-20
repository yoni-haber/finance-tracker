<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class CategoryIntegrityAuditor
{
    /**
     * @return array<string, list<int>>
     */
    public function issues(): array
    {
        /** @var array<int, object{id: int, user_id: int, parent_id: int|null, type: string, name: string}> $categories */
        $categories = DB::table('categories')
            ->select(['id', 'user_id', 'parent_id', 'type', 'name'])
            ->orderBy('id')
            ->get()
            ->all();

        $categoriesById = [];
        $categoriesByScope = [];
        $invalidParents = [];

        foreach ($categories as $category) {
            $categoriesById[$category->id] = $category;
            $scope = implode('|', [
                $category->user_id,
                $category->type,
                $category->parent_id ?? 0,
                mb_strtolower(rtrim($category->name)),
            ]);
            $categoriesByScope[$scope][] = $category->id;
        }

        foreach ($categories as $category) {
            if ($category->parent_id === null) {
                continue;
            }

            $parent = $categoriesById[$category->parent_id] ?? null;

            if (
                $parent === null
                || $parent->id === $category->id
                || $parent->user_id !== $category->user_id
                || $parent->type !== $category->type
                || $parent->parent_id !== null
            ) {
                $invalidParents[] = $category->id;
            }
        }

        $duplicateCategories = [];
        foreach ($categoriesByScope as $ids) {
            if (count($ids) > 1) {
                array_push($duplicateCategories, ...$ids);
            }
        }

        $invalidTransactions = DB::table('transactions')
            ->leftJoin('categories', 'categories.id', '=', 'transactions.category_id')
            ->whereNotNull('transactions.category_id')
            ->where(function ($query): void {
                $query->whereNull('categories.id')
                    ->orWhereColumn('transactions.user_id', '!=', 'categories.user_id')
                    ->orWhereColumn('transactions.type', '!=', 'categories.type');
            })
            ->orderBy('transactions.id')
            ->pluck('transactions.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $invalidBudgets = DB::table('budgets')
            ->leftJoin('categories', 'categories.id', '=', 'budgets.category_id')
            ->where(function ($query): void {
                $query->whereNull('categories.id')
                    ->orWhereColumn('budgets.user_id', '!=', 'categories.user_id')
                    ->orWhere('categories.type', '!=', 'expense')
                    ->orWhereNotNull('categories.parent_id')
                    ->orWhereNull('categories.expense_treatment')
                    ->orWhere('categories.expense_treatment', '!=', 'spending');
            })
            ->orderBy('budgets.id')
            ->pluck('budgets.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return array_filter([
            'duplicate category scopes' => array_values(array_unique($duplicateCategories)),
            'invalid category parents' => array_values(array_unique($invalidParents)),
            'invalid transaction categories' => array_values($invalidTransactions),
            'invalid budget categories' => array_values($invalidBudgets),
        ]);
    }

    /** @param array<string, list<int>> $issues */
    public function summary(array $issues): string
    {
        return collect($issues)
            ->map(static fn (array $ids, string $issue): string => sprintf('%s [%s]', $issue, implode(', ', $ids)))
            ->implode('; ');
    }
}
