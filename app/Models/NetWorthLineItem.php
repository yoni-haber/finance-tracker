<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\NetWorthLineItemFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property int $net_worth_entry_id
 * @property int $user_id
 * @property string $type
 * @property string $category
 * @property numeric $amount
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read NetWorthEntry $netWorthEntry
 * @property-read User $user
 *
 * @method static NetWorthLineItemFactory factory($count = null, $state = [])
 * @method static Builder<static>|NetWorthLineItem newModelQuery()
 * @method static Builder<static>|NetWorthLineItem newQuery()
 * @method static Builder<static>|NetWorthLineItem query()
 * @method static Builder<static>|NetWorthLineItem whereAmount($value)
 * @method static Builder<static>|NetWorthLineItem whereCategory($value)
 * @method static Builder<static>|NetWorthLineItem whereCreatedAt($value)
 * @method static Builder<static>|NetWorthLineItem whereId($value)
 * @method static Builder<static>|NetWorthLineItem whereNetWorthEntryId($value)
 * @method static Builder<static>|NetWorthLineItem whereType($value)
 * @method static Builder<static>|NetWorthLineItem whereUpdatedAt($value)
 * @method static Builder<static>|NetWorthLineItem whereUserId($value)
 *
 * @mixin Eloquent
 */
#[Fillable([
    'net_worth_entry_id',
    'user_id',
    'type',
    'category',
    'amount',
])]
class NetWorthLineItem extends Model
{
    /** @use HasFactory<NetWorthLineItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<NetWorthEntry, $this> */
    public function netWorthEntry(): BelongsTo
    {
        return $this->belongsTo(NetWorthEntry::class, 'net_worth_entry_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
