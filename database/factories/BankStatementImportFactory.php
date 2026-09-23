<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BankProfile;
use App\Models\BankStatementImport;
use App\Models\User;
use App\Support\BankStatementConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankStatementImport>
 */
class BankStatementImportFactory extends Factory
{
    protected $model = BankStatementImport::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'bank_profile_id' => BankProfile::factory(),
            'original_filename' => $this->faker->word . '.csv',
            'status' => BankStatementConfig::STATUS_UPLOADED,
            'statement_type' => BankStatementConfig::STATEMENT_TYPE_BANK,
        ];
    }

    /**
     * Indicate that the import is for a credit card statement.
     */
    public function creditCard(): static
    {
        return $this->state(fn (array $attributes) => [
            'statement_type' => BankStatementConfig::STATEMENT_TYPE_CREDIT_CARD,
        ]);
    }

    /**
     * Indicate that the import is in parsing status.
     */
    public function parsing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BankStatementConfig::STATUS_PARSING,
        ]);
    }

    /**
     * Indicate that the import is in parsed status.
     */
    public function parsed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BankStatementConfig::STATUS_PARSED,
        ]);
    }

    /**
     * Indicate that the import is in committed status.
     */
    public function committed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BankStatementConfig::STATUS_COMMITTED,
        ]);
    }

    /**
     * Indicate that the import failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BankStatementConfig::STATUS_FAILED,
        ]);
    }
}
