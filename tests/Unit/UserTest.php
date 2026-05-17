<?php

namespace Tests\Unit;

use App\Models\BankProfile;
use App\Models\Budget;
use App\Models\Category;
use App\Models\NetWorthEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BankStatementConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_initials_returns_first_letters_of_first_two_names(): void
    {
        $user = User::factory()->create(['name' => 'Jane Ann Doe']);

        $this->assertSame('JA', $user->initials());
    }

    public function test_user_has_many_transactions(): void
    {
        $user = User::factory()->create();
        Transaction::factory()->count(2)->for($user)->create();

        $this->assertCount(2, $user->transactions);
        $this->assertTrue($user->transactions->every(fn ($transaction) => $transaction->user->is($user)));
    }

    public function test_user_has_many_categories(): void
    {
        $user = User::factory()->create();
        Category::factory()->count(3)->for($user)->create();

        $this->assertCount(3, $user->categories);
        $this->assertTrue($user->categories->every(fn ($category) => $category->user->is($user)));
    }

    public function test_user_has_many_budgets(): void
    {
        $user = User::factory()->create();
        Budget::factory()->count(2)->for($user)->create();

        $this->assertCount(2, $user->budgets);
        $this->assertTrue($user->budgets->every(fn ($budget) => $budget->user->is($user)));
    }

    public function test_user_has_many_net_worth_entries(): void
    {
        $user = User::factory()->create();
        $user->netWorthEntries()->createMany([
            ['date' => '2024-01-01', 'assets' => 1000, 'liabilities' => 200, 'net_worth' => 800],
            ['date' => '2024-02-01', 'assets' => 1500, 'liabilities' => 300, 'net_worth' => 1200],
        ]);

        $this->assertCount(2, $user->netWorthEntries);
        $this->assertTrue($user->netWorthEntries->every(fn ($entry) => $entry->user->is($user)));
    }

    public function test_user_has_many_net_worth_line_items(): void
    {
        $user = User::factory()->create();
        /** @var NetWorthEntry $entry */
        $entry = $user->netWorthEntries()->create(['date' => '2024-01-01', 'assets' => 1000, 'liabilities' => 200, 'net_worth' => 800]);

        $user->netWorthLineItems()->createMany([
            ['net_worth_entry_id' => $entry->id, 'type' => 'asset', 'category' => 'Cash', 'amount' => 800],
            ['net_worth_entry_id' => $entry->id, 'type' => 'liability', 'category' => 'Credit Card', 'amount' => 200],
        ]);

        $this->assertCount(2, $user->netWorthLineItems);
        $this->assertTrue($user->netWorthLineItems->every(fn ($lineItem) => $lineItem->user->is($user)));
    }

    public function test_user_has_many_bank_statement_imports(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create();

        $user->bankStatementImports()->createMany([
            [
                'original_filename' => 'statement_jan.csv',
                'status' => BankStatementConfig::STATUS_UPLOADED,
                'bank_profile_id' => $profile->id,
                'statement_type' => 'bank',
            ],
            [
                'original_filename' => 'statement_feb.csv',
                'status' => BankStatementConfig::STATUS_UPLOADED,
                'bank_profile_id' => $profile->id,
                'statement_type' => 'bank',
            ],
        ]);

        $this->assertCount(2, $user->bankStatementImports);
        $this->assertTrue($user->bankStatementImports->every(fn ($import) => $import->user->is($user)));
    }

    public function test_user_has_many_bank_profiles(): void
    {
        $user = User::factory()->create();
        $user->bankProfiles()->createMany([
            [
                'name' => 'Bank A Profile',
                'statement_type' => 'bank',
                'config' => ['columns' => ['date' => 0, 'description' => 1, 'amount' => 2], 'date_format' => 'd/m/Y'],
            ],
            [
                'name' => 'Bank B Profile',
                'statement_type' => 'credit_card',
                'config' => ['columns' => ['date' => 0, 'description' => 1, 'amount' => 2], 'date_format' => 'd/m/Y'],
            ],
        ]);

        $this->assertCount(2, $user->bankProfiles);
        $this->assertTrue($user->bankProfiles->every(fn ($profile) => $profile->user->is($user)));
    }
}
