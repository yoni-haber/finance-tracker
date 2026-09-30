<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PlannedBill;
use App\Models\PlannedBillPayment;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

final class PlannedBillReport
{
    /**
     * @return Collection<int, array{bill: PlannedBill, date: CarbonImmutable, payment: PlannedBillPayment|null}>
     */
    public static function forRange(int $userId, DateTimeInterface $start, DateTimeInterface $end): Collection
    {
        $first = CarbonImmutable::instance($start)->startOfDay();
        $last = CarbonImmutable::instance($end)->endOfDay();

        return PlannedBill::forUser($userId)
            ->with(['category', 'payments.transaction'])
            ->get()
            ->flatMap(function (PlannedBill $plannedBill) use ($first, $last): Collection {
                $paid = $plannedBill->payments
                    ->filter(fn (PlannedBillPayment $plannedBillPayment): bool => $plannedBillPayment->expected_date->betweenIncluded($first, $last))
                    ->map(fn (PlannedBillPayment $plannedBillPayment): array => [
                        'bill' => $plannedBill,
                        'date' => $plannedBillPayment->expected_date->toImmutable(),
                        'payment' => $plannedBillPayment,
                    ]);

                $expected = $plannedBill->unpaidDatesThrough($last)
                    ->filter(fn (CarbonImmutable $date): bool => $date->greaterThanOrEqualTo($first))
                    ->map(fn (CarbonImmutable $date): array => [
                        'bill' => $plannedBill,
                        'date' => $date,
                        'payment' => null,
                    ]);

                return $paid->concat($expected);
            })
            ->sortBy(fn (array $row): string => $row['date']->format('Y-m-d') . '|' . $row['bill']->name)
            ->values();
    }
}
