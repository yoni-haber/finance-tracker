<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $planned_bill_id
 * @property int $user_id
 * @property Carbon $expected_date
 * @property int $transaction_id
 * @property string|null $previous_estimated_amount
 * @property-read PlannedBill $bill
 * @property-read Transaction $transaction
 */
#[Fillable(['planned_bill_id', 'user_id', 'expected_date', 'transaction_id', 'previous_estimated_amount'])]
class PlannedBillPayment extends Model
{
    protected function casts(): array
    {
        return ['expected_date' => 'date'];
    }

    /** @return BelongsTo<PlannedBill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(PlannedBill::class, 'planned_bill_id');
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
