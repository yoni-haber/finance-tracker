<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PeriodSelectorVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_period_selector_is_visible_only_on_period_driven_pages(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        foreach (['dashboard', 'transactions', 'budgets'] as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertSee('aria-label="Previous month"', false);
        }

        foreach (['categories', 'net-worth', 'reports', 'statements.import'] as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertDontSee('aria-label="Previous month"', false);
        }
    }
}
