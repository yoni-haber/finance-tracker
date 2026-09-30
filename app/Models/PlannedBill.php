<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $category_id
 * @property string $name
 * @property string|null $note
 * @property string $estimated_amount
 * @property Carbon $next_due_date
 * @property int $anchor_day
 * @property string $frequency
 * @property-read Category|null $category
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PlannedBillPayment> $payments
 *
 * @method static Builder<static>|PlannedBill forUser(int $userId)
 */
#[Fillable(['user_id', 'category_id', 'name', 'note', 'estimated_amount', 'next_due_date', 'anchor_day', 'frequency'])]
class PlannedBill extends Model
{
    protected function casts(): array
    {
        return [
            'estimated_amount' => 'decimal:2',
            'next_due_date' => 'date',
            'anchor_day' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<PlannedBillPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(PlannedBillPayment::class);
    }

    /** @param Builder<self> $builder
     *  @return Builder<self>
     */
    public function scopeForUser(Builder $builder, int $userId): Builder
    {
        return $builder->where('user_id', $userId);
    }

    /** The next date is always calculated from the intended day, not a clamped date. */
    public function dateAfter(DateTimeInterface $date): CarbonImmutable
    {
        $months = $this->frequency === 'quarterly' ? 3 : 12;
        $target = CarbonImmutable::instance($date)->startOfMonth()->addMonthsNoOverflow($months);

        return $target->day(min($this->anchor_day, $target->daysInMonth));
    }

    /** @return Collection<int, CarbonImmutable> */
    public function unpaidDatesThrough(DateTimeInterface $end): Collection
    {
        $dates = collect();
        $date = $this->next_due_date->toImmutable()->startOfDay();
        $last = CarbonImmutable::instance($end)->endOfDay();

        while ($date->lessThanOrEqualTo($last)) {
            $dates->push($date);
            $date = $this->dateAfter($date);
        }

        return $dates;
    }
}
