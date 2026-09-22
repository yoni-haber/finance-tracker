<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Carbon\Month;
use Carbon\WeekDay;
use Database\Factories\TransactionFactory;
use DateTimeInterface;
use Eloquent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon as SupportCarbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Override;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $category_id
 * @property string $type
 * @property numeric $amount
 * @property SupportCarbon $date
 * @property bool $is_recurring
 * @property string|null $frequency
 * @property SupportCarbon|null $recurring_until
 * @property string|null $description
 * @property string|null $hash
 * @property SupportCarbon|null $created_at
 * @property SupportCarbon|null $updated_at
 * @property-read Category|null $category
 * @property-read \Illuminate\Database\Eloquent\Collection<int, TransactionException> $occurrenceExceptions
 * @property-read int|null $occurrence_exceptions_count
 * @property-read User $user
 *
 * @method static Builder<static>|Transaction expense()
 * @method static TransactionFactory factory($count = null, $state = [])
 * @method static Builder<static>|Transaction forCategory(array<int, int>|int|null $categoryId)
 * @method static Builder<static>|Transaction forMonthYear(int $month, int $year)
 * @method static Builder<static>|Transaction forUser(int $userId)
 * @method static Builder<static>|Transaction income()
 * @method static Builder<static>|Transaction newModelQuery()
 * @method static Builder<static>|Transaction newQuery()
 * @method static Builder<static>|Transaction query()
 * @method static Builder<static>|Transaction whereAmount($value)
 * @method static Builder<static>|Transaction whereCategoryId($value)
 * @method static Builder<static>|Transaction whereCreatedAt($value)
 * @method static Builder<static>|Transaction whereDate($value)
 * @method static Builder<static>|Transaction whereDescription($value)
 * @method static Builder<static>|Transaction whereFrequency($value)
 * @method static Builder<static>|Transaction whereHash($value)
 * @method static Builder<static>|Transaction whereId($value)
 * @method static Builder<static>|Transaction whereIsRecurring($value)
 * @method static Builder<static>|Transaction whereRecurringUntil($value)
 * @method static Builder<static>|Transaction whereType($value)
 * @method static Builder<static>|Transaction whereUpdatedAt($value)
 * @method static Builder<static>|Transaction whereUserId($value)
 *
 * @mixin Eloquent
 */
