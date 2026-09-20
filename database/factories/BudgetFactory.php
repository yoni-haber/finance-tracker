<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Budget>
 */
class BudgetFactory extends Factory
{
    protected $model = Budget::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category_id' => static fn (array $attributes): int => Category::factory()->expense()->create([
                'user_id' => $attributes['user_id'],
            ])->id,
            'month' => now()->month,
            'year' => now()->year,
            'amount' => fake()->numberBetween(50, 1500),
        ];
    }
}
