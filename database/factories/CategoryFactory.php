<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->word(),
            'type' => fake()->randomElement([Category::TYPE_INCOME, Category::TYPE_EXPENSE]),
            'parent_id' => null,
        ];
    }

    public function income(): static
    {
        return $this->state(['type' => Category::TYPE_INCOME]);
    }

    public function expense(): static
    {
        return $this->state(['type' => Category::TYPE_EXPENSE]);
    }

    /** Create a subcategory belonging to the given parent. */
    public function subcategoryOf(Category $parent): static
    {
        return $this->state([
            'parent_id' => $parent->id,
            'user_id' => $parent->user_id,
            'type' => $parent->type,
        ]);
    }
}