#[Fillable([
    'user_id',
    'category_id',
    'type',
    'amount',
    'date',
    'is_recurring',
    'frequency',
    'recurring_until',
    'description',
    'hash',
])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    const string TYPE_INCOME = 'income';

    const string TYPE_EXPENSE = 'expense';

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'date' => 'date',
            'is_recurring' => 'boolean',
            'recurring_until' => 'date',
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

    /** @return HasMany<TransactionException, $this> */
    public function occurrenceExceptions(): HasMany
    {
        return $this->hasMany(TransactionException::class);
    }

    /**
     * @param Builder<self> $builder
     * @return Builder<self>
     */
    public function scopeForUser(Builder $builder, int $userId): Builder
    {
        return $builder->where('user_id', $userId);
    }

    /**
     * @param Builder<self> $builder
     * @return Builder<self>
     */
    public function scopeIncome(Builder $builder): Builder
    {
        return $builder->where('type', self::TYPE_INCOME);
    }

    /**
     * @param Builder<self> $builder
     * @return Builder<self>
     */
    public function scopeExpense(Builder $builder): Builder
    {
        return $builder->where('type', self::TYPE_EXPENSE);
    }

    /**
     * @param Builder<self> $builder
     * @return Builder<self>
     */
    public function scopeForMonthYear(Builder $builder, int $month, int $year): Builder
    {
        return $builder->whereMonth('date', $month)->whereYear('date', $year);
    }

    /**
     * @param Builder<self> $builder
     * @param array<int, int>|int|null $categoryId
     * @return Builder<self>
     */
    public function scopeForCategory(Builder $builder, int|array|null $categoryId): Builder
    {
        if (is_array($categoryId)) {
            return $builder->whereIn('category_id', $categoryId);
        }

        return $categoryId ? $builder->where('category_id', $categoryId) : $builder;
    }

    /** Returns all the dates that the current transaction should appear in a given month. */
    /** @return Collection<int, self> */
    public function projectOccurrencesForMonth(int $month, int $year): Collection
    {
        $monthStart = Carbon::createMidnightDate($year, $month, 1);

        return $this->projectOccurrencesForRange($monthStart, $monthStart->copy()->endOfMonth());
    }

    /**
     * Return all effective occurrences inside an inclusive date range.
     *
     * Monthly and yearly recurrences are always derived from the original date,
     * so clamping an occurrence to a short month does not change the series anchor.
     *
     * @return Collection<int, self>
     */
    public function projectOccurrencesForRange(DateTimeInterface $rangeStart, DateTimeInterface $rangeEnd): Collection
    {
        $start = Carbon::instance($rangeStart)->copy()->startOfDay();
        $end = Carbon::instance($rangeEnd)->copy()->endOfDay();

        if ($start->greaterThan($end)) {
            throw new InvalidArgumentException('The recurrence range start must not be after its end.');
        }

        if (!$this->is_recurring) {
            return $this->date->betweenIncluded($start, $end)
                ? collect([$this->replicateForDate($this->date, false)])
                : collect();
        }

        $recurringEnd = $this->recurring_until
            ? Carbon::parse($this->recurring_until)->endOfDay()
            : null;

        if (!in_array($this->frequency, ['weekly', 'monthly', 'yearly'], true)) {
            return collect();
        }

        if ($this->date->greaterThan($end)) {
            return collect();
        }

        if ($recurringEnd instanceof Carbon && $start->greaterThan($recurringEnd)) {
            return collect();
        }

        if ($recurringEnd instanceof Carbon && $this->date->greaterThan($recurringEnd)) {
            return collect();
        }

        $generationEnd = $recurringEnd instanceof Carbon && $recurringEnd->lessThan($end)
            ? $recurringEnd
            : $end;

        $skippedDates = $this->occurrenceExceptions
            ->pluck('date')
            ->map(fn (DateTimeInterface|WeekDay|Month|string|int|float|null $date): string => Carbon::parse($date)->toDateString())
            ->flip();

        $occurrences = collect();
        $step = $this->firstStepOnOrAfter($start);
        $occurrenceDate = $this->occurrenceDateForStep($step);

        while ($occurrenceDate->lessThanOrEqualTo($generationEnd)) {
            $dateKey = $occurrenceDate->toDateString();

            if ($occurrenceDate->greaterThanOrEqualTo($start) && !$skippedDates->has($dateKey)) {
                $occurrences->push($this->replicateForDate($occurrenceDate));
            }

            $step++;
            $occurrenceDate = $this->occurrenceDateForStep($step);
        }

        return $occurrences;
    }

    /**
     * Locate a starting step without iterating over the transaction's full history.
     *
     * @infection-ignore-all The public range-projection tests verify the resulting
     * dates; most mutations here only add discarded pre-range iterations and are
     * therefore deliberately unobservable implementation-detail changes.
     */
    private function firstStepOnOrAfter(Carbon $rangeStart): int
    {
        $anchor = $this->date->copy()->startOfDay();

        if ($anchor->greaterThanOrEqualTo($rangeStart)) {
            return 0;
        }

        $step = match ($this->frequency) {
            'weekly' => intdiv((int) $anchor->diffInDays($rangeStart), 7),
            'monthly' => ($rangeStart->year - $anchor->year) * 12 + $rangeStart->month - $anchor->month,
            'yearly' => $rangeStart->year - $anchor->year,
            default => 0,
        };

        return $this->occurrenceDateForStep($step)->lessThan($rangeStart) ? $step + 1 : $step;
    }

    private function occurrenceDateForStep(int $step): SupportCarbon
    {
        $anchor = $this->date->copy()->startOfDay();

        if ($this->frequency === 'weekly') {
            return $anchor->addWeeks($step);
        }

        if ($this->frequency === 'monthly') {
            $month = $anchor->copy()->startOfMonth()->addMonthsNoOverflow($step);

            return $month->day(min($anchor->day, $month->daysInMonth));
        }

        $year = $anchor->year + $step;
        $month = $anchor->copy()->startOfMonth()->year($year);

        return $month->day(min($anchor->day, $month->daysInMonth));
    }

    /**
     * Create an in-memory clone of the transaction for a specific occurrence date.
     *
     * This method is used to represent a single effective occurrence of a transaction
     * (either projected from a recurring transaction or normalised from a non-recurring
     * one) without persisting a new database record.
     *
     * The returned model:
     * - Shares the same primary key as the original transaction (identity is preserved)
     * - Has its `date` set to the occurrence date being represented
     * - Is marked with a `projected` attribute to indicate whether the occurrence is
     *   derived from recurrence rules or represents the original transaction
     * - Carries over the already-loaded `category` relation to avoid additional queries
     *
     * @param  bool  $isProjected  Whether this occurrence is derived from recurrence rules
     */
    protected function replicateForDate(SupportCarbon $supportCarbon, bool $isProjected = true): self
    {
        $clone = $this->replicate();
        $clone->id = $this->id;
        $clone->date = $supportCarbon;
        $clone->setAttribute('projected', $isProjected);
        $clone->setRelation('category', $this->category);

        return $clone;
    }
}
