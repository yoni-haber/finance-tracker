<?php

namespace Database\Seeders;

use App\Models\NetWorthEntry;
use App\Models\NetWorthLineItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NetWorthSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $user = User::where('email', 'alex@example.com')->firstOrFail();

            $this->seedEntriesForUser($user, [
                'Checking account' => 4200.50,
                'Brokerage' => 15200.75,
                'Retirement 401k' => 33800.00,
                'Credit card' => -1200.40,
                'Auto loan' => -7300.00,
            ]);
        });
    }

    /** @param array<string, float> $lineItemDefinitions */
    private function seedEntriesForUser(User $user, array $lineItemDefinitions): void
    {
        $now = Carbon::now();

        $checkpoints = [
            $now->copy()->subMonths(2)->startOfMonth(),
            $now->copy()->startOfMonth(),
            $now->copy()->addMonth()->startOfMonth(),
        ];

        foreach ($checkpoints as $date) {
            $assets = 0;
            $liabilities = 0;
            $entryLineItems = [];

            foreach ($lineItemDefinitions as $category => $amount) {
                if ($amount >= 0) {
                    $scaledAmount = $this->scaleAmountForDate($amount, $date, $now);
                    $assets += $scaledAmount;
                    $entryLineItems[] = [
                        'type' => 'asset',
                        'category' => $category,
                        'amount' => $scaledAmount,
                    ];
                } else {
                    $scaledAmount = $this->scaleAmountForDate(abs($amount), $date, $now);
                    $liabilities += $scaledAmount;
                    $entryLineItems[] = [
                        'type' => 'liability',
                        'category' => $category,
                        'amount' => $scaledAmount,
                    ];
                }
            }

            $netWorth = $assets - $liabilities;

            $entry = NetWorthEntry::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'date' => $date->toDateString(),
                ],
                [
                    'assets' => $assets,
                    'liabilities' => $liabilities,
                    'net_worth' => $netWorth,
                ],
            );

            foreach ($entryLineItems as $lineItem) {
                NetWorthLineItem::updateOrCreate(
                    [
                        'net_worth_entry_id' => $entry->id,
                        'user_id' => $user->id,
                        'type' => $lineItem['type'],
                        'category' => $lineItem['category'],
                    ],
                    [
                        'amount' => $lineItem['amount'],
                    ],
                );
            }
        }
    }

    private function scaleAmountForDate(float $amount, Carbon $date, Carbon $baseline): float
    {
        // Apply a light deterministic drift so each month captures different balances.
        $monthsDifference = $baseline->diffInMonths($date, false);
        $adjustment = $monthsDifference * 0.01; // +/-1% per month drift

        return round($amount * (1 + $adjustment), 2);
    }
}
