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

All amounts are stored as `decimal:2` strings. Use `Money::add()` / `Money::subtract()` for arithmetic. For manual totals, convert to pennies with `Money::normalize()` and back with `Money::fromPennies()`. Never use raw float arithmetic.

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

## User Scoping

Every query touching user data **must** be scoped to the authenticated user:

```php
Transaction::forUser(Auth::id())->findOrFail($id);   // correct
Transaction::find($id);                              // never — unscoped
```

Always set `$data['user_id'] = Auth::id()` before creating records.

## Recurring Transactions

`Transaction::projectOccurrencesForMonth()` expands recurring rules (weekly / monthly / yearly) into in-memory clones via `replicateForDate()`. Clones carry a `projected` attribute. Skipped dates are stored as `TransactionException` rows.

Always access projected data through `TransactionReport::projectedForMonth()` - never query occurrences directly.

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
