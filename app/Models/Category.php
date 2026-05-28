<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CategoryFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $parent_id
 * @property int|null $parent_lookup_id
 * @property string $type
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Budget> $budgets
 * @property-read int|null $budgets_count
 * @property-read Collection<int, Category> $children
 * @property-read int|null $children_count
 * @property-read Category|null $parent
 * @property-read Collection<int, Transaction> $transactions
 * @property-read int|null $transactions_count
 * @property-read User $user
 *
 * @method static Builder<static>|Category expense()
 * @method static CategoryFactory factory($count = null, $state = [])
 * @method static Builder<static>|Category forUser(int $userId)
 * @method static Builder<static>|Category income()
 * @method static Builder<static>|Category newModelQuery()
 * @method static Builder<static>|Category newQuery()
 * @method static Builder<static>|Category parents()
 * @method static Builder<static>|Category query()
 * @method static Builder<static>|Category subcategories()
 * @method static Builder<static>|Category whereCreatedAt($value)
 * @method static Builder<static>|Category whereId($value)
 * @method static Builder<static>|Category whereName($value)
 * @method static Builder<static>|Category whereParentId($value)
 * @method static Builder<static>|Category whereParentLookupId($value)
 * @method static Builder<static>|Category whereType($value)
 * @method static Builder<static>|Category whereUpdatedAt($value)
 * @method static Builder<static>|Category whereUserId($value)
 *
 * @mixin Eloquent
 */
#[Fillable([
    'name',
    'user_id',
    'type',
    'parent_id',
])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    const string TYPE_INCOME = 'income';

    const string TYPE_EXPENSE = 'expense';

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Parent category (null for top-level categories). */
    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /** Direct subcategories (one level only). */
    /** @return HasMany<Category, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('name');
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return HasMany<Budget, $this> */
    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    /** @param Builder<self> $builder */
    public function scopeForUser(Builder $builder, int $userId): void
    {
        $builder->where('user_id', $userId);
    }

    /** @param Builder<self> $builder */
    public function scopeIncome(Builder $builder): void
    {
        $builder->where('type', self::TYPE_INCOME);
    }

    /** @param Builder<self> $builder */
    public function scopeExpense(Builder $builder): void
    {
        $builder->where('type', self::TYPE_EXPENSE);
    }

    /** Top-level categories (no parent). */
    /** @param Builder<self> $builder */
    public function scopeParents(Builder $builder): void
    {
        $builder->whereNull('parent_id');
    }

    /** Subcategories (have a parent). */
    /** @param Builder<self> $builder */
    public function scopeSubcategories(Builder $builder): void
    {
        $builder->whereNotNull('parent_id');
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
