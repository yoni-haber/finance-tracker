# Architecture

## Directory Structure

| Directory | Purpose |
|---|---|
| `app/Livewire/` | Screen-level Livewire components — `Dashboard`, `TransactionManager`, `CategoryManager`, `BudgetManager`, `NetWorthTracker`, `ReportsHub`, and statement import components |
| `app/Models/` | Eloquent models with relationships, scopes, and domain logic |
| `app/Support/` | Domain helpers — `TransactionReport` (month projection), `Money` (decimal arithmetic), bank statement processing classes |
| `routes/web.php` | All routes map directly to Livewire classes or Volt pages — no controllers |
| `resources/views/` | Blade + Livewire templates; Volt single-file components for settings pages |
| `database/migrations/` | Schema definitions using the Laravel schema builder |
| `database/factories/` | Model factories for test data |
| `docker/8.5/` | Custom PHP 8.5 Dockerfile, Supervisor config, and entrypoint script |
| `tests/` | PHPUnit Feature and Unit tests |

## Data Model

All user-owned data is scoped via `user_id`. The schema is strictly hierarchical.

```
User
 ├── Category (type: income|expense, self-referencing parent/child)
 │    ├── Transaction
 │    └── Budget
 ├── Transaction (one-off or recurring)
 │    └── TransactionException (skipped dates for recurring transactions)
 ├── Budget (monthly spending limit per expense category)
 ├── NetWorthEntry
 │    └── NetWorthLineItem (individual asset/liability)
 ├── BankProfile (CSV column mapping for a bank/provider)
 │    └── BankStatementImport
 │         └── ImportedTransaction (staged rows before commit)
 └── BankStatementImport (also directly on User)
```

### Key Relationships

| Model | Relationship | Target | Notes                                                                                                                        |
|---|---|---|------------------------------------------------------------------------------------------------------------------------------|
| **Category** | `parent()` / `children()` | `Category` | One level of nesting. Parent has `parent_id = null`; subcategory points to a parent of the same user and type.               |
| **Transaction** | `category()` | `Category` | Category type must match transaction type (`income` or `expense`).                                                           |
| **Transaction** | `occurrenceExceptions()` | `TransactionException` | Dates to skip when projecting recurring occurrences.                                                                         |
| **Budget** | `category()` | `Category` | Must be an expense parent category.                                                                                          |
| **Budget** | `transactions()` | `Transaction` | Non-standard `HasMany` joining on `category_id` + `user_id` + `month` + `year` - a computed relationship for budget actuals. |
| **NetWorthEntry** | `lineItems()` | `NetWorthLineItem` | Itemised assets and liabilities that roll up into totals.                                                                    |
| **BankStatementImport** | `importedTransactions()` | `ImportedTransaction` | Staged CSV rows; committed rows become real `Transaction` records.                                                           |

### Monetary Values

All amounts are stored as `decimal:2` strings. `Money::normalize()` parses them with
`brick/math`, rounds half-up at the penny boundary, and returns an integer number
of pennies. Arithmetic is performed only on those integers; `Money::fromPennies()`
converts the result back to a database-safe decimal string and `Money::format()`
handles display formatting. Never use raw float arithmetic for financial totals.

Net-worth snapshots follow the same boundary: Livewire keeps amount inputs as
decimal strings, totals them in pennies, then persists decimal strings. History is
paginated in groups of 25 with line items eager-loaded for the visible page.

Composite indexes support the period-based budget lookup and the line-item type
lookup used by the paginated net-worth screen.

## Request Flow

1. Authenticated routes in `routes/web.php` map directly to Livewire classes (e.g. `/transactions` → `TransactionManager`). No controllers.
2. Livewire handles the request/response cycle server-side and re-renders Blade fragments as state changes.
3. `save()`, `edit()`, and `delete()` methods validate input, enforce user scoping via `Auth::id()`, and persist with Eloquent.

## Livewire Component Conventions

Every screen-level component follows this pattern:

- `#[Layout('components.layouts.app')]` and `#[Title('...')]` attributes on the class.
- Public properties hold form state; `mount()` sets defaults.
- Validation rules in `protected function rules(): array`, called via `$this->validate($this->rules())` inside `save()`.
- CRUD lives directly in component methods - no service classes.
- Modal state driven by Livewire events: `openModal()` resets the form, `save()` dispatches `close-*-modal`.

### Volt Pages (Settings)

