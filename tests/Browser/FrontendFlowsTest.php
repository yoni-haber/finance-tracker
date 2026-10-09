<?php

declare(strict_types=1);

use App\Models\Transaction;
use App\Models\User;
use Pest\Browser\Api\PendingAwaitablePage;

function loginForBrowserTest(User $user): PendingAwaitablePage
{
    $page = visit('/login');
    $page->type('input[type="email"]', $user->email)
        ->type('input[type="password"]', 'password')
        ->click('@login-button')
        ->assertPathIs('/dashboard');

    return $page;
}

it('logs in and follows Livewire navigation between the main pages', function (): void {
    $user = User::factory()->withoutTwoFactor()->create();

    loginForBrowserTest($user)
        ->click('Transactions')
        ->assertPathIs('/transactions')
        ->assertSee('Search transactions')
        ->click('Reports')
        ->assertPathIs('/reports')
        ->assertSee('Cash flow over time')
        ->assertNoJavaScriptErrors();
});

it('shows focused validation and saves a transaction from its modal', function (): void {
    $user = User::factory()->withoutTwoFactor()->create();
    $page = loginForBrowserTest($user)
        ->click('Transactions')
        ->click('button:has-text("New transaction")')
        ->assertVisible('dialog[open]');

    $page->click('dialog[open] button[type="submit"]')
        ->assertVisible('dialog[open] [data-action-error]')
        ->assertScript('document.activeElement?.hasAttribute("data-action-error")', true);

    $page->type('#transaction-amount', '42.50')
        ->type('#transaction-description', 'Browser test groceries')
        ->click('dialog[open] button[type="submit"]')
        ->assertMissing('dialog[open]')
        ->assertSeeIn('[data-action-feedback]', 'Transaction saved successfully.')
        ->assertNoJavaScriptErrors();

    expect(Transaction::query()->where('user_id', $user->id)->where('description', 'Browser test groceries')->where('amount', 42.50)->exists())->toBeTrue();
});

it('searches recorded transactions and applies the type filter', function (): void {
    $user = User::factory()->withoutTwoFactor()->create();
    Transaction::factory()->for($user)->create(['description' => 'Browser test salary', 'type' => Transaction::TYPE_INCOME, 'date' => now()]);
    Transaction::factory()->for($user)->create(['description' => 'Browser test rent', 'type' => Transaction::TYPE_EXPENSE, 'date' => now()]);

    $page = loginForBrowserTest($user)
        ->click('Transactions')
        ->type('#transaction-search', 'Browser test')
        ->assertSee('Browser test salary')
        ->assertSee('Browser test rent');

    $page->select('select[aria-label="Transaction type filter"]', Transaction::TYPE_INCOME)
        ->assertSee('Browser test salary')
        ->assertDontSee('Browser test rent')
        ->assertNoJavaScriptErrors();
});

it('updates report totals and renders the chart when the range changes', function (): void {
    $user = User::factory()->withoutTwoFactor()->create();
    Transaction::factory()->for($user)->create(['type' => Transaction::TYPE_INCOME, 'amount' => 100, 'date' => now()->startOfMonth()]);
    Transaction::factory()->for($user)->create(['type' => Transaction::TYPE_INCOME, 'amount' => 400, 'date' => now()->subMonths(4)->startOfMonth()]);

    $page = loginForBrowserTest($user)
        ->click('Reports')
        ->assertSeeIn('section[aria-labelledby="report-summary-heading"] .grid > div:first-child', '£500.00')
        ->assertVisible('#incomeVsExpensesChart');

    $page->select('#report-range', '3_months')
        ->assertSeeIn('section[aria-labelledby="report-summary-heading"] .grid > div:first-child', '£100.00')
        ->assertScript('document.querySelector("#incomeVsExpensesChart").getContext("2d").getImageData(0, 0, document.querySelector("#incomeVsExpensesChart").width, document.querySelector("#incomeVsExpensesChart").height).data.some((value, index) => index % 4 === 3 && value > 0)', true)
        ->assertNoJavaScriptErrors();
});
