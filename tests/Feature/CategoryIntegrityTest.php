<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Support\CategoryIntegrityAuditor;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Mockery;
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

    public function test_audit_command_passes_for_consistent_data(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();
        Transaction::factory()->for($user)->for($category)->create(['type' => Transaction::TYPE_EXPENSE]);

        $command = $this->artisan('categories:audit');
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command
            ->expectsOutputToContain('Category integrity audit passed.')
            ->assertSuccessful();
    }

    public function test_auditor_reports_mismatched_legacy_links_without_changing_them(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $category = Category::factory()->for($owner)->expense()->create();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $transactionId = DB::table('transactions')->insertGetId([
            'user_id' => $other->id,
            'category_id' => $category->id,
            'type' => Transaction::TYPE_INCOME,
            'amount' => '10.00',
            'date' => '2026-09-19',
            'is_recurring' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $issues = app(CategoryIntegrityAuditor::class)->issues();

        $this->assertSame([$transactionId], $issues['invalid transaction categories']);
        $this->assertDatabaseHas('transactions', ['id' => $transactionId]);

        $command = $this->artisan('categories:audit');
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command
            ->expectsOutputToContain('Category integrity problems were found.')
            ->expectsTable(
                ['Problem', 'Affected record IDs'],
                [['invalid transaction categories', (string) $transactionId]],
            )
            ->assertFailed();
    }

    public function test_auditor_reports_each_invalid_parent_relationship_and_ignores_valid_parents(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $validParent = Category::factory()->for($owner)->expense()->create();
        $validChild = Category::factory()->subcategoryOf($validParent)->create();
        $missingParent = Category::factory()->for($owner)->expense()->create();
        $selfParent = Category::factory()->for($owner)->expense()->create();
        $crossUser = Category::factory()->for($other)->expense()->create();
        $incomeParent = Category::factory()->for($owner)->income()->create();
        $crossType = Category::factory()->for($owner)->expense()->create();
        $thirdLevel = Category::factory()->for($owner)->expense()->create();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            DB::table('categories')->where('id', $missingParent->id)->update(['parent_id' => 999999]);
            DB::table('categories')->where('id', $selfParent->id)->update(['parent_id' => $selfParent->id]);
            DB::table('categories')->where('id', $crossUser->id)->update(['parent_id' => $validParent->id]);
            DB::table('categories')->where('id', $crossType->id)->update(['parent_id' => $incomeParent->id]);
            DB::table('categories')->where('id', $thirdLevel->id)->update(['parent_id' => $validChild->id]);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $issues = app(CategoryIntegrityAuditor::class)->issues();

        $this->assertSame([
            $missingParent->id,
            $selfParent->id,
            $crossUser->id,
            $crossType->id,
            $thirdLevel->id,
        ], $issues['invalid category parents']);
        $this->assertNotContains($validChild->id, $issues['invalid category parents']);
    }

    public function test_auditor_normalizes_duplicate_category_names_within_each_scope(): void
    {
        $categories = collect([
            (object) ['id' => 1, 'user_id' => 10, 'parent_id' => null, 'type' => 'expense', 'name' => 'Groceries'],
            (object) ['id' => 2, 'user_id' => 10, 'parent_id' => null, 'type' => 'expense', 'name' => 'GROCERIES '],
            (object) ['id' => 3, 'user_id' => 10, 'parent_id' => null, 'type' => 'income', 'name' => 'Groceries'],
            (object) ['id' => 4, 'user_id' => 11, 'parent_id' => null, 'type' => 'expense', 'name' => 'Groceries'],
            (object) ['id' => 20, 'user_id' => 10, 'parent_id' => null, 'type' => 'expense', 'name' => 'Food'],
            (object) ['id' => 21, 'user_id' => 10, 'parent_id' => null, 'type' => 'expense', 'name' => 'Transport'],
            (object) ['id' => 5, 'user_id' => 10, 'parent_id' => 20, 'type' => 'expense', 'name' => 'Lunch'],
            (object) ['id' => 6, 'user_id' => 10, 'parent_id' => 20, 'type' => 'expense', 'name' => 'LUNCH '],
            (object) ['id' => 7, 'user_id' => 10, 'parent_id' => 21, 'type' => 'expense', 'name' => 'Lunch'],
            (object) ['id' => 8, 'user_id' => 10, 'parent_id' => null, 'type' => 'expense', 'name' => 'Boundary'],
            (object) ['id' => 9, 'user_id' => 10, 'parent_id' => 1, 'type' => 'expense', 'name' => 'Boundary'],
            (object) ['id' => 10, 'user_id' => 10, 'parent_id' => -1, 'type' => 'expense', 'name' => 'Boundary'],
            (object) ['id' => 11, 'user_id' => 10, 'parent_id' => null, 'type' => 'expense', 'name' => 'ÄPFEL'],
            (object) ['id' => 12, 'user_id' => 10, 'parent_id' => null, 'type' => 'expense', 'name' => 'äpfel'],
            (object) ['id' => 100, 'user_id' => 10, 'parent_id' => null, 'type' => 'expense', 'name' => 'Repeated A'],
            (object) ['id' => 101, 'user_id' => 10, 'parent_id' => null, 'type' => 'expense', 'name' => 'REPEATED A'],
            (object) ['id' => 100, 'user_id' => 10, 'parent_id' => 20, 'type' => 'expense', 'name' => 'Repeated B'],
            (object) ['id' => 102, 'user_id' => 10, 'parent_id' => 20, 'type' => 'expense', 'name' => 'REPEATED B'],
            (object) ['id' => 200, 'user_id' => 10, 'parent_id' => 997, 'type' => 'expense', 'name' => 'Invalid A'],
            (object) ['id' => 201, 'user_id' => 10, 'parent_id' => 998, 'type' => 'expense', 'name' => 'Invalid B'],
            (object) ['id' => 200, 'user_id' => 10, 'parent_id' => 999, 'type' => 'expense', 'name' => 'Invalid C'],
            (object) ['id' => 202, 'user_id' => 10, 'parent_id' => 996, 'type' => 'expense', 'name' => 'Invalid D'],
        ]);

        $mock = Mockery::mock();
        $mock->shouldReceive('select')->once()->andReturnSelf();
        $mock->shouldReceive('orderBy')->once()->with('id')->andReturnSelf();
        $mock->shouldReceive('get')->once()->andReturn($categories);

        $emptyLinkedQuery = Mockery::mock();
        $emptyLinkedQuery->shouldReceive('leftJoin')->twice()->andReturnSelf();
        $emptyLinkedQuery->shouldReceive('whereNotNull')->once()->andReturnSelf();
        $emptyLinkedQuery->shouldReceive('where')->twice()->andReturnSelf();
        $emptyLinkedQuery->shouldReceive('orderBy')->twice()->andReturnSelf();
        $emptyLinkedQuery->shouldReceive('pluck')->twice()->andReturn(collect());

        DB::shouldReceive('table')->once()->with('categories')->andReturn($mock);
        DB::shouldReceive('table')->once()->with('transactions')->andReturn($emptyLinkedQuery);
        DB::shouldReceive('table')->once()->with('budgets')->andReturn($emptyLinkedQuery);

        $issues = app(CategoryIntegrityAuditor::class)->issues();

        $this->assertSame([1, 2, 5, 6, 11, 12, 100, 101, 102], $issues['duplicate category scopes']);
        $this->assertSame([10, 200, 201, 202], $issues['invalid category parents']);
    }

    public function test_auditor_returns_reindexed_integer_ids_from_database_results(): void
    {
        $mock = Mockery::mock();
        $mock->shouldReceive('select')->once()->andReturnSelf();
        $mock->shouldReceive('orderBy')->once()->with('id')->andReturnSelf();
        $mock->shouldReceive('get')->once()->andReturn(collect());

        $transactionQuery = Mockery::mock();
        $transactionQuery->shouldReceive('leftJoin')->once()->andReturnSelf();
        $transactionQuery->shouldReceive('whereNotNull')->once()->andReturnSelf();
        $transactionQuery->shouldReceive('where')->once()->andReturnSelf();
        $transactionQuery->shouldReceive('orderBy')->once()->andReturnSelf();
        $transactionQuery->shouldReceive('pluck')->once()->andReturn(collect([4 => '31', 8 => '32']));

        $budgetQuery = Mockery::mock();
        $budgetQuery->shouldReceive('leftJoin')->once()->andReturnSelf();
        $budgetQuery->shouldReceive('where')->once()->andReturnSelf();
        $budgetQuery->shouldReceive('orderBy')->once()->andReturnSelf();
        $budgetQuery->shouldReceive('pluck')->once()->andReturn(collect([3 => '41', 9 => '42']));

        DB::shouldReceive('table')->once()->with('categories')->andReturn($mock);
        DB::shouldReceive('table')->once()->with('transactions')->andReturn($transactionQuery);
        DB::shouldReceive('table')->once()->with('budgets')->andReturn($budgetQuery);

        $issues = app(CategoryIntegrityAuditor::class)->issues();

        $this->assertSame([31, 32], $issues['invalid transaction categories']);
        $this->assertSame([41, 42], $issues['invalid budget categories']);
    }

    public function test_auditor_reports_every_invalid_transaction_category_relationship(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $expense = Category::factory()->for($owner)->expense()->create();
        $valid = Transaction::factory()->for($owner)->for($expense)->create([
            'type' => Transaction::TYPE_EXPENSE,
        ]);

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            $missingCategoryId = DB::table('transactions')->insertGetId($this->transactionAttributes(
                userId: $owner->id,
                categoryId: 999999,
                type: Transaction::TYPE_EXPENSE,
            ));
            $crossUserId = DB::table('transactions')->insertGetId($this->transactionAttributes(
                userId: $other->id,
                categoryId: $expense->id,
                type: Transaction::TYPE_EXPENSE,
            ));
            $crossTypeId = DB::table('transactions')->insertGetId($this->transactionAttributes(
                userId: $owner->id,
                categoryId: $expense->id,
                type: Transaction::TYPE_INCOME,
            ));
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $issues = app(CategoryIntegrityAuditor::class)->issues();

        $this->assertSame(
            [$missingCategoryId, $crossUserId, $crossTypeId],
            $issues['invalid transaction categories'],
        );
        $this->assertNotContains($valid->id, $issues['invalid transaction categories']);
    }

    public function test_auditor_reports_every_invalid_budget_category_relationship(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $spending = Category::factory()->for($owner)->expense()->create();
        $income = Category::factory()->for($owner)->income()->create();
        $saving = Category::factory()->for($owner)->expense()->create([
            'expense_treatment' => Category::TREATMENT_SAVING,
        ]);
        $nullTreatment = Category::factory()->for($owner)->expense()->create([
            'expense_treatment' => Category::TREATMENT_SPENDING,
        ]);
        $child = Category::factory()->subcategoryOf($spending)->create();
        $valid = Budget::factory()->for($owner)->for($spending)->create();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            DB::table('categories')->where('id', $nullTreatment->id)->update(['expense_treatment' => null]);

            $missingCategoryId = DB::table('budgets')->insertGetId($this->budgetAttributes($owner->id, 999999));
            $crossUserId = DB::table('budgets')->insertGetId($this->budgetAttributes($other->id, $spending->id));
            $incomeId = DB::table('budgets')->insertGetId($this->budgetAttributes($owner->id, $income->id));
            $childId = DB::table('budgets')->insertGetId($this->budgetAttributes($owner->id, $child->id));
            $nullTreatmentId = DB::table('budgets')->insertGetId($this->budgetAttributes($owner->id, $nullTreatment->id));
            $savingId = DB::table('budgets')->insertGetId($this->budgetAttributes($owner->id, $saving->id));
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $issues = app(CategoryIntegrityAuditor::class)->issues();

        $this->assertSame(
            [$missingCategoryId, $crossUserId, $incomeId, $childId, $nullTreatmentId, $savingId],
            $issues['invalid budget categories'],
        );
        $this->assertNotContains($valid->id, $issues['invalid budget categories']);
    }

    public function test_auditor_summary_includes_every_issue_name_and_id_in_order(): void
    {
        $summary = app(CategoryIntegrityAuditor::class)->summary([
            'invalid category parents' => [7, 11],
            'invalid transaction categories' => [13],
        ]);

        $this->assertSame(
            'invalid category parents [7, 11]; invalid transaction categories [13]',
            $summary,
        );
    }

    /** @return array<string, mixed> */
    private function transactionAttributes(int $userId, int $categoryId, string $type): array
    {
        return [
            'user_id' => $userId,
            'category_id' => $categoryId,
            'type' => $type,
            'amount' => '10.00',
            'date' => '2026-09-19',
            'is_recurring' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @return array<string, mixed> */
    private function budgetAttributes(int $userId, int $categoryId): array
    {
        return [
            'user_id' => $userId,
            'category_id' => $categoryId,
            'amount' => '100.00',
            'month' => 9,
            'year' => 2026,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
