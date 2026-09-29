<?php

declare(strict_types=1);

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

            $this->seedEntriesForUser($user);
        });
    }

    private function seedEntriesForUser(User $user): void
    {
        $now = Carbon::now();
        $balances = [
            [3800, 2100, 13400, 30700, 1450, 8600],
            [4150, 2350, 13950, 31400, 1310, 8350],
            [3900, 2600, 14600, 32300, 1675, 8100],
            [4400, 2850, 15150, 32900, 1240, 7850],
            [4250, 3100, 15800, 33800, 1520, 7600],
            [4700, 3400, 16450, 34600, 1180, 7350],
        ];

        foreach ($balances as $index => [$checking, $savings, $brokerage, $retirement, $creditCard, $autoLoan]) {
            $date = $now->copy()->startOfMonth()->subMonths(5 - $index);
            if ($index !== 5) {
                $date->endOfMonth();
            }
            $lineItemDefinitions = [
                'asset' => [
                    'Checking account' => $checking,
                    'Savings account' => $savings,
                    'Brokerage' => $brokerage,
                    'Retirement account' => $retirement,
                ],
                'liability' => [
                    'Credit card' => $creditCard,
                    'Auto loan' => $autoLoan,
                ],
            ];
            $assets = 0;
            $liabilities = 0;
            foreach ($lineItemDefinitions['asset'] as $amount) {
                $assets += $amount;
            }
            foreach ($lineItemDefinitions['liability'] as $amount) {
                $liabilities += $amount;
            }

            $entry = NetWorthEntry::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'date' => $date->toDateString(),
                ],
                [
                    'assets' => $assets,
                    'liabilities' => $liabilities,
                    'net_worth' => $assets - $liabilities,
                ],
            );

            foreach ($lineItemDefinitions as $type => $lineItems) {
                foreach ($lineItems as $category => $amount) {
                    NetWorthLineItem::updateOrCreate(
                        [
                            'net_worth_entry_id' => $entry->id,
                            'user_id' => $user->id,
                            'type' => $type,
                            'category' => $category,
                        ],
                        ['amount' => $amount],
                    );
                }
            }
        }
    }
}
