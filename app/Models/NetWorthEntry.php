<?php

namespace App\Models;

use Database\Factories\NetWorthEntryFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property int $user_id
 * @property Carbon $date
 * @property numeric $assets
 * @property numeric $liabilities
 * @property numeric $net_worth
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, NetWorthLineItem> $lineItems
 * @property-read int|null $line_items_count
 * @property-read User $user
 *
 * @method static NetWorthEntryFactory factory($count = null, $state = [])
 * @method static Builder<static>|NetWorthEntry newModelQuery()
 * @method static Builder<static>|NetWorthEntry newQuery()
 * @method static Builder<static>|NetWorthEntry query()
 * @method static Builder<static>|NetWorthEntry whereAssets($value)
 * @method static Builder<static>|NetWorthEntry whereCreatedAt($value)
 * @method static Builder<static>|NetWorthEntry whereDate($value)
 * @method static Builder<static>|NetWorthEntry whereId($value)
 * @method static Builder<static>|NetWorthEntry whereLiabilities($value)
 * @method static Builder<static>|NetWorthEntry whereNetWorth($value)
 * @method static Builder<static>|NetWorthEntry whereUpdatedAt($value)
 * @method static Builder<static>|NetWorthEntry whereUserId($value)
 *
 * @mixin Eloquent
 */
#[Fillable([
    'user_id',
    'date',
    'assets',
    'liabilities',
    'net_worth',
])]
class NetWorthEntry extends Model
{
    /** @use HasFactory<NetWorthEntryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'assets' => 'decimal:2',
            'liabilities' => 'decimal:2',
            'net_worth' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<NetWorthLineItem, $this> */
    public function lineItems(): HasMany
    {
        return $this->hasMany(NetWorthLineItem::class);
    }
}
