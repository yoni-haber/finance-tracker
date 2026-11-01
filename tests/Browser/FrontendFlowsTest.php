<?php

declare(strict_types=1);

use App\Models\BankStatementImport;
use App\Models\Budget;
use App\Models\Category;
use App\Models\ImportedTransaction;
use App\Models\NetWorthEntry;
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

it('reviews statement rows with clear selection and import scope', function (int $width): void {
    $user = User::factory()->withoutTwoFactor()->create();
    $category = Category::factory()->for($user)->expense()->create(['name' => 'Groceries']);
    $import = BankStatementImport::factory()->for($user)->parsed()->create(['original_filename' => 'October statement.csv']);
    $expense = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create([
        'description' => 'NEIGHBOURHOOD MARKET', 'amount' => -46.80,
    ]);
    ImportedTransaction::factory()->for($import, 'bankStatementImport')->create([
        'description' => 'CLIENT INVOICE', 'amount' => 325,
    ]);
    $possibleMatches = ImportedTransaction::factory()->for($import, 'bankStatementImport')->count(2)->create([
        'description' => 'COFFEE KIOSK', 'amount' => -8.50, 'date' => today(), 'hash' => 'possible-match',
    ]);
    ImportedTransaction::factory()->for($import, 'bankStatementImport')->duplicate()->create([
        'description' => 'LOCAL BOOKSHOP', 'amount' => -28.75,
    ]);

    $webpage = loginForBrowserTest($user)
        ->navigate('/statements/review/' . $import->id)
        ->resize($width, 1000)
        ->assertSee('Review statement')
        ->assertSee('−£46.80')
        ->assertSee('+£325.00')
        ->assertSee('Checkboxes are for bulk actions only.')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->screenshot(filename: 'statement-review-' . $width . '-light');
    $webpage->script('window.Flux.appearance = "dark"');
    $webpage->screenshot(filename: 'statement-review-' . $width . '-dark');

    $webpage->click('Select visible')
        ->assertSee('4 selected')
        ->assertDisabled('#bulk-import-category')
        ->click('Deselect visible')
        ->assertDontSee('4 selected')
        ->click('[data-review-filter="possible"]')
        ->assertSee('Showing 2 of 5 transactions')
        ->click('Select visible')
        ->assertSee('2 selected')
        ->select('#bulk-import-category', '')
        ->assertSee('2 selected')
        ->assertNoJavaScriptErrors()
        ->select('#bulk-import-category', (string) $category->id)
        ->assertSee('Category assigned to 2 transactions.')
        ->assertDontSee('2 selected')
        ->click('[data-import-trigger]')
        ->assertSee('Import 4 transactions?')
        ->assertSee('2 possible matching entries will be included.')
        ->click('Back to review')
        ->click('[data-review-filter="all"]')
        ->click('[data-import-row="' . $expense->id . '"] button[wire\\:click^="editTransaction"]')
        ->assertSee('Save or cancel your changes before continuing.')
        ->assertDisabled('[data-import-trigger]')
        ->type('#edit-description-' . $expense->id, 'Updated market')
        ->click('Save changes')
        ->assertSee('UPDATED MARKET')
        ->assertEnabled('[data-import-trigger]')
        ->assertNoJavaScriptErrors();

    expect($expense->fresh()?->description)->toBe('UPDATED MARKET');
    expect($possibleMatches->fresh()->pluck('category_id')->all())->toBe([$category->id, $category->id]);
    expect(Transaction::forUser($user->id)->count())->toBe(0);
})->with([1440, 390]);

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
        ->click('[data-header-actions="right"] button:has-text("New transaction")')
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

it('searches historical occurrences and applies the type filter', function (int $width): void {
    $user = User::factory()->withoutTwoFactor()->create();
    Transaction::factory()->for($user)->create(['description' => 'Browser test salary', 'type' => Transaction::TYPE_INCOME, 'date' => now()]);
    Transaction::factory()->for($user)->recurring('weekly')->create(['description' => 'Browser test rent', 'type' => Transaction::TYPE_EXPENSE, 'date' => today()->subWeeks(2)]);
    Transaction::factory()->for($user)->create(['description' => 'Browser test future', 'date' => today()->addDay()]);

    $webpage = loginForBrowserTest($user)
        ->click('Transactions')
        ->resize($width, 900)
        ->type('#transaction-search', 'Browser test')
        ->assertSee('4 results')
        ->assertSee('Browser test salary')
        ->assertSee('Browser test rent')
        ->assertSee('Recurring occurrence')
        ->assertDontSee('Browser test future')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->screenshot(filename: 'transaction-history-' . $width);

    $webpage->select('select[aria-label="Transaction type filter"]', Transaction::TYPE_INCOME)
        ->assertSee('1 result')
        ->assertSee('Browser test salary')
        ->assertDontSee('Browser test rent')
        ->assertNoJavaScriptErrors();
})->with([390, 1440]);

