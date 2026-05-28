<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NetWorthEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<NetWorthEntry> */
class NetWorthEntryFactory extends Factory
{
    /** @var class-string<NetWorthEntry> */
    protected $model = NetWorthEntry::class;

    public function definition(): array
    {
        $assets = $this->faker->randomFloat(2, 1000, 100000);
        $liabilities = $this->faker->randomFloat(2, 0, $assets * 0.8);

        return [
            'date' => $this->faker->date(),
            'assets' => $assets,
            'liabilities' => $liabilities,
            'net_worth' => $assets - $liabilities,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),

            'user_id' => User::factory(),
        ];
    }
}
