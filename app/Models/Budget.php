<?php

namespace App\Models;

use Database\Factories\BudgetFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property int $user_id
 * @property int $category_id
 * @property int $month
 * @property int $year
 * @property numeric $amount
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Category $category
 * @property-read Collection<int, Transaction> $transactions
 * @property-read int|null $transactions_count
 * @property-read User $user
 *
 * @method static BudgetFactory factory($count = null, $state = [])
 * @method static Builder<static>|Budget newModelQuery()
 * @method static Builder<static>|Budget newQuery()
 * @method static Builder<static>|Budget query()
 * @method static Builder<static>|Budget whereAmount($value)
 * @method static Builder<static>|Budget whereCategoryId($value)
 * @method static Builder<static>|Budget whereCreatedAt($value)
 * @method static Builder<static>|Budget whereId($value)
 * @method static Builder<static>|Budget whereMonth($value)
 * @method static Builder<static>|Budget whereUpdatedAt($value)
 * @method static Builder<static>|Budget whereUserId($value)
 * @method static Builder<static>|Budget whereYear($value)
 *
 * @mixin Eloquent
 */
#[Fillable([
    'user_id',
    'category_id',
    'month',
    'year',
    'amount',
])]
class Budget extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'month' => 'integer',
            'year' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'category_id', 'category_id')
            ->where('user_id', $this->user_id)
            ->whereMonth('date', $this->month)
            ->whereYear('date', $this->year);
    }
}
