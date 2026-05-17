<?php

namespace App\Models;

use Database\Factories\TransactionExceptionFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $transaction_id
 * @property Carbon $date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Transaction $transaction
 *
 * @method static TransactionExceptionFactory factory($count = null, $state = [])
 * @method static Builder<static>|TransactionException newModelQuery()
 * @method static Builder<static>|TransactionException newQuery()
 * @method static Builder<static>|TransactionException query()
 * @method static Builder<static>|TransactionException whereCreatedAt($value)
 * @method static Builder<static>|TransactionException whereDate($value)
 * @method static Builder<static>|TransactionException whereId($value)
 * @method static Builder<static>|TransactionException whereTransactionId($value)
 * @method static Builder<static>|TransactionException whereUpdatedAt($value)
 *
 * @mixin Eloquent
 */
class TransactionException extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'date',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
