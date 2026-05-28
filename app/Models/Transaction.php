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
        // Target month window
        $monthStart = Carbon::create($year, $month);
        assert($monthStart instanceof Carbon);
        $monthEnd = $monthStart->copy()->endOfMonth();

        /**
         * NON-RECURRING TRANSACTION
         * Return the transaction only if its date falls within the month
         */
        if (!$this->is_recurring) {
            return $this->date->between($monthStart, $monthEnd)
                ? collect([$this->replicateForDate($this->date, false)])
                : collect();
        }

        // Optional recurring end date
        $recurringEnd = $this->recurring_until
            ? Carbon::parse($this->recurring_until)
            : null;

        /**
         * RECURRING TRANSACTION
         * Early return if:
         * - the transaction has no frequency
         * - the last recurrence date is before the month started
         * - the transaction date is after the last recurrence date
         */
        if (
            !$this->frequency ||
            ($recurringEnd instanceof Carbon && $monthStart->greaterThan($recurringEnd)) ||
            ($recurringEnd instanceof Carbon && $this->date->greaterThan($recurringEnd))
        ) {
            return collect();
        }

        /**
         * Limit recurrence generation to the earliest of:
         * - end of the month
         * - recurring_until (if defined)
         */
        $generationEnd = $recurringEnd instanceof Carbon && $recurringEnd->lessThan($monthEnd)
            ? $recurringEnd
            : $monthEnd;

        /**
         * Dates that should be skipped (exceptions)
         * Normalised to Y-m-d for fast comparison
         */
        $skippedDates = $this->occurrenceExceptions
            ->pluck('date')
            ->map(fn (DateTimeInterface|WeekDay|Month|string|int|float|null $date): string => Carbon::parse($date)->toDateString())
            ->flip(); // enables O(1) lookups

        // Frequency → interval mapping
        $intervals = [
            'weekly' => fn (Carbon $date) => $date->addWeek(),
            'monthly' => fn (Carbon $date) => $date->addMonth(),
            'yearly' => fn (Carbon $date) => $date->addYear(),
        ];

        // If the frequency is invalid, return empty
        if (!isset($intervals[$this->frequency])) {
            return collect();
        }

        $intervalFn = $intervals[$this->frequency];
        $occurrences = collect();
        $transactionDate = $this->date->copy();

        /**
         * Generate recurrence dates up to the allowed limit
         */
        while ($transactionDate->lessThanOrEqualTo($generationEnd)) {
            $dateKey = $transactionDate->toDateString();

            // Include only occurrences inside the target month and not skipped
            if (
                $transactionDate->between($monthStart, $monthEnd) &&
                !$skippedDates->has($dateKey)
            ) {
                $occurrences->push(
                    $this->replicateForDate($transactionDate),
                );
            }

            // Advance to the next recurrence
            $intervalFn($transactionDate);
        }

        return $occurrences;
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
