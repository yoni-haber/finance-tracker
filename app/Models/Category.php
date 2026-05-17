<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    const string TYPE_INCOME = 'income';

    const string TYPE_EXPENSE = 'expense';

    protected $fillable = [
        'name',
        'user_id',
        'type',
        'parent_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Parent category (null for top-level categories). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /** Direct subcategories (one level only). */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('name');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    public function scopeForUser(Builder $query, int $userId): void
    {
        $query->where('user_id', $userId);
    }

    public function scopeIncome(Builder $query): void
    {
        $query->where('type', self::TYPE_INCOME);
    }

    public function scopeExpense(Builder $query): void
    {
        $query->where('type', self::TYPE_EXPENSE);
    }

    /** Top-level categories (no parent). */
    public function scopeParents(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /** Subcategories (have a parent). */
    public function scopeSubcategories(Builder $query): void
    {
        $query->whereNotNull('parent_id');
    }

    public function isParent(): bool
    {
        return $this->parent_id === null;
    }

    public function isSubcategory(): bool
    {
        return $this->parent_id !== null;
    }

    /**
     * Returns true if this category (or any of its children) has transactions.
     * Used to guard against deletion.
     */
    public function hasTransactions(): bool
    {
        if ($this->transactions()->exists()) {
            return true;
        }

        return $this->children()->whereHas('transactions')->exists();
    }

    /**
     * Returns true if this category has any budgets.
     * Used to guard against deletion.
     */
    public function hasBudgets(): bool
    {
        return $this->budgets()->exists();
    }
}
