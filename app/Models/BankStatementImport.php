<?php

namespace App\Models;

use App\Support\BankStatementConfig;
use Database\Factories\BankStatementImportFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $original_filename
 * @property string $status
 * @property int|null $bank_profile_id
 * @property string $statement_type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BankProfile|null $bankProfile
 * @property-read Collection<int, ImportedTransaction> $importedTransactions
 * @property-read int|null $imported_transactions_count
 * @property-read User $user
 *
 * @method static BankStatementImportFactory factory($count = null, $state = [])
 * @method static Builder<static>|BankStatementImport forUser(int $userId)
 * @method static Builder<static>|BankStatementImport newModelQuery()
 * @method static Builder<static>|BankStatementImport newQuery()
 * @method static Builder<static>|BankStatementImport query()
 * @method static Builder<static>|BankStatementImport whereBankProfileId($value)
 * @method static Builder<static>|BankStatementImport whereCreatedAt($value)
 * @method static Builder<static>|BankStatementImport whereId($value)
 * @method static Builder<static>|BankStatementImport whereOriginalFilename($value)
 * @method static Builder<static>|BankStatementImport whereStatementType($value)
 * @method static Builder<static>|BankStatementImport whereStatus($value)
 * @method static Builder<static>|BankStatementImport whereUpdatedAt($value)
 * @method static Builder<static>|BankStatementImport whereUserId($value)
 *
 * @mixin Eloquent
 */
#[Fillable([
    'user_id',
    'original_filename',
    'status',
    'bank_profile_id',
    'statement_type',
])]
class BankStatementImport extends Model
{
    /** @use HasFactory<BankStatementImportFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<BankProfile, $this> */
    public function bankProfile(): BelongsTo
    {
        return $this->belongsTo(BankProfile::class);
    }

    /** @return HasMany<ImportedTransaction, $this> */
    public function importedTransactions(): HasMany
    {
        return $this->hasMany(ImportedTransaction::class, 'import_id');
    }

    /**
     * @param Builder<self> $builder
     * @return Builder<self>
     */
    public function scopeForUser(Builder $builder, int $userId): Builder
    {
        return $builder->where('user_id', $userId);
    }

    public function isUploaded(): bool
    {
        return $this->status === BankStatementConfig::STATUS_UPLOADED;
    }

    public function isParsing(): bool
    {
        return $this->status === BankStatementConfig::STATUS_PARSING;
    }

    public function isParsed(): bool
    {
        return $this->status === BankStatementConfig::STATUS_PARSED;
    }

    public function isFailed(): bool
    {
        return $this->status === BankStatementConfig::STATUS_FAILED;
    }

    public function isCommitted(): bool
    {
        return $this->status === BankStatementConfig::STATUS_COMMITTED;
    }

    public function isBankStatement(): bool
    {
        return $this->statement_type === BankStatementConfig::STATEMENT_TYPE_BANK;
    }

    public function isCreditCardStatement(): bool
    {
        return $this->statement_type === BankStatementConfig::STATEMENT_TYPE_CREDIT_CARD;
    }
}
