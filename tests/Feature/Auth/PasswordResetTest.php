<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_route_is_disabled(): void
    {
        $this->get('/forgot-password')->assertNotFound();
    }

    public function test_reset_password_route_is_disabled(): void
    {
        $this->get('/reset-password/some-token')->assertNotFound();
    }

    public function test_password_reset_route_names_are_not_defined(): void
    {
        $this->assertFalse(Route::has('password.request'));
        $this->assertFalse(Route::has('password.reset'));
    }

    public function test_login_screen_does_not_show_a_forgot_password_link(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Forgot your password?')
            ->assertDontSee('/forgot-password');
    }
}