Settings pages use [Volt](https://livewire.laravel.com/docs/volt) single-file components in `resources/views/livewire/settings/`. Key differences from class-based components: no `#[Layout]`/`#[Title]` attributes (layout applied by `Volt::route()`), inline `$this->validate([...])`, and events via `$this->dispatch()`.

## Global Selected Period

The Dashboard, Transactions, and Budgets screens all view data for a single
month. Rather than a per-page filter, the chosen month/year is a **global,
per-user selection** so it stays consistent as the user moves between pages.

- **Storage:** persisted on the `users` table via `selected_month` /
  `selected_year` (nullable). `User::selectedPeriod()` returns a
  `App\Support\SelectedPeriod` value object, falling back to the current month
  when unset; `User::setSelectedPeriod($month, $year)` persists a change. Both
  read and write **clamp** to the supported bounds (month 1–12, year
  `SelectedPeriod::MIN_YEAR`–`MAX_YEAR`) via `SelectedPeriod::clamp()`.
- **Boundaries:** previous/next navigation stops at January 2000 and December
  2100. The corresponding control is disabled at each limit, and the component
  neither persists nor broadcasts an out-of-range period.
- **Picker:** `App\Livewire\PeriodSelector` is rendered in the sidebar only on
  Dashboard, Transactions, and Budgets, the three screens that consume the
  global period. On change it persists to the user and dispatches a
  `period-changed` event carrying `{ month, year }`.
- **Consumers:** screen components `use` the
  `App\Livewire\Concerns\InteractsWithSelectedPeriod` trait, which exposes public
  `periodMonth` / `periodYear`, initialises them from the user on mount
  (`mountInteractsWithSelectedPeriod()`), and listens for `period-changed` to
  refresh in-place when the period changes without a navigation. Because
  `period-changed` payloads are client-controlled, the listener clamps them.
- **Form defaults:** the selected period also seeds create forms — a new
  transaction's date defaults to today (current month) or the 1st of the selected
  month, and a new budget's month/year default to the selected period.

Note: `SelectedPeriod` is month + year only. A component's own `mount()` runs
**before** trait mount hooks, so resolve the period via `selectedPeriod()`, which
is safe to call pre-mount (it falls back to the persisted user period when
`periodMonth`/`periodYear` aren't set yet) rather than reading the not-yet-set
properties directly.

## User Scoping

Every query touching user data **must** be scoped to the authenticated user:

```php
Transaction::forUser(Auth::id())->findOrFail($id);   // correct
Transaction::find($id);                              // never — unscoped
```

Always set `$data['user_id'] = Auth::id()` before creating records.

## Recurring Transactions

`Transaction::projectOccurrencesForRange()` expands recurring rules (weekly /
monthly / yearly) into in-memory clones via `replicateForDate()`;
`projectOccurrencesForMonth()` is its single-month convenience wrapper. Clones
carry a `projected` attribute. Skipped dates are stored as
`TransactionException` rows.

Monthly and yearly rules retain the original date as their anchor. An occurrence
is clamped to the final day of a shorter month or non-leap February, then returns
to the original day when the calendar allows it (for example, January 31 becomes
February 28/29 and then March 31; February 29 becomes February 28 in non-leap
years and February 29 in leap years). Projection jumps directly to the first
possible occurrence in the requested range instead of iterating through the
entire history.

Always access projected data through `TransactionReport`. Use
`projectedForMonth()` for a screen month and `projectedForRange()` when consuming
several months. The range API loads candidate transactions and relationships
once, then expands and groups them in memory; reports must not issue one
transaction query per month.

## Charts and Accessible Data

Chart.js is installed as an exact npm dependency and bundled through Vite; no
runtime CDN is required. `resources/js/charts.js` owns chart creation and cleanup
across Livewire navigation. Every chart has a text label and an equivalent data
table, while an explicit empty state replaces canvases with no meaningful data.

## Model Query Scopes

### Transaction

| Scope | Purpose |
|---|---|
| `forUser($userId)` | Scope to authenticated user |
| `forMonthYear($month, $year)` | Filter by calendar month |
| `forCategory($categoryId)` | Filter by category (accepts int, array, or null) |
| `income()` / `expense()` | Filter by type |

### Category

| Scope | Purpose |
|---|---|
| `forUser($userId)` | Scope to authenticated user |
| `income()` / `expense()` | Filter by type |
| `parents()` | Top-level categories (`parent_id IS NULL`) |
| `subcategories()` | Child categories (`parent_id IS NOT NULL`) |

## Category Hierarchy

Categories support one level of nesting with type enforcement:

- **Parent categories** have `parent_id = null`.
- **Subcategories** point to a parent of the same user and same type.
- A subcategory cannot have children.

Key constraints (enforced in PHP, not at the database level):
- Uniqueness validated in `CategoryManager` before persisting (MySQL treats `NULL` as distinct in unique indexes).
- Budget categories must be expense parents.
- Transaction `category_id` must match the transaction's type.
- Deleting a parent is blocked when any category in its subtree has transactions or budgets.

### Dashboard Rollup

`Dashboard::categoryTotals()` maps every transaction to its parent category before grouping, so subcategory totals appear under their parent in charts and budget comparisons.
