# Architecture

## Directory Structure

| Directory / Path | Purpose |
|---|---|
| `app/Livewire/` | Screen-level Livewire components — `Dashboard`, `TransactionManager`, `CategoryManager`, `BudgetManager`, `NetWorthTracker`, `ReportsHub`. Each class owns its state, validation rules, and rendering logic. |
| `app/Models/` | Eloquent models — `Transaction`, `Category`, `Budget`, `NetWorthEntry`, and related domain entities. They map database tables and relationships using Laravel's Active Record pattern. |
| `app/Support/` | Domain helpers — `TransactionReport` for reporting and `Money` for monetary arithmetic. These keep Livewire components lean. |
| `routes/web.php` | Authenticated routes pointing directly to Livewire classes and Volt-powered settings pages. No controllers. |
| `resources/views/` | Blade and Livewire templates (including Volt partials). Styles compiled with Tailwind + Vite. |
| `database/migrations/` | Schema definitions. Use the schema builder — no raw MySQL SQL. |
| `database/factories/` | Model factories for reproducible test data. |
| `docker/8.5/` | Custom PHP 8.5 Dockerfile, `php.ini`, `start-container` entrypoint, and `supervisord.conf` (runs the web server and queue worker together). |
| `docker/mysql/` | MySQL init script that creates the `finance_tracker_testing` database for the test suite on first container start. |
| `config/` & `bootstrap/` | Standard Laravel configuration and bootstrap files; largely unchanged to keep the focus on Laravel defaults and readability. |
| `tests/` | PHPUnit feature and unit tests that exercise critical flows and support CI automation. |

## Request Flow

1. An authenticated route in `routes/web.php` maps to a Livewire class (e.g. `/transactions` → `TransactionManager`). No controllers are involved.
2. Livewire handles the request/response cycle server-side and re-renders Blade fragments as component state changes.
3. `save()`, `edit()`, and `delete()` methods validate input, enforce user scoping with `Auth::id()`, and persist via Eloquent.
4. For read-heavy screens (dashboard, reports), `TransactionReport::projectedForMonth()` is the single entry point — it eager-loads relationships and expands recurring rules, keeping components free of query logic.
5. Components render Blade templates under `resources/views/livewire/`, wrapped in a shared layout (`components.layouts.app`) that wires Livewire assets, Tailwind styles, and Vite-built scripts.

## Livewire Component Conventions

Every screen-level component follows the same pattern:

```php
#[Layout('components.layouts.app')]
#[Title('Transactions')]
class TransactionManager extends Component
{
    // Public properties hold form state
    public string $amount = '';
    public string $description = '';

    public function mount(): void
    {
        // Set defaults, load initial data
    }

    protected function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            // ...
        ];
    }

    public function save(): void
    {
        $data = $this->validate($this->rules());
        $data['user_id'] = Auth::id();
        Transaction::create($data);
        $this->resetForm();
        $this->dispatch('close-transaction-modal');
    }

    public function openModal(): void
    {
        $this->resetForm();
        $this->dispatch('open-transaction-modal');
    }

    public function edit(int $id): void
    {
        // Load row into form state
        $this->dispatch('open-transaction-modal');
    }

    public function delete(int $id): void
    {
        Transaction::forUser(Auth::id())->findOrFail($id)->delete();
    }

    public function resetForm(): void
    {
        // Clear form properties
    }
}
```

Key rules:
- `#[Layout]` and `#[Title]` attributes on every component class.
- Validation rules live in a `protected function rules(): array` method, called inside `save()` via `$this->validate($this->rules())`.
- No service classes for CRUD — validation and persistence live directly in component methods.
- Modal open/close is driven by Livewire events — `openModal()` resets the form and dispatches `open-*-modal`; `edit()` dispatches `open-*-modal` after loading state; `save()` dispatches `close-*-modal` after persisting.

## Volt Pages (Settings)

