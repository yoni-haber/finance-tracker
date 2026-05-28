<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ImportedTransactionFactory;
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
 * @property int $import_id
 * @property Carbon $date
 * @property string $description
 * @property numeric $amount
 * @property string|null $external_id
 * @property int|null $category_id
 * @property string $hash
 * @property string|null $original_hash
 * @property bool $is_duplicate
 * @property bool $is_committed
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BankStatementImport $bankStatementImport
 *
 * @method static Builder<static>|ImportedTransaction committable()
 * @method static ImportedTransactionFactory factory($count = null, $state = [])
 * @method static Builder<static>|ImportedTransaction newModelQuery()
 * @method static Builder<static>|ImportedTransaction newQuery()
 * @method static Builder<static>|ImportedTransaction notCommitted()
 * @method static Builder<static>|ImportedTransaction notDuplicate()
 * @method static Builder<static>|ImportedTransaction query()
 * @method static Builder<static>|ImportedTransaction whereAmount($value)
 * @method static Builder<static>|ImportedTransaction whereCategoryId($value)
 * @method static Builder<static>|ImportedTransaction whereCreatedAt($value)
 * @method static Builder<static>|ImportedTransaction whereDate($value)
 * @method static Builder<static>|ImportedTransaction whereDescription($value)
 * @method static Builder<static>|ImportedTransaction whereExternalId($value)
 * @method static Builder<static>|ImportedTransaction whereHash($value)
 * @method static Builder<static>|ImportedTransaction whereId($value)
 * @method static Builder<static>|ImportedTransaction whereImportId($value)
 * @method static Builder<static>|ImportedTransaction whereIsCommitted($value)
 * @method static Builder<static>|ImportedTransaction whereIsDuplicate($value)
 * @method static Builder<static>|ImportedTransaction whereOriginalHash($value)
 * @method static Builder<static>|ImportedTransaction whereUpdatedAt($value)
 *
 * @mixin Eloquent
 */
#[Fillable([
    'import_id',
    'date',
    'description',
    'amount',
    'external_id',
    'category_id',
    'hash',
    'original_hash',
    'is_duplicate',
    'is_committed',
])]
class ImportedTransaction extends Model
{
    /** @use HasFactory<ImportedTransactionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'decimal:2',
            'is_duplicate' => 'boolean',
            'is_committed' => 'boolean',
        ];
    }

    /** @return BelongsTo<BankStatementImport, $this> */
    public function bankStatementImport(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'import_id');
    }

    /**
     * @param Builder<self> $builder
     * @return Builder<self>
     */
    public function scopeNotDuplicate(Builder $builder): Builder
    {
        return $builder->where('is_duplicate', false);
    }

    /**
     * @param Builder<self> $builder
     * @return Builder<self>
     */
    public function scopeNotCommitted(Builder $builder): Builder
    {
        return $builder->where('is_committed', false);
    }

    /**
     * @param Builder<self> $builder
     * @return Builder<self>
     */
    public function scopeCommittable(Builder $builder): Builder
    {
        return $builder->notDuplicate()->notCommitted();
    }
}
