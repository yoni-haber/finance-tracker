<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\BankProfile;
use App\Models\BankStatementImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BankProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_bank_profile_has_required_fillable_fields(): void
    {
        $bankProfile = new BankProfile();
        $expectedFillable = ['user_id', 'name', 'statement_type', 'config'];

        $this->assertEquals($expectedFillable, $bankProfile->getFillable());
    }

    public function test_bank_profile_casts_config_to_array(): void
    {
        $config = [
            'columns' => ['date' => 0, 'description' => 1, 'amount' => 2],
            'date_format' => 'd/m/Y',
        ];

        $profile = BankProfile::factory()->create(['config' => $config]);

        $this->assertArraysAreEqual($config, $profile->config);
    }

    public function test_bank_profile_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create();

        $this->assertInstanceOf(User::class, $profile->user);
        $this->assertTrue($profile->user->is($user));
    }

    public function test_bank_profile_has_many_bank_statement_imports(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create();

        BankStatementImport::factory()
            ->count(3)
            ->for($user)
            ->for($profile, 'bankProfile')
            ->create();

        $this->assertCount(3, $profile->bankStatementImports);
        $this->assertTrue(
            $profile->bankStatementImports->every(
                fn ($import) => $import->bankProfile !== null && $import->bankProfile->is($profile),
            ),
        );
    }

    public function test_is_bank_statement_returns_true_for_bank_type(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create(['statement_type' => 'bank']);

        $this->assertTrue($profile->isBankStatement());
        $this->assertFalse($profile->isCreditCardStatement());
    }

    public function test_is_credit_card_statement_returns_true_for_credit_card_type(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->creditCard()->create();

        $this->assertTrue($profile->isCreditCardStatement());
        $this->assertFalse($profile->isBankStatement());
    }

    public function test_bank_profile_defaults_to_bank_statement_type(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create();

        $this->assertEquals('bank', $profile->statement_type);
        $this->assertTrue($profile->isBankStatement());
        $this->assertFalse($profile->isCreditCardStatement());
    }
}
