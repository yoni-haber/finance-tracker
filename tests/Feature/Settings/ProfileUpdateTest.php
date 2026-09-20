<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\BankProfile;
use App\Models\BankStatementImport;
use App\Models\User;
use App\Support\BankStatementConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

final class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->get(route('profile.edit'))->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $testable = Volt::test('settings.profile')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->call('updateProfileInformation');

        $testable->assertHasNoErrors();

        $user->refresh();

        $this->assertEquals('Test User', $user->name);
        $this->assertEquals('test@example.com', $user->email);
        $this->assertNotInstanceOf(\Illuminate\Support\Carbon::class, $user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $testable = Volt::test('settings.profile')
            ->set('name', 'Test User')
            ->set('email', $user->email)
            ->call('updateProfileInformation');

        $testable->assertHasNoErrors();

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $testable = Volt::test('settings.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser');

        $testable
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertNull($user->fresh());
        $this->assertFalse(auth()->check());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $testable = Volt::test('settings.delete-user-form')
            ->set('password', 'wrong-password')
            ->call('deleteUser');

        $testable->assertHasErrors(['password']);

        $this->assertNotNull($user->fresh());
    }

    public function test_storage_failure_leaves_account_and_import_records_intact(): void
    {
        Storage::shouldReceive('disk')->andReturnSelf();
        Storage::shouldReceive('exists')->once()->andReturn(true);
        Storage::shouldReceive('delete')->once()->andReturn(false);

        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create([
            'file_cleanup_status' => BankStatementConfig::CLEANUP_PENDING,
        ]);

        $this->actingAs($user);

        Volt::test('settings.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasErrors(['password']);

        $this->assertNotNull($user->fresh());
        $freshImport = $import->fresh();
        $this->assertNotNull($freshImport);
        $this->assertSame(BankStatementConfig::CLEANUP_FAILED, $freshImport->file_cleanup_status);
    }
}
