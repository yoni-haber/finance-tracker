<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BankStatementImport;
use App\Models\ImportedTransaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportedTransaction>
 */
class ImportedTransactionFactory extends Factory
{
    protected $model = ImportedTransaction::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = $this->faker->randomFloat(2, -1000, 1000);
        $date = $this->faker->dateTimeBetween('-1 year', 'now');
        $description = strtoupper($this->faker->sentence(3));

        // Generate a simple hash based on the transaction details
        $hash = sha1("1_{$date->format('Y-m-d')}_{$amount}_{$description}");

        return [
            'import_id' => BankStatementImport::factory(),
            'date' => $date,
            'description' => $description,
            'amount' => $amount,
            'hash' => $hash,
            'external_id' => null,
            'category_id' => null,
            'is_duplicate' => false,
            'is_committed' => false,
        ];
    }

    /**
     * Indicate that the transaction is a duplicate.
     */
    public function duplicate(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_duplicate' => true,
        ]);
    }

    /**
     * Indicate that the transaction has been committed.
     */
    public function committed(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_committed' => true,
        ]);
    }

    /**
     * Set the transaction as an income (positive amount).
     */
    public function income(?float $amount = null): static
    {
        return $this->state(fn (array $attributes) => [
            'amount' => $amount ?? $this->faker->randomFloat(2, 1, 1000),
        ]);
    }

    /**
     * Set the transaction as an expense (negative amount).
     */
    public function expense(?float $amount = null): static
    {
        return $this->state(fn (array $attributes) => [
            'amount' => -abs($amount ?? $this->faker->randomFloat(2, 1, 1000)),
        ]);
    }

    /**
     * Assign a category to the imported transaction.
     */
    public function withCategory(int $categoryId): static
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => $categoryId,
        ]);
    }

    /**
     * Set a specific hash (useful for testing deduplication).
     */
    public function withHash(string $hash): static
    {
        return $this->state(fn (array $attributes) => [
            'hash' => $hash,
        ]);
    }

    /**
     * Set a specific date.
     */
    public function onDate(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => Carbon::parse($date),
        ]);
    }

    /**
     * Set a specific description.
     */
    public function withDescription(string $description): static
    {
        return $this->state(fn (array $attributes) => [
            'description' => strtoupper($description),
        ]);
    }
}
