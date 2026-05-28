<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\TransactionException;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransactionException>
 */
class TransactionExceptionFactory extends Factory
{
    protected $model = TransactionException::class;

    public function definition(): array
    {
        return [
            'transaction_id' => Transaction::factory(),
            'date' => fake()->dateTimeBetween('-1 month', '+1 month'),
        ];
    }
}
