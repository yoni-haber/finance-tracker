<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Transaction;
use App\Models\TransactionException;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TransactionExceptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_casts_configures_date_as_date(): void
    {
        $transactionException = new TransactionException();

        $this->assertSame('date', $transactionException->getCasts()['date']);
    }

    public function test_transaction_relation_returns_parent_transaction(): void
    {
        $transaction = Transaction::factory()->create();
        $exceptionDate = Carbon::parse('2024-06-15');

        $exception = TransactionException::create([
            'transaction_id' => $transaction->id,
            'date' => $exceptionDate,
        ]);

        $this->assertInstanceOf(Transaction::class, $exception->transaction);
        $this->assertTrue($exception->transaction->is($transaction));
        $this->assertInstanceOf(Carbon::class, $exception->date);
        $this->assertTrue($exception->date->equalTo($exceptionDate));
    }
}
