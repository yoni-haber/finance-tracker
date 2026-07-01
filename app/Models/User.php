<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\SelectedPeriod;
use Database\Factories\UserFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Override;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property string|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $selected_month
 * @property int|null $selected_year
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, BankProfile> $bankProfiles
 * @property-read int|null $bank_profiles_count
 * @property-read Collection<int, BankStatementImport> $bankStatementImports
 * @property-read int|null $bank_statement_imports_count
 * @property-read Collection<int, Budget> $budgets
 * @property-read int|null $budgets_count
 * @property-read Collection<int, Category> $categories
 * @property-read int|null $categories_count
 * @property-read Collection<int, NetWorthEntry> $netWorthEntries
 * @property-read int|null $net_worth_entries_count
 * @property-read Collection<int, NetWorthLineItem> $netWorthLineItems
 * @property-read int|null $net_worth_line_items_count
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Collection<int, Transaction> $transactions
 * @property-read int|null $transactions_count
 *
 * @method static UserFactory factory($count = null, $state = [])
 * @method static Builder<static>|User newModelQuery()
 * @method static Builder<static>|User newQuery()
 * @method static Builder<static>|User query()
 * @method static Builder<static>|User whereCreatedAt($value)
 * @method static Builder<static>|User whereEmail($value)
 * @method static Builder<static>|User whereEmailVerifiedAt($value)
 * @method static Builder<static>|User whereId($value)
 * @method static Builder<static>|User whereName($value)
 * @method static Builder<static>|User wherePassword($value)
 * @method static Builder<static>|User whereRememberToken($value)
 * @method static Builder<static>|User whereTwoFactorConfirmedAt($value)
 * @method static Builder<static>|User whereTwoFactorRecoveryCodes($value)
 * @method static Builder<static>|User whereTwoFactorSecret($value)
 * @method static Builder<static>|User whereUpdatedAt($value)
 *
 * @mixin Eloquent
 */
#[Fillable([
    'name',
    'email',
    'password',
    'selected_month',
    'selected_year',
])]
#[Hidden([
    'password',
    'two_factor_secret',
    'two_factor_recovery_codes',
    'remember_token',
])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'selected_month' => 'integer',
            'selected_year' => 'integer',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return HasMany<Category, $this> */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /** @return HasMany<Budget, $this> */
    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    /** @return HasMany<NetWorthEntry, $this> */
    public function netWorthEntries(): HasMany
    {
        return $this->hasMany(NetWorthEntry::class);
    }

    /** @return HasMany<NetWorthLineItem, $this> */
    public function netWorthLineItems(): HasMany
    {
        return $this->hasMany(NetWorthLineItem::class);
    }

    /** @return HasMany<BankStatementImport, $this> */
    public function bankStatementImports(): HasMany
    {
        return $this->hasMany(BankStatementImport::class);
    }

    /** @return HasMany<BankProfile, $this> */
    public function bankProfiles(): HasMany
    {
        return $this->hasMany(BankProfile::class);
    }

    /**
     * The user's globally selected period, falling back to the current month
     * when one has never been chosen.
     */
    public function selectedPeriod(): SelectedPeriod
    {
        if ($this->selected_month === null || $this->selected_year === null) {
            return SelectedPeriod::current();
        }

        return SelectedPeriod::clamp($this->selected_month, $this->selected_year);
    }

    /** Persist the user's globally selected period. */
    public function setSelectedPeriod(int $month, int $year): void
    {
        $selectedPeriod = SelectedPeriod::clamp($month, $year);
        $this->selected_month = $selectedPeriod->month;
        $this->selected_year = $selectedPeriod->year;
        $this->save();
    }
}
