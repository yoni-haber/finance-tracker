<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NetWorthEntry;
use App\Models\NetWorthLineItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<NetWorthLineItem> */
class NetWorthLineItemFactory extends Factory
{
    /** @var class-string<NetWorthLineItem> */
    protected $model = NetWorthLineItem::class;

    public function definition(): array
    {
        return [
            'type' => $this->faker->randomElement(['asset', 'liability']),
            'category' => $this->faker->word(),
            'amount' => $this->faker->randomFloat(2, 100, 10000),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),

            'net_worth_entry_id' => NetWorthEntry::factory(),
            'user_id' => User::factory(),
        ];
    }
}
