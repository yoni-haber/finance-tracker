<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_route_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_register_route_name_is_not_defined(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('register'));
    }

    public function test_login_screen_does_not_show_a_signup_link(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Sign up')
            ->assertDontSee('/register');
    }
}
