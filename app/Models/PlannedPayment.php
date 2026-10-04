<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $note
 * @property string $amount
 * @property \Illuminate\Support\Carbon $first_due_on
 * @property string $frequency
 * @property int $completed_occurrences
 * @property-read User $user
 */
#[Fillable(['user_id', 'name', 'note', 'amount', 'first_due_on', 'frequency', 'completed_occurrences'])]
class PlannedPayment extends Model
{
    protected function casts(): array
    {
        return [
            'first_due_on' => 'date',
            'amount' => 'decimal:2',
            'completed_occurrences' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function nextDueDate(): ?CarbonImmutable
    {
        if ($this->frequency === 'once' && $this->completed_occurrences > 0) {
            return null;
        }

        return $this->dueDateForOccurrence($this->completed_occurrences ?? 0);
    }

    public function dueDateForOccurrence(int $occurrence): CarbonImmutable
    {
        $anchor = CarbonImmutable::parse($this->first_due_on->toDateString());
        $months = match ($this->frequency) {
            'quarterly' => $occurrence * 3,
            'yearly' => $occurrence * 12,
            default => 0,
        };

        // Always derive from the original date; a clamped February does not move later dates.
        return $anchor->startOfMonth()->addMonths($months)->day(
            min($anchor->day, $anchor->startOfMonth()->addMonths($months)->daysInMonth),
        );
    }

    public function frequencyLabel(): string
    {
        return match ($this->frequency) {
            'quarterly' => 'Quarterly',
            'yearly' => 'Yearly',
            default => 'One-off',
        };
    }

    public function dueLabel(?CarbonImmutable $date = null): string
    {
        $date ??= $this->nextDueDate();
        if (!$date instanceof CarbonImmutable) {
            return 'Completed';
        }

        // Compare calendar dates in UTC so daylight-saving transitions stay whole days.
        $days = (int) CarbonImmutable::parse(today()->toDateString(), 'UTC')
            ->diffInDays(CarbonImmutable::parse($date->toDateString(), 'UTC'), false);

        return match (true) {
            $days < 0 => 'Overdue by ' . abs($days) . ' ' . (abs($days) === 1 ? 'day' : 'days'),
            $days === 0 => 'Due today',
            $days === 1 => 'Due tomorrow',
            default => 'Due in ' . $days . ' days',
        };
    }
}
