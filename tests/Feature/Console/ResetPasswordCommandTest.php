<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ResetPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_resets_an_existing_users_password(): void
    {
        $user = User::factory()->create([
            'email' => 'member@example.com',
            'password' => 'old-password',
        ]);

        $exitCode = Artisan::call('app:reset-password', [
            '--email' => 'member@example.com',
            '--password' => 'brand-new-password',
        ]);

        $this->assertSame(0, $exitCode);

        $user->refresh();
        $this->assertTrue(Hash::check('brand-new-password', $user->password));
        $this->assertFalse(Hash::check('old-password', $user->password));

        $this->assertStringContainsString('member@example.com', Artisan::output());
    }

    public function test_fails_for_an_unknown_email(): void
    {
        $exitCode = Artisan::call('app:reset-password', [
            '--email' => 'nobody@example.com',
            '--password' => 'brand-new-password',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('email', Artisan::output());
    }

    public function test_rejects_a_short_password(): void
    {
        $user = User::factory()->create([
            'email' => 'member@example.com',
            'password' => 'old-password',
        ]);

        $exitCode = Artisan::call('app:reset-password', [
            '--email' => 'member@example.com',
            '--password' => 'short',
        ]);

        $this->assertSame(1, $exitCode);

        $user->refresh();
        $this->assertTrue(Hash::check('old-password', $user->password), 'Password must be unchanged when validation fails.');
    }

    public function test_rejects_a_missing_password(): void
    {
        User::factory()->create(['email' => 'member@example.com']);

        $exitCode = Artisan::call('app:reset-password', [
            '--email' => 'member@example.com',
            '--password' => '',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('password', Artisan::output());
    }
}