it('updates report totals and separate net worth charts when the range changes', function (int $width): void {
    $user = User::factory()->withoutTwoFactor()->create();
    Transaction::factory()->for($user)->create(['type' => Transaction::TYPE_INCOME, 'amount' => 100, 'date' => now()->startOfMonth()]);
    Transaction::factory()->for($user)->create(['type' => Transaction::TYPE_INCOME, 'amount' => 400, 'date' => now()->subMonths(4)->startOfMonth()]);
    foreach (range(1, 6) as $index) {
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Spending category ' . $index]);
        Transaction::factory()->for($user)->for($category)->create(['type' => Transaction::TYPE_EXPENSE, 'amount' => 10, 'date' => today()]);
        if ($index === 1) {
            Budget::factory()->for($user)->for($category)->create(['amount' => 100]);
        }
    }

    foreach ([6 => [50000, 10000], 5 => [51000, 9500], 4 => [52000, 9000]] as $monthsAgo => [$assets, $liabilities]) {
        NetWorthEntry::factory()->for($user)->create([
            'date' => today()->startOfMonth()->subMonths($monthsAgo),
            'assets' => $assets, 'liabilities' => $liabilities, 'net_worth' => $assets - $liabilities,
        ]);
    }

    $webpage = loginForBrowserTest($user)
        ->click('Reports')
        ->resize($width, 1000)
        ->assertSeeIn('section[aria-labelledby="report-summary-heading"] .grid > div:first-child', '£500.00')
        ->assertSeeIn('section[aria-labelledby="report-summary-heading"] .grid > div:nth-child(2)', '12% of income')
        ->assertVisible('#incomeVsExpensesChart')
        ->assertVisible('#assetsChart')->assertVisible('#liabilitiesChart')->assertVisible('#netWorthChart');

    $webpage->select('#report-range', '3_months')
        ->assertSeeIn('section[aria-labelledby="report-summary-heading"] .grid > div:first-child', '£100.00')
        ->assertSeeIn('section[aria-labelledby="report-summary-heading"] .grid > div:nth-child(2)', '60% of income')
        ->assertScript('document.querySelector("#incomeVsExpensesChart").getContext("2d").getImageData(0, 0, document.querySelector("#incomeVsExpensesChart").width, document.querySelector("#incomeVsExpensesChart").height).data.some((value, index) => index % 4 === 3 && value > 0)', true)
        ->assertSee('No snapshots in this range.')
        ->assertMissing('#assetsChart')->assertMissing('#liabilitiesChart')->assertMissing('#netWorthChart');

    $webpage->select('#report-range', '12_months')
        ->assertSeeIn('section[aria-labelledby="report-summary-heading"] .grid > div:nth-child(2)', '12% of income')
        ->assertVisible('#assetsChart')->assertVisible('#liabilitiesChart')->assertVisible('#netWorthChart')
        ->assertScript('Array.from(document.querySelectorAll("section[aria-labelledby=net-worth-heading] canvas")).filter(canvas => canvas.getContext("2d").getImageData(0, 0, canvas.width, canvas.height).data.some((value, index) => index % 4 === 3 && value > 0)).length', 3)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true);
    $webpage->script('const chart = document.querySelector("#budgetPerformanceChart").getBoundingClientRect(); window.budgetChartSize = { width: chart.width, height: chart.height }');
    $webpage->click('#budget-data summary')
        ->assertVisible('#budget-data table')
        ->assertScript('(() => { const chart = document.querySelector("#budgetPerformanceChart").getBoundingClientRect(); return Math.abs(chart.width - window.budgetChartSize.width) < 1 && Math.abs(chart.height - window.budgetChartSize.height) < 1; })()', true)
        ->click('#budget-data summary');
    $webpage->script('window.Flux.appearance = "light"');
    $webpage->screenshotElement('section[aria-labelledby="net-worth-heading"]', 'net-worth-' . $width . '-light');
    $webpage->script('window.Flux.appearance = "dark"');
    $webpage->screenshotElement('section[aria-labelledby="net-worth-heading"]', 'net-worth-' . $width . '-dark')
        ->assertNoJavaScriptErrors();
})->with([1440, 390]);
