<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BankProfile;
use App\Models\User;
use App\Support\BankStatementConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankProfile>
 */
class BankProfileFactory extends Factory
{
    protected $model = BankProfile::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->company . ' Bank',
            'statement_type' => BankStatementConfig::STATEMENT_TYPE_BANK,
            'config' => [
                'columns' => [
                    'date' => 0,
                    'description' => 1,
                    'amount' => 2,
                ],
                'date_format' => 'd/m/Y',
            ],
        ];
    }

    /**
     * Indicate that the bank profile is for credit card statements.
     */
    public function creditCard(): static
    {
        return $this->state(fn (array $attributes) => [
            'statement_type' => BankStatementConfig::STATEMENT_TYPE_CREDIT_CARD,
        ]);
    }

    /**
     * Indicate that the bank profile uses separate debit/credit columns.
     */
    public function separateColumns(): static
    {
        return $this->state(fn (array $attributes) => [
            'config' => [
                'columns' => [
                    'date' => 0,
                    'description' => 1,
                    'debit' => 2,
                    'credit' => 3,
                ],
                'date_format' => 'd/m/Y',
            ],
        ]);
    }

    /**
     * Configure for US date format.
     */
    public function usDateFormat(): static
    {
        return $this->state(function (array $attributes) {
            $config = $attributes['config'];
            $config['date_format'] = 'm/d/Y';

            return ['config' => $config];
        });
    }

    /**
     * Configure for ISO date format.
     */
    public function isoDateFormat(): static
    {
        return $this->state(function (array $attributes) {
            $config = $attributes['config'];
            $config['date_format'] = 'Y-m-d';

            return ['config' => $config];
        });
    }
}
