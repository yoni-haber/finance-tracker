<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Eloquent;
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
