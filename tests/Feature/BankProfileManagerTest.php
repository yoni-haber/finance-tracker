<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Statements\BankProfileManager;
use App\Models\BankProfile;
use App\Models\BankStatementImport;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class BankProfileManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_successfully(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(BankProfileManager::class)
            ->assertStatus(200);
    }

    public function test_displays_existing_bank_profiles(): void
    {
        $user = User::factory()->create();

        // Create profiles for the user
        BankProfile::factory()->for($user)->create([
            'name' => 'Test Bank Profile',
            'statement_type' => 'bank',
        ]);

        BankProfile::factory()->for($user)->create([
            'name' => 'Test Credit Card Profile',
            'statement_type' => 'credit_card',
        ]);

        // Create profile for different user (should not be visible)
        $otherUser = User::factory()->create();
        BankProfile::factory()->for($otherUser)->create([
            'name' => 'Other User Profile',
            'statement_type' => 'bank',
        ]);

        Livewire::actingAs($user)
            ->test(BankProfileManager::class)
            ->assertSee('Test Bank Profile')
            ->assertSee('Test Credit Card Profile')
            ->assertSee('Bank Statement')
            ->assertSee('Credit Card')
            ->assertDontSee('Other User Profile');
    }

    public function test_shows_create_form_when_show_create_called(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(BankProfileManager::class)
            ->call('showCreate')
            ->assertSet('showCreateForm', true)
            ->assertSet('hasSeparateColumns', false)
            ->assertSet('editingProfile', null)
            ->assertDispatched('open-bank-profile-modal')
            ->assertSee('Profile Name')
            ->assertSee('Statement Type')
            ->assertSee('Choose how positive and negative amounts should be interpreted.')
            ->assertSee('Column number for the transaction date.')
            ->assertDontSee('Bank: Positive = Income, Negative = Expense');
    }

    public function test_create_query_parameter_opens_create_modal_on_first_render(): void
    {
        $user = User::factory()->create();

        Livewire::withQueryParams(['create' => 1])
            ->actingAs($user)
            ->test(BankProfileManager::class)
            ->assertSet('showCreateForm', true)
            ->assertSet('hasSeparateColumns', false)
            ->assertSet('editingProfile', null)
            ->assertSet('openCreateModalOnFirstRender', true)
            ->assertSee('Create Bank Profile')
            ->assertSee('Profile Name');
    }

    public function test_creates_new_bank_profile_with_single_amount_column(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(BankProfileManager::class)
            ->call('showCreate')
            ->set('form.name', 'My Bank')
            ->set('form.statement_type', 'bank')
            ->set('form.date_column', 1)
            ->set('form.description_column', 2)
            ->set('form.amount_column', 3)
            ->set('form.date_format', 'd/m/Y')
            ->set('hasSeparateColumns', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('close-bank-profile-modal')
            ->assertSet('showCreateForm', false);

        $profile = BankProfile::where('name', 'My Bank')->where('user_id', $user->id)->first();
        $this->assertNotNull($profile);
        $this->assertEquals($user->id, $profile->user_id);
        $this->assertEquals('bank', $profile->statement_type);
        $this->assertEquals([
            'columns' => ['date' => 0, 'description' => 1, 'amount' => 2],
            'date_format' => 'd/m/Y',
            'has_header' => true,
        ], $profile->config);
    }

    public function test_creates_new_bank_profile_with_separate_debit_credit_columns(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(BankProfileManager::class)
            ->call('showCreate')
            ->set('form.name', 'My Credit Card')
            ->set('form.statement_type', 'credit_card')
            ->set('form.date_column', 1)
            ->set('form.description_column', 2)
            ->set('form.debit_column', 3)
            ->set('form.credit_column', 4)
            ->set('form.date_format', 'd/m/Y')
            ->set('hasSeparateColumns', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('close-bank-profile-modal')
            ->assertSet('showCreateForm', false);

        $profile = BankProfile::where('name', 'My Credit Card')->where('user_id', $user->id)->first();
        $this->assertNotNull($profile);
        $this->assertEquals($user->id, $profile->user_id);
        $this->assertEquals('credit_card', $profile->statement_type);
        $this->assertEquals([
            'columns' => ['date' => 0, 'description' => 1, 'debit' => 2, 'credit' => 3],
            'date_format' => 'd/m/Y',
            'has_header' => true,
        ], $profile->config);
    }

    public function test_validates_required_fields(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(BankProfileManager::class)
            ->call('showCreate')
            ->set('form.name', 'x') // too short
            ->set('form.statement_type', 'incorrect') // invalid type
            ->set('form.date_column', 'incorrect') // not an int
            ->set('form.description_column', 'incorrect') // not an int
            ->set('form.date_format', 'm/Y/d') // invalid format
            ->call('save')
            ->assertHasErrors(['form.name', 'form.date_column', 'form.date_column', 'form.description_column', 'form.statement_type', 'form.date_format'])
            ->assertSet('showCreateForm', true);
    }

    public function test_validates_column_uniqueness(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(BankProfileManager::class)
            ->call('showCreate')
            ->set('form.name', 'Test Bank')
            ->set('form.statement_type', 'bank')
            ->set('form.date_column', 1)
            ->set('form.description_column', 1) // Same as date column
            ->set('form.amount_column', 2)
            ->set('form.date_format', 'd/m/Y')
            ->set('hasSeparateColumns', false)
            ->call('save')
            ->assertHasErrors('form.date_column')
            ->assertSet('showCreateForm', true);
    }

    public function test_validates_amount_column_configuration(): void
    {
        $user = User::factory()->create();

        // missing amount column when not using separate columns
        Livewire::actingAs($user)
            ->test(BankProfileManager::class)
            ->call('showCreate')
            ->set('form.name', 'Test Bank')
            ->set('form.statement_type', 'bank')
            ->set('form.date_column', 1)
            ->set('form.description_column', 2)
            ->set('form.amount_column')
            ->set('form.date_format', 'd/m/Y')
            ->set('hasSeparateColumns', false)
            ->call('save')
            ->assertHasErrors('form.amount_column')
            ->assertSet('showCreateForm', true);

        // missing debit/credit columns when using separate columns
        Livewire::actingAs($user)
            ->test(BankProfileManager::class)
            ->call('showCreate')
            ->set('form.name', 'Test Bank')
            ->set('form.statement_type', 'bank')
            ->set('form.date_column', 1)
            ->set('form.description_column', 2)
            ->set('form.date_format', 'd/m/Y')
            ->set('hasSeparateColumns', true)
            ->call('save')
            ->assertHasErrors(['form.debit_column', 'form.credit_column'])
            ->assertSet('showCreateForm', true);
    }

    public function test_edits_existing_bank_profile(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create([
            'name' => 'Original Name',
            'statement_type' => 'bank',
            'config' => [
                'columns' => ['date' => 0, 'description' => 1, 'amount' => 2],
                'date_format' => 'd/m/Y',
            ],
        ]);

        Livewire::actingAs($user)
            ->test(BankProfileManager::class)
            ->call('edit', $profile->id)
            ->assertSet('editingProfile.id', $profile->id)
            ->assertSet('showCreateForm', true)
            ->assertDispatched('open-bank-profile-modal')
            ->assertSet('form.name', 'Original Name')
            ->assertSet('form.statement_type', 'bank')
            ->assertSet('form.date_column', 1)
            ->assertSet('form.description_column', 2)
            ->assertSet('form.amount_column', 3)
            ->assertSet('hasSeparateColumns', false);

        // Update the profile
        Livewire::actingAs($user)
            ->test(BankProfileManager::class)
            ->call('edit', $profile->id)
            ->set('form.name', 'Updated Name')
            ->set('form.statement_type', 'credit_card')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('close-bank-profile-modal')
            ->assertSet('showCreateForm', false);

        $profile->refresh();
        $this->assertEquals('Updated Name', $profile->name);
        $this->assertEquals('credit_card', $profile->statement_type);
    }

    public function test_deletes_unused_bank_profile(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create(['name' => 'Delete Me']);

        Livewire::actingAs($user)
            ->test(BankProfileManager::class)
            ->call('delete', $profile->id);

        $this->assertDatabaseMissing('bank_profiles', ['id' => $profile->id]);
    }

    public function test_cannot_delete_bank_profile_in_use(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create(['name' => 'In Use']);

        // Create import using this profile
        BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();

        Livewire::actingAs($user)
            ->test(BankProfileManager::class)
            ->call('delete', $profile->id);

        // Profile should still exist
        $this->assertDatabaseHas('bank_profiles', ['id' => $profile->id]);
    }

    public function test_cannot_edit_other_users_bank_profile(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $profile = BankProfile::factory()->for($otherUser)->create(['name' => 'Other User Profile']);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($user)
            ->test(BankProfileManager::class)
            ->call('edit', $profile->id);
    }

    public function test_cannot_delete_other_users_bank_profile(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $profile = BankProfile::factory()->for($otherUser)->create(['name' => 'Other User Profile']);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($user)
            ->test(BankProfileManager::class)
            ->call('delete', $profile->id);
    }

    public function test_cancels_form_editing(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(BankProfileManager::class)
            ->call('edit', $profile->id)
            ->assertSet('showCreateForm', true)
            ->assertDispatched('open-bank-profile-modal')
            ->call('cancel')
            ->assertDispatched('close-bank-profile-modal')
            ->assertSet('showCreateForm', false)
            ->assertSet('editingProfile', null)
            ->assertSet('hasSeparateColumns', false);
    }

    public function test_creates_profile_with_has_header_false(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(BankProfileManager::class)
            ->call('showCreate')
            ->set('form.name', 'No Header Bank')
            ->set('form.statement_type', 'bank')
            ->set('form.date_column', 1)
            ->set('form.description_column', 2)
            ->set('form.amount_column', 3)
            ->set('form.date_format', 'd/m/Y')
            ->set('form.has_header', false)
            ->set('hasSeparateColumns', false)
            ->call('save')
            ->assertHasNoErrors();

        $profile = BankProfile::where('name', 'No Header Bank')->where('user_id', $user->id)->first();
        $this->assertNotNull($profile);
        $this->assertFalse($profile->config['has_header']);
    }

    public function test_has_header_defaults_to_true(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(BankProfileManager::class)
            ->assertSet('form.has_header', true);
    }

    public function test_edits_profile_restores_has_header_value(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create([
            'config' => [
                'columns' => ['date' => 0, 'description' => 1, 'amount' => 2],
                'date_format' => 'd/m/Y',
                'has_header' => false,
            ],
        ]);

        Livewire::actingAs($user)
            ->test(BankProfileManager::class)
            ->call('edit', $profile->id)
            ->assertSet('form.has_header', false);
    }
}
