<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PlannedPayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection as SupportCollection;

final class PlannedPayments
{
    public static function outstanding(int $userId): SupportCollection
    {
        return PlannedPayment::query()->where('user_id', $userId)->get()
            ->filter(fn (PlannedPayment $plannedPayment): bool => $plannedPayment->nextDueDate() instanceof CarbonImmutable)
            ->sort(fn (PlannedPayment $a, PlannedPayment $b): int => ($a->nextDueDate() <=> $b->nextDueDate()) ?: ($a->id <=> $b->id))
            ->values();
    }

    /** @return SupportCollection<int, array{plan: PlannedPayment, due: CarbonImmutable, is_next: bool}> */
    public static function nextTwelveMonths(int $userId): SupportCollection
    {
        $today = CarbonImmutable::parse(today()->toDateString());
        $end = $today->addYear();
        $occurrences = collect();

        foreach (self::outstanding($userId) as $plan) {
            $nextIndex = $plan->completed_occurrences;
            $index = $nextIndex;
            $due = $plan->dueDateForOccurrence($index);

            if ($due->isBefore($today)) {
                $occurrences->push(['plan' => $plan, 'due' => $due, 'is_next' => true]);
            }

            if ($plan->frequency === 'once') {
                if ($due->greaterThanOrEqualTo($today)) {
                    $occurrences->push(['plan' => $plan, 'due' => $due, 'is_next' => true]);
                }

                continue;
            }

            while ($due->isBefore($today)) {
                $index++;
                $due = $plan->dueDateForOccurrence($index);
            }

            if ($index === $nextIndex && $due->isAfter($end)) {
                $occurrences->push(['plan' => $plan, 'due' => $due, 'is_next' => true]);
            }

            while ($due->lessThanOrEqualTo($end)) {
                $occurrences->push(['plan' => $plan, 'due' => $due, 'is_next' => $index === $nextIndex]);
                $index++;
                $due = $plan->dueDateForOccurrence($index);
            }
        }

        return $occurrences
            ->sort(fn (array $a, array $b): int => ($a['due'] <=> $b['due']) ?: ($a['plan']->id <=> $b['plan']->id))
            ->values();
    }
}
