<?php

declare(strict_types=1);

use App\Models\Note;
use App\Models\Transaction;
use App\Models\User;
use Pest\Browser\Api\PendingAwaitablePage;

function loginForBrowserTest(User $user): PendingAwaitablePage
{
    $pendingAwaitablePage = visit('/login');
    $pendingAwaitablePage->type('input[type="email"]', $user->email)
        ->type('input[type="password"]', 'password')
        ->click('@login-button')
        ->assertPathIs('/dashboard');

    return $pendingAwaitablePage;
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

it('saves and clears one dashboard notepad without refreshing charts', function (): void {
    $user = User::factory()->withoutTwoFactor()->create();
    $pendingAwaitablePage = loginForBrowserTest($user);
    $pendingAwaitablePage->script('window.noteChartRefreshes = 0; window.Livewire.on("dashboard-trend-updated", () => window.noteChartRefreshes++)');
    $pendingAwaitablePage->type('#dashboard-note-body', "Compare renewal quotes\nCall the bank")
        ->assertSee('Unsaved changes')
        ->click('button:has-text("Save note")')
        ->assertSee('Note saved.')
        ->assertDontSee('Unsaved changes')
        ->assertScript('window.noteChartRefreshes', 0);

    expect($user->note()->sole()->body)->toBe("Compare renewal quotes\nCall the bank");
    $pendingAwaitablePage->type('#dashboard-note-body', 'Call the bank tomorrow')
        ->click('button:has-text("Save note")')
        ->assertSee('Note saved.')
        ->assertScript('window.noteChartRefreshes', 0);
    expect($user->note()->count())->toBe(1);
    expect($user->note()->sole()->body)->toBe('Call the bank tomorrow');

    $pendingAwaitablePage->type('#dashboard-note-body', '')
        ->click('button:has-text("Save note")')
        ->assertSee('Note cleared.')
        ->assertScript('window.noteChartRefreshes', 0)
        ->assertNoJavaScriptErrors();
    expect(Note::where('user_id', $user->id)->exists())->toBeFalse();
});

it('keeps the notepad beside payments and usable on small screens', function (int $width, bool $dark): void {
    $user = User::factory()->withoutTwoFactor()->create();
    $body = "Remember to compare renewal quotes.\nCall the bank before Friday.\n" . str_repeat('LongReminder', 150);
    $user->note()->create(['body' => $body]);

    $webpage = loginForBrowserTest($user)->resize($width, 900);
    $webpage->script($dark ? 'window.Flux.appearance = "dark"' : 'window.Flux.appearance = "light"');
    $webpage->assertScript('document.documentElement.classList.contains("dark")', $dark)
        ->type('#dashboard-note-body', $body . "\nUnsaved thought")
        ->click('button[aria-label="Previous month"]')
        ->assertSee(now()->subMonthNoOverflow()->format('F Y') . ' overview')
        ->assertValue('#dashboard-note-body', $body . "\nUnsaved thought")
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertScript('(() => { const notes = document.querySelector("section[aria-labelledby=dashboard-notes-heading]").getBoundingClientRect(); const payments = document.querySelector("section[aria-labelledby=dashboard-upcoming-heading]").getBoundingClientRect(); return window.innerWidth >= 1024 ? Math.abs(notes.top - payments.top) < 2 && notes.right < payments.left && Math.abs(notes.width - payments.width) < 2 : notes.bottom < payments.top; })()', true)
        ->assertScript('(() => { const note = document.querySelector("#dashboard-note-body"); return note.scrollWidth <= note.clientWidth; })()', true)
        ->assertScript('document.querySelector("section[aria-labelledby=dashboard-notes-heading] button").innerText.trim()', 'Save note');
    $webpage->script('document.querySelector("#dashboard-note-body").scrollTop = 0');
    $webpage->screenshotElement('div.grid:has(> section[aria-labelledby="dashboard-notes-heading"])', 'notepad-' . $width . '-' . ($dark ? 'dark' : 'light'))
        ->click('#dashboard-note-body')
        ->keys('#dashboard-note-body', 'Tab')
        ->assertScript('document.activeElement?.innerText?.trim()', 'Save note')
        ->keys('button:has-text("Save note")', 'Enter')
        ->assertSee('Note saved.')
        ->assertNoJavaScriptErrors();
})->with([[1440, false], [1440, true], [390, false], [390, true]]);

it('shows focused validation and saves a transaction from its modal', function (): void {
    $user = User::factory()->withoutTwoFactor()->create();
    $webpage = loginForBrowserTest($user)
        ->click('Transactions')
        ->click('button:has-text("New transaction")')
        ->assertVisible('dialog[open]');

    $webpage->click('dialog[open] button[type="submit"]')
        ->assertVisible('dialog[open] [data-action-error]')
        ->assertScript('document.activeElement?.hasAttribute("data-action-error")', true);

    $webpage->type('#transaction-amount', '42.50')
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

    $webpage = loginForBrowserTest($user)
        ->click('Transactions')
        ->type('#transaction-search', 'Browser test')
        ->assertSee('Browser test salary')
        ->assertSee('Browser test rent');

    $webpage->select('select[aria-label="Transaction type filter"]', Transaction::TYPE_INCOME)
        ->assertSee('Browser test salary')
        ->assertDontSee('Browser test rent')
        ->assertNoJavaScriptErrors();
});

it('updates report totals and renders the chart when the range changes', function (): void {
    $user = User::factory()->withoutTwoFactor()->create();
    Transaction::factory()->for($user)->create(['type' => Transaction::TYPE_INCOME, 'amount' => 100, 'date' => now()->startOfMonth()]);
    Transaction::factory()->for($user)->create(['type' => Transaction::TYPE_INCOME, 'amount' => 400, 'date' => now()->subMonths(4)->startOfMonth()]);

    $webpage = loginForBrowserTest($user)
        ->click('Reports')
        ->assertSeeIn('section[aria-labelledby="report-summary-heading"] .grid > div:first-child', '£500.00')
        ->assertVisible('#incomeVsExpensesChart');

    $webpage->select('#report-range', '3_months')
        ->assertSeeIn('section[aria-labelledby="report-summary-heading"] .grid > div:first-child', '£100.00')
        ->assertScript('document.querySelector("#incomeVsExpensesChart").getContext("2d").getImageData(0, 0, document.querySelector("#incomeVsExpensesChart").width, document.querySelector("#incomeVsExpensesChart").height).data.some((value, index) => index % 4 === 3 && value > 0)', true)
        ->assertNoJavaScriptErrors();
});
