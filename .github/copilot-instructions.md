# Copilot Instructions

## Commands

All commands run inside Docker via Laravel Sail. Docker Desktop must be running.

```bash
make setup       # First-time setup (build image, migrate, install deps, build assets)
make up          # Start containers
make down        # Stop containers
make test        # Run the full PHPUnit test suite
make pint        # Fix code style (Laravel Pint)
make phpstan     # Static analysis (PHPStan)
make migrate     # Run outstanding migrations
make fresh       # Wipe DB and re-run all migrations + seed
make npm-dev     # Start Vite HMR dev server (second terminal)
make npm-build   # Build frontend assets
make logs        # Tail Laravel logs via Pail
make shell       # Open a bash shell inside the app container
```

**Run a single test file or filter by name:**
```bash
./vendor/bin/sail artisan test tests/Feature/TransactionManagerTest.php
./vendor/bin/sail artisan test --filter=TransactionManagerTest
```

**Run tests with coverage:**
```bash
./vendor/bin/sail artisan test --coverage
```

Tests run against a dedicated MySQL database (`finance_tracker_testing`), not SQLite. The database is created automatically by `docker/mysql/` init scripts on first container start.

## Architecture

- **Laravel 13** with **Livewire 4** and **Fortify** authentication. No controllers — all authenticated routes in `routes/web.php` point directly to Livewire component classes.
- **Volt** is used exclusively for the four settings pages (`resources/views/livewire/settings/`). All other screens are class-based Livewire components in `app/Livewire/`.
- **Flux UI** is the component library (`<flux:button>`, `<flux:input>`, etc.) used in Blade templates.
- `app/Support/TransactionReport.php` — the single entry point for all month-view data. It eager-loads `category` and `occurrenceExceptions` relationships to prevent N+1 queries. Never bypass it to query transactions directly.
- `app/Support/Money.php` — all monetary arithmetic. Amounts are stored as `decimal:2` strings in the database.
- `app/Jobs/ParseBankStatementJob.php` — async CSV bank statement import pipeline. Dispatched on upload; the queue worker runs automatically via Supervisor inside the container.
- The queue driver is `database`. In tests it is set to `sync` via `phpunit.xml`.

## Key Conventions

### User scoping
Every query that touches user data must be scoped. An unscoped lookup is a security bug.
```php
// Correct
Transaction::forUser(Auth::id())->findOrFail($id);

// Never — unscoped
Transaction::find($id);
```
Always set `$data['user_id'] = Auth::id()` before creating records.

### Money arithmetic
Never use raw float arithmetic. Amounts are stored as `decimal:2`.
```php
// Correct
$total = Money::add($a, $b);
$total = Money::fromPennies(Money::normalize($a) + Money::normalize($b));

// Never
$total = (float) $a + (float) $b;
```
`Money::format()` renders amounts as `£1,234.56`.

### Recurring transactions
Never query recurring occurrences directly from the database. Always use `TransactionReport::projectedForMonth()`, which calls `Transaction::projectOccurrencesForMonth()` to expand rules into in-memory clones. Recurring logic lives in the `Transaction` model — do not move it into components.

Skipped occurrences are stored as `TransactionException` rows. Projected clones carry a `projected` attribute and share the parent `id`.

### Transaction model scopes
Prefer these over raw `where()` chains:

| Scope | Purpose |
|---|---|
| `forUser($userId)` | Scope to the authenticated user |
| `forMonthYear($month, $year)` | Filter by calendar month |
| `forCategory($categoryId)` | Filter by category (nullable-safe) |
| `income()` | Income-type transactions |
| `expense()` | Expense-type transactions |

### Category hierarchy

Categories are typed (`income` or `expense`) and support a single level of nesting: a **parent** has `parent_id = null`; a **subcategory** points to a parent of the same user and same type. No third level is allowed.

Use the Category model scopes instead of raw `where()` chains:

| Scope | Purpose |
|---|---|
| `forUser($userId)` | Scope to the authenticated user |
| `income()` | Income categories only |
| `expense()` | Expense categories only |
| `parents()` | Top-level categories (`parent_id IS NULL`) |
| `subcategories()` | Child categories (`parent_id IS NOT NULL`) |

Use the factory states in tests:

```php
Category::factory()->income()->create();               // parent income
Category::factory()->expense()->create();              // parent expense
Category::factory()->subcategoryOf($parent)->create(); // child (inherits user + type)
```

Key constraints:
- **Uniqueness is enforced in PHP**, not at the DB level (MySQL treats `NULL` as always-distinct in unique indexes). The `CategoryManager` validates names before persisting.
- **Budgets are expense-only parents.** `BudgetManager` validates `->where('type', 'expense')->whereNull('parent_id')` — never create a budget for an income category or a subcategory.
- **Transaction `category_id` must match transaction type.** `TransactionManager` validates `->where('type', $this->type)` on the category.
- `updatedType()` in `CategoryManager` and `TransactionManager` clears the dependent category selection whenever the type property changes via `wire:model.live`.
- Deleting a parent category is blocked when the parent or any of its children have transactions or budgets.

### Livewire component pattern
Every screen-level component (`app/Livewire/`) follows this structure:
- `#[Layout('components.layouts.app')]` and `#[Title('...')]` attributes on the class.
- Public properties hold form state.
- `protected function rules(): array` defines validation rules, called inside `save()` via `$this->validate($this->rules())`.
- Standard methods: `mount()`, `render()`, `save()`, `edit(int $id)`, `delete(int $id)`, `openModal()`, `resetForm()`.
- No service classes for CRUD — validation and persistence live directly in component methods.
- Modals are driven by Livewire events: `openModal()` resets the form and dispatches `open-*-modal`; `save()` dispatches `close-*-modal` after persisting.
- `updatedX()` lifecycle hooks react to property changes (e.g. `updatedIsRecurring()`).

### Volt pages (settings only)
Settings pages are single-file components in `resources/views/livewire/settings/`, routed via `Volt::route()`. They do not use `#[Layout]` or `#[Title]` attributes, and call `$this->validate([...])` inline rather than using a `rules()` method.

### Migrations
Use the schema builder — no raw SQL. Run `make migrate` after adding a migration.

## Working Preferences

- Always ask clarifying questions if required.
- Always consult the user before making non-trivial decisions or assumptions. If you identify a better way to implement something, flag it before proceeding.
- Always update documentation when making code changes that affect it.

### Patterns
Follow the existing Livewire CRUD pattern by default. If a meaningfully better approach exists for a specific case, flag it before implementing rather than deviating silently.

### Tests
Always write PHPUnit tests for new code — feature tests for Livewire components, unit tests for support classes and models. A task is not done until tests are written and passing.

### PHPDoc
Add PHPDoc blocks to public methods only. Skip them on private/protected methods unless the logic is non-obvious.

### Frontend
Use Blade and Flux UI components exclusively. Do not introduce Alpine.js or vanilla JavaScript.
