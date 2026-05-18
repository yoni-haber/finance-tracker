<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Transactions\TransactionManager;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Support\TransactionReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class RecurringTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_recurring_transactions_project_across_month(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();

        Transaction::factory()->for($user)->for($category)->recurring('weekly')->create([
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => 25,
            'date' => '2024-03-01',
        ]);

        $transactions = TransactionReport::projectedForMonth($user->id, 3, 2024);

        $this->assertCount(5, $transactions->where('type', Transaction::TYPE_EXPENSE));
        $this->assertEqualsWithDelta(125.0, $transactions->where('type', Transaction::TYPE_EXPENSE)->sum('amount'), PHP_FLOAT_EPSILON);
    }

    public function test_delete_rejects_invalid_occurrence_date(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();
        $transaction = Transaction::factory()->for($user)->for($category)->recurring('monthly')->create([
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => 50,
            'date' => '2024-05-01',
        ]);

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->call('delete', $transaction->id, 'invalid-date')
            ->assertHasErrors('delete');

        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
        $this->assertDatabaseCount('transaction_exceptions', 0);
    }

    public function test_delete_skips_single_recurring_occurrence_without_removing_series(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();
        $transaction = Transaction::factory()->for($user)->for($category)->recurring('weekly')->create([
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => 25,
            'date' => '2024-05-01',
        ]);

        Livewire::actingAs($user)
            ->test(TransactionManager::class)
            ->call('delete', $transaction->id, '2024-05-08')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
        $this->assertDatabaseHas('transaction_exceptions', [
            'transaction_id' => $transaction->id,
            'date' => Carbon::parse('2024-05-08')->toDateTimeString(),
        ]);
    }
}
