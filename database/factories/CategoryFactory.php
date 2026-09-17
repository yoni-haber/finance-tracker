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
        $type = fake()->randomElement([Category::TYPE_INCOME, Category::TYPE_EXPENSE]);

        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->word(),
            'type' => $type,
            'expense_treatment' => $type === Category::TYPE_EXPENSE ? Category::TREATMENT_SPENDING : null,
            'parent_id' => null,
        ];
    }

    public function income(): static
    {
        return $this->state([
            'type' => Category::TYPE_INCOME,
            'expense_treatment' => null,
        ]);
    }

    public function expense(): static
    {
        return $this->state([
            'type' => Category::TYPE_EXPENSE,
            'expense_treatment' => Category::TREATMENT_SPENDING,
        ]);
    }

    /** Create a subcategory belonging to the given parent. */
    public function subcategoryOf(Category $parent): static
    {
        return $this->state([
            'parent_id' => $parent->id,
            'user_id' => $parent->user_id,
            'type' => $parent->type,
            'expense_treatment' => null,
        ]);
    }
}
