<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_pre_verified_user(): void
    {
        $exitCode = Artisan::call('app:create-user', [
            '--name' => 'Trusted User',
            '--email' => 'trusted@example.com',
            '--password' => 'secret-password',
        ]);

        $this->assertSame(0, $exitCode);

        $user = User::where('email', 'trusted@example.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('Trusted User', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('secret-password', $user->password));
    }

    public function test_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $exitCode = Artisan::call('app:create-user', [
            '--name' => 'Another User',
            '--email' => 'existing@example.com',
            '--password' => 'secret-password',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame(1, User::where('email', 'existing@example.com')->count());
    }

    public function test_rejects_an_invalid_email(): void
    {
        $exitCode = Artisan::call('app:create-user', [
            '--name' => 'Bad Email',
            '--email' => 'not-an-email',
            '--password' => 'secret-password',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame(0, User::count());
    }

    public function test_rejects_a_short_password(): void
    {
        $exitCode = Artisan::call('app:create-user', [
            '--name' => 'Short Pass',
            '--email' => 'shortpass@example.com',
            '--password' => 'short',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame(0, User::count());
    }
}