[Volt](https://livewire.laravel.com/docs/volt) is used exclusively for the settings pages. Unlike the class-based Livewire components above, Volt pages are **single-file components** — PHP logic and Blade template live together in one `.blade.php` file.

**Location:** `resources/views/livewire/settings/`

**Routing** (in `routes/web.php`):
```php
Volt::route('settings/profile', 'settings.profile')->name('profile.edit');
```
The second argument maps to `resources/views/livewire/settings/profile.blade.php`.

**File structure:**
```php
<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\Auth;

new class extends Component {
    // Public properties = reactive state
    public string $name = '';

    public function mount(): void
    {
        $this->name = Auth::user()->name;
    }

    public function updateProfileInformation(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        Auth::user()->fill($validated)->save();

        $this->dispatch('profile-updated');
    }
}; ?>

<form wire:submit="updateProfileInformation">
    <flux:input wire:model="name" label="Name" />
    <flux:button type="submit" variant="primary">Save</flux:button>
</form>
```

Key differences from class-based components:
- No `#[Layout]` or `#[Title]` attributes — the app layout is applied by the `Volt::route()` definition.
- No separate `rules()` method — `$this->validate([...])` is called inline.
- `$this->dispatch('event-name')` fires browser/Livewire events (e.g. to show a "Saved" confirmation).

## User Scoping

Every query touching user data **must** be scoped to the authenticated user. An unscoped lookup is a security bug.

```php
// Correct
Transaction::forUser(Auth::id())->findOrFail($id);
Transaction::where('user_id', Auth::id())->find($id);

// Never — unscoped
Transaction::find($id);
```

Always set `$data['user_id'] = Auth::id()` before creating records.

## Money Handling (`app/Support/Money.php`)

Amounts are stored as `decimal:2` strings in the database — not floats.

```php
// Correct — returns a usable decimal string
$total = Money::add($a, $b);

// Also correct when manual arithmetic is needed
$total = Money::fromPennies(Money::normalize($a) + Money::normalize($b));

// Never — raw float arithmetic loses precision
$total = (float) $a + (float) $b;
```

Use `Money::add()` / `Money::subtract()` for arithmetic. When building up totals manually, convert to pennies with `Money::normalize()` and back to a decimal string with `Money::fromPennies()`.

## Recurring Transactions (`app/Models/Transaction.php`)

`Transaction::projectOccurrencesForMonth()` expands recurring rules (weekly / monthly / yearly) into in-memory clones via `replicateForDate()`. Clones carry a `projected` attribute and share the parent `id`. Skipped dates are stored as `TransactionException` rows.

**Never query occurrences directly from the DB** — always go through `TransactionReport::projectedForMonth()`.

Recurring logic stays in the `Transaction` model. Do not move it into Livewire components or service classes.

## Reporting (`app/Support/TransactionReport.php`)

`TransactionReport::projectedForMonth(userId, month, year, ?categoryId)` is the single entry point for all month-view data. It eager-loads `category` and `occurrenceExceptions` to prevent N+1 queries.

## Model Query Scopes

Use the built-in scopes on `Transaction` instead of raw `where()` chains:

| Scope | Purpose                             |
|---|-------------------------------------|
| `forUser($userId)` | Scope to the authenticated user     |
| `forMonthYear($month, $year)` | Filter by calendar month            |
| `forCategory($categoryId)` | Filter by category                  |
| `income()` | Filter to income-type transactions  |
| `expense()` | Filter to expense-type transactions |

Use the built-in scopes on `Category` instead of raw `where()` chains:

| Scope | Purpose |
|---|---|
| `forUser($userId)` | Scope to the authenticated user |
| `income()` | Income categories only |
| `expense()` | Expense categories only |
| `parents()` | Top-level categories (`parent_id IS NULL`) |
| `subcategories()` | Child categories (`parent_id IS NOT NULL`) |

## Category Hierarchy

Categories have a `type` (`income` or `expense`) and support **one level of nesting**.

- **Parent categories** have `parent_id = null`.
- **Subcategories** have a `parent_id` pointing to a parent of the **same user and same type**.
- No third level is permitted — a subcategory cannot have children.

### Hierarchy helpers on `Category`

```php
$category->isParent();       // true when parent_id is null
$category->isSubcategory();  // true when parent_id is set
$category->hasTransactions(); // checks own + children's transactions
$category->hasBudgets();      // checks own budgets
```

### Constraints enforced in code

- **Uniqueness is PHP-enforced**, not at the database level. MySQL treats `NULL` as always-distinct in unique indexes, so `UNIQUE(user_id, parent_id, name)` would allow duplicate parent names. `CategoryManager` validates uniqueness before persisting.
- **Budget categories must be expense parents.** `BudgetManager::rules()` includes `->where('type', 'expense')->whereNull('parent_id')`.
- **Transaction `category_id` must match the transaction type.** `TransactionManager::rules()` includes `->where('type', $this->type)`.
- Changing the `type` property in `CategoryManager` or `TransactionManager` clears the dependent category field via `updatedType()`.
- Deleting a parent is blocked when any category in its subtree has transactions or budgets.

### Dashboard rollup

`Dashboard::categoryTotals()` maps every transaction's `category_id` to the parent's name before grouping, so subcategory transactions appear under their parent in charts and breakdowns. Budget actuals also collect the parent's subcategory IDs and filter against the combined set.

## Key Files

| Path | Purpose |
|---|---|
| `app/Livewire/Transactions/TransactionManager.php` | Reference implementation of the Livewire CRUD pattern |
| `app/Models/Category.php` | Category type hierarchy, scopes, and helper methods |
| `app/Support/Money.php` | All monetary arithmetic |
| `app/Support/TransactionReport.php` | Month-projection entry point |
| `app/Models/Transaction.php` | Recurring expansion logic |
| `routes/web.php` | All route → component mappings |
| `resources/views/components/layouts/app.blade.php` | Shared app shell |
