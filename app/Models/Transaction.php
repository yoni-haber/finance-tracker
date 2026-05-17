<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Transaction extends Model
{
    use HasFactory;

    const TYPE_INCOME = 'income';

    const TYPE_EXPENSE = 'expense';

    protected $fillable = [
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
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'date' => 'date',
            'is_recurring' => 'boolean',
            'recurring_until' => 'date',
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

    public function occurrenceExceptions(): HasMany
    {
        return $this->hasMany(TransactionException::class);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeIncome($query)
    {
        return $query->where('type', self::TYPE_INCOME);
    }

    public function scopeExpense($query)
    {
        return $query->where('type', self::TYPE_EXPENSE);
    }

    public function scopeForMonthYear($query, int $month, int $year)
    {
        return $query->whereMonth('date', $month)->whereYear('date', $year);
    }

    public function scopeForCategory($query, int|array|null $categoryId)
    {
        if (is_array($categoryId)) {
            return $query->whereIn('category_id', $categoryId);
        }

        return $categoryId ? $query->where('category_id', $categoryId) : $query;
    }

    /** Returns all the dates that the current transaction should appear in a given month. */
    public function projectOccurrencesForMonth(int $month, int $year): Collection
    {
        // Target month window
        $monthStart = Carbon::create($year, $month);
        $monthEnd = $monthStart->copy()->endOfMonth();

        /**
         * NON-RECURRING TRANSACTION
         * Return the transaction only if its date falls within the month
         */
        if (! $this->is_recurring) {
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
            ! $this->frequency ||
            ($recurringEnd && $monthStart->greaterThan($recurringEnd)) ||
            ($recurringEnd && $this->date->greaterThan($recurringEnd))
        ) {
            return collect();
        }

        /**
         * Limit recurrence generation to the earliest of:
         * - end of the month
         * - recurring_until (if defined)
         */
        $generationEnd = $recurringEnd && $recurringEnd->lessThan($monthEnd)
            ? $recurringEnd
            : $monthEnd;

        /**
         * Dates that should be skipped (exceptions)
         * Normalised to Y-m-d for fast comparison
         */
        $skippedDates = $this->occurrenceExceptions
            ->pluck('date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->flip(); // enables O(1) lookups

        // Frequency → interval mapping
        $intervals = [
            'weekly' => fn (Carbon $date) => $date->addWeek(),
            'monthly' => fn (Carbon $date) => $date->addMonth(),
            'yearly' => fn (Carbon $date) => $date->addYear(),
        ];

        // If the frequency is invalid, return empty
        if (! isset($intervals[$this->frequency])) {
            return collect();
        }

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
                ! $skippedDates->has($dateKey)
            ) {
                $occurrences->push(
                    $this->replicateForDate($transactionDate)
                );
            }

            // Advance to the next recurrence
            $intervals[$this->frequency]($transactionDate);
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
    protected function replicateForDate(Carbon $date, bool $isProjected = true): self
    {
        $clone = $this->replicate();
        $clone->id = $this->id;
        $clone->date = $date;
        $clone->setAttribute('projected', $isProjected);
        $clone->setRelation('category', $this->category);

        return $clone;
    }
}
