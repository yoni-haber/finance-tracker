<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CategoryIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_enforces_unique_names_for_top_level_and_child_scopes(): void
    {
        $user = User::factory()->create();
        $parent = Category::factory()->for($user)->expense()->create(['name' => 'Food']);
        Category::factory()->subcategoryOf($parent)->create(['name' => 'Groceries']);

        try {
            Category::factory()->subcategoryOf($parent)->create(['name' => 'Groceries']);
            $this->fail('Expected the child category unique constraint to reject a duplicate.');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(UniqueConstraintViolationException::class);

        Category::factory()->for($user)->expense()->create(['name' => 'Food']);
    }

    public function test_model_rejects_self_parenting_and_a_third_level(): void
    {
        $user = User::factory()->create();
        $parent = Category::factory()->for($user)->expense()->create();
        $child = Category::factory()->subcategoryOf($parent)->create();

        try {
            $parent->update(['parent_id' => $parent->id]);
            $this->fail('Expected self-parenting to be rejected.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(DomainException::class);

        Category::factory()->subcategoryOf($child)->create();
    }

    public function test_database_parent_constraint_rejects_cross_user_or_type_links(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $parent = Category::factory()->for($owner)->expense()->create();
        $child = Category::factory()->for($other)->expense()->create();

        $this->expectException(QueryException::class);

        DB::table('categories')->where('id', $child->id)->update(['parent_id' => $parent->id]);
    }

    public function test_database_transaction_constraint_rejects_mismatched_category_scope(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $category = Category::factory()->for($owner)->expense()->create();

        $this->expectException(QueryException::class);

        DB::table('transactions')->insert([
            'user_id' => $other->id,
            'category_id' => $category->id,
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => '10.00',
            'date' => '2026-09-19',
            'is_recurring' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
