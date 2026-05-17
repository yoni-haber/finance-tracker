<?php

namespace App\Models;

use App\Support\BankStatementConfig;
use Database\Factories\BankProfileFactory;
use Eloquent;
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
 * @property string $name
 * @property string $statement_type
 * @property array<array-key, mixed> $config
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, BankStatementImport> $bankStatementImports
 * @property-read int|null $bank_statement_imports_count
 * @property-read User $user
 *
 * @method static BankProfileFactory factory($count = null, $state = [])
 * @method static Builder<static>|BankProfile newModelQuery()
 * @method static Builder<static>|BankProfile newQuery()
 * @method static Builder<static>|BankProfile query()
 * @method static Builder<static>|BankProfile whereConfig($value)
 * @method static Builder<static>|BankProfile whereCreatedAt($value)
 * @method static Builder<static>|BankProfile whereId($value)
 * @method static Builder<static>|BankProfile whereName($value)
 * @method static Builder<static>|BankProfile whereStatementType($value)
 * @method static Builder<static>|BankProfile whereUpdatedAt($value)
 * @method static Builder<static>|BankProfile whereUserId($value)
 *
 * @mixin Eloquent
 */
class BankProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'statement_type',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bankStatementImports(): HasMany
    {
        return $this->hasMany(BankStatementImport::class);
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
