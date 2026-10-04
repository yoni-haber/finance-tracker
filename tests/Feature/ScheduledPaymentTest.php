<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Transactions\TransactionManager;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class ScheduledPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_editor_opens_only_for_its_new_action(): void
    {
        $user = User::factory()->create();
        Livewire::actingAs($user)->test(TransactionManager::class)
            ->assertNotDispatched('open-transaction-modal');
        Livewire::actingAs($user)->withQueryParams(['new' => 1])->test(TransactionManager::class)
            ->assertDispatched('open-transaction-modal');
        Livewire::actingAs($user)->withQueryParams(['scheduled' => 1, 'upcoming' => 1, 'edit' => 1])
            ->test(TransactionManager::class)->assertNotDispatched('open-transaction-modal');
    }

    public function test_quarterly_recorded_transaction_remains_supported_and_stays_on_transactions_after_save(): void
    {
        $user = User::factory()->create();
        Livewire::actingAs($user)->test(TransactionManager::class)
            ->set('amount', '180.00')->set('date', '2026-11-15')
            ->set('is_recurring', true)->set('frequency', 'quarterly')
            ->call('save')->assertHasNoErrors()->assertNoRedirect()
            ->assertDispatched('close-transaction-modal');
        $transaction = Transaction::sole();
        $this->assertSame('quarterly', $transaction->frequency);
        $this->assertDatabaseCount('planned_payments', 0);
    }
}
