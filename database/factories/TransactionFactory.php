<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        $type = fake()->randomElement([Transaction::TYPE_INCOME, Transaction::TYPE_EXPENSE]);

        return [
            'user_id' => User::factory(),
            'type' => $type,
            'category_id' => static fn (array $attributes): int => Category::factory()->create([
                'user_id' => $attributes['user_id'],
                'type' => $attributes['type'],
                'expense_treatment' => $attributes['type'] === Transaction::TYPE_EXPENSE
                    ? Category::TREATMENT_SPENDING
                    : null,
            ])->id,
            'amount' => $type === Transaction::TYPE_INCOME ? fake()->randomFloat(2, 200, 2000) : fake()->randomFloat(2, 10, 800),
            'date' => fake()->dateTimeBetween('-3 months', '+3 months'),
            'is_recurring' => false,
            'frequency' => null,
            'description' => fake()->sentence(),
        ];
    }

    public function recurring(string $frequency = 'monthly'): self
    {
        return $this->state(fn () => [
            'is_recurring' => true,
            'frequency' => $frequency,
        ]);
    }
}
