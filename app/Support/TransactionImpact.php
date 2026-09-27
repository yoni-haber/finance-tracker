<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Transaction;

final class TransactionImpact
{
    public static function incomePennies(Transaction $transaction): int
    {
        return $transaction->type === Transaction::TYPE_INCOME
            ? Money::normalize($transaction->amount)
            : 0;
    }

    public static function expensePennies(Transaction $transaction): int
    {
        return $transaction->type === Transaction::TYPE_EXPENSE ? Money::normalize($transaction->amount) : 0;
    }
}
