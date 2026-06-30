<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class TrustedProxiesTest extends TestCase
{
    public function test_forwarded_https_scheme_is_trusted_behind_a_proxy(): void
    {
        Route::get('/_proxy-test', fn (): array => ['secure' => request()->isSecure()]);

        $this->get('/_proxy-test', ['X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->assertJson(['secure' => true]);
    }
}
