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
 ├── Budget (monthly spending limit or investment goal per expense category)
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

### Action feedback and dialog layout

`resources/js/ui-feedback.js` brings completed-action errors and success messages
into view and focuses them for keyboard and screen-reader users. Page banners
use `data-action-feedback` with `role="status"`; errors use `data-action-error`
with `role="alert"`. Flux input errors are also recognised. An open dialog keeps
focus inside it and scrolls to its first error. After closing or redirecting,
the page message is revealed instead. Field updates and background polling do
not interrupt the user's focus or scroll position.
Opening a different edit form clears validation left over from the previous form.

Standard Flux dialogs retain their initial layout height until closed. Dynamic
fields, validation and added rows scroll inside that height; reopening measures
the content afresh. Width follows the viewport and the dialog's existing maximum
width, while height is capped to the viewport. Stable scrollbar gutters prevent
the page and dialog content from shifting horizontally. These shared behaviours
cover finance, statement and settings dialogs in both themes.

Run `npm run test:ui` inside the app container for the targeted browser-interface
tests. CI runs them with 100% line, branch and function coverage required for the
shared UI module.

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
monthly / quarterly / yearly) into in-memory clones via `replicateForDate()`;
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

Key constraints:
- Category names are unique for each user, type, and parent scope. A generated
  `parent_lookup_id` converts a top-level category's `NULL` parent to `0`, so the
  database can enforce top-level uniqueness as well as subcategory uniqueness.
- Parent links are constrained to the same user and type. Model validation also
  rejects self-parenting, cycles, and a third hierarchy level.
- A category may always be renamed, but its type or parent cannot change after it
  has subcategories, transactions, or budgets.
- Budget categories must be expense parents.
- Transaction `category_id` must match the transaction's type.
- Deleting a parent is blocked when any category in its subtree has transactions or budgets.

The category-integrity migration assumes existing category names, hierarchy,
transaction links, and budget links already satisfy these rules. It does not
rewrite existing records; incompatible data must be corrected before migration.

### Dashboard Rollup

`Dashboard::categoryTotals()` maps every transaction to its parent category before grouping, so subcategory totals appear under their parent in charts and budget comparisons.

`MonthlyFlow::totals()` calculates savings as income minus spending and investing. Saving-category transactions remain in activity history and do not reduce that remainder. Dashboard and cash flow reports share this calculation; current-month dashboard savings includes projected recurring occurrences. Budget progress counts transactions through today, treats Spending budgets as limits and Investment budgets as goals, and keeps the Reports budget chart limited to spending budgets.

## Upcoming Payments

`UpcomingPayments::forecast()` uses `TransactionReport::projectedForRange()` once
for the full horizon, filters spending expenses (including uncategorised entries),
and returns ordered occurrences, monthly totals and an overall total. Monetary
arithmetic stays in integer pennies. The horizon is today through the end of the
third, sixth or twelfth calendar month, counting the current month as month one.

The Upcoming Payments page has URL-backed `months`, `regular` and `minimum`
filters. It operates independently of the global selected period. Its default
forecast includes quarterly/yearly schedules and dated one-offs. Weekly/monthly
payments can be included explicitly; income, savings and investments are excluded.
The dashboard shares the six-month default forecast and previews five payments
in a card at the bottom of the page, half width on desktop and full width on
mobile. Neither view displays an overall forecast total; the planning page
retains monthly totals and individual amounts. The date range and payment count
are integrated into the filters card. Months with payments use the existing green
accent and coral spending totals; empty months are quieter. Frequency badges use
the existing blue accent. Net worth summary values share the dashboard palette:
assets and nonnegative net worth are green, liabilities and negative net worth
are coral; missing snapshots remain muted.

A future schedule starts on its first expected payment date and requires no
historical payment. Add scheduled payment opens the standard transaction editor
with `scheduled=1` and today's date, even when the global month is historical.
`edit=<id>` opens an existing user-owned record; recurring edits affect the series.
Links from Upcoming Payments include `upcoming=1`, so a successful create or edit
returns to that page and brings its accessible success message into view. Filter
errors use the shared error-feedback markers. Validation failures keep the editor open. Saving or closing
the modal clears its URL action flags; regular transaction saves remain on
Transactions. The editor focuses its heading and resets its scroll position on
opening. Recurrences have no end date by default; selecting **Set an end date**
reveals an initially empty required date field. Removing that selection clears
the stored end date. Existing finite series load with the selection enabled.
Generated occurrences remain in memory and are never persisted as additional transactions.

Quarterly schedules advance by three months from the original date and preserve
the same short-month clamping and anchor restoration as monthly schedules. Apply
the quarterly-frequency migration before saving them. Its rollback refuses while
quarterly rows exist: convert or remove these schedules before rollback.

The coverage requirement for new/changed executable application PHP is 100%.
CI runs the complete PHPUnit suite with coverage; local checks target the affected
tests. Infection's configuration requires a 100% mutation score with no escaped
mutants or timeouts. During feature work,
run only relevant PHPUnit files/methods and changed-file static checks locally;
leave full-suite and Infection runs to CI.
