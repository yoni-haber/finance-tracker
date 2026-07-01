<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Immutable month + year period used by the global period selector.
 */
final readonly class SelectedPeriod
{
    public function __construct(
        public int $month,
        public int $year,
    ) {}

    /** The period covering the current month. */
    public static function current(): self
    {
        $now = CarbonImmutable::now();

        return new self($now->month, $now->year);
    }

    /** First day of the period as an immutable date. */
    public function startOfMonth(): CarbonImmutable
    {
        $date = CarbonImmutable::create($this->year, $this->month, 1);
        assert($date instanceof CarbonImmutable);

        return $date->startOfDay();
    }

    /** Human-readable label, e.g. "May 2024". */
    public function label(): string
    {
        return $this->startOfMonth()->format('F Y');
    }

    /** The period one month earlier. */
    public function previous(): self
    {
        $date = $this->startOfMonth()->subMonth();

        return new self($date->month, $date->year);
    }

    /** The period one-month later. */
    public function next(): self
    {
        $date = $this->startOfMonth()->addMonth();

        return new self($date->month, $date->year);
    }

    /** Whether this period is the current calendar month. */
    public function isCurrentMonth(): bool
    {
        $now = CarbonImmutable::now();

        return $this->month === $now->month && $this->year === $now->year;
    }
}
