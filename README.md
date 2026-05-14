# Laravel Finance Tracker

## Project Overview
Laravel Finance Tracker is a personal budgeting and finance dashboard built with the Laravel 13 Livewire starter kit. It lets authenticated users record transactions, group them into categories, set monthly budgets, track net worth entries, review reports that summarise income vs. expenses over time, and import transactions from bank statement CSV files. All data is scoped per user.

- **Framework:** Laravel 13 with Fortify authentication, Livewire 4, Flux UI component library, and Volt for function-based settings pages.
- **Language:** PHP 8.4+.
- **Frontend:** Blade-based Livewire views enhanced by Volt components; assets compiled with Vite, Tailwind CSS, and the Laravel Vite plugin.
- **Database:** MySQL 9.7.0 running in a Docker container, with a persistent named volume so data survives container restarts.
- **Tooling:** Composer for PHP dependencies, npm for frontend tooling, and Docker / Laravel Sail for a consistent local development environment.

## Key Design Principles
- **Framework conventions first:** Routes point directly to Livewire classes and Volt pages, leaning on Laravel defaults instead of custom routing or service layers for clarity.
- **Separation of concerns:** Livewire components own screen-level interactions, Eloquent models handle data, and support classes (e.g., `App\Support\TransactionReport`) encapsulate reporting logic. Views stay thin and presentation-focused.
- **Simplicity over premature optimisation:** MySQL runs automatically in Docker alongside the app, migrations seed the necessary schema, and scripts automate common tasks to allow development rather than environment wrangling.
- **Learning-first architecture:** The code follows Laravel’s directory conventions and uses explicit method naming (`mount`, `render`, `save`, `edit`, `delete`) to make the request/component lifecycle easy to follow.

## Directory Structure

| Directory | Purpose |
|---|---|
| `app/Livewire/` | Screen-level components (`Dashboard`, `TransactionManager`, etc.) |
| `app/Models/` | Eloquent models — `Transaction`, `Category`, `Budget`, `NetWorthEntry`, `BankStatementImport`, and more |
| `app/Support/` | Domain helpers — `TransactionReport` (reporting) and `Money` (arithmetic) |
| `routes/web.php` | All routes map directly to Livewire classes — no controllers |
| `resources/views/` | Blade + Livewire templates, compiled with Tailwind + Vite |
| `database/` | Migrations and model factories |
| `docker/` | PHP 8.4 Docker image, Supervisor config, and MySQL init scripts |
| `tests/` | PHPUnit feature and unit tests |

## How the App Works
1. **Request flow:** Authenticated routes defined in `routes/web.php` map to Livewire classes (e.g., `/transactions` → `TransactionManager`). Livewire handles request/response cycles server-side and re-renders Blade fragments as state changes.  
2. **Stateful components:** Each Livewire class exposes public properties for form state (amount, date, category, recurrence) and lifecycle hooks (`mount`, `updatedIsRecurring`) to set defaults and react to user interactions.
3. **Validation and persistence:** Actions like `save`, `edit`, and `delete` validate input, enforce user scoping with `Auth::id()`, and use Eloquent to insert or update rows. Recurring transactions support occurrence exceptions to omit specific dates.  
4. **Querying and reporting:** The dashboard gathers monthly transactions via `TransactionReport`, calculates income/expense totals, evaluates budgets, and emits chart data for category breakdowns, showcasing separation between reporting logic and UI.
5. **Views and layout:** Components render Blade templates under `resources/views/livewire`, wrapped in a shared layout (`components.layouts.app`) that wires Livewire/Volt assets, Tailwind styles, and Vite-built scripts for a cohesive UI.

## Running the App Locally

This project uses **Docker / Laravel Sail** for local development. It handles PHP, MySQL, and all dependencies inside containers — no need to install PHP or Node on your machine beyond the initial bootstrap.

Sail is Laravel's built-in Docker development environment. It wraps `docker compose` with a simple `./vendor/bin/sail` command, and this project adds a `Makefile` on top so commands are even shorter.

**Prerequisites:** Install [Docker Desktop](https://www.docker.com/products/docker-desktop/) and start it. You also need PHP and Composer installed locally **once** to pull in Sail before Docker takes over.

1. **Clone the repository:**
   ```bash
   git clone ... # use preferred method (HTTPS/SSH)
   cd laravel-finance-tracker-app
   ```

2. **Run the one-command setup** — this installs PHP dependencies, builds the Docker image, generates an app key, runs database migrations, creates the storage symlink, installs JS dependencies, and compiles frontend assets:
   ```bash
   make setup
   ```

3. **Visit the app:** Open `http://localhost:8080` in your browser (or whatever `APP_PORT` is set to in `.env`) and register a user (or run the database seeder for a pre-configured account).

**Optional — start the Vite dev server** if you are actively editing CSS or JavaScript:
   ```bash
   make npm-dev   # run in a second terminal; keep it running while you work
   ```
   Without this, a one-off asset build was already done by `make setup` so the app works fine. See [Local Docker Setup](docs/local-docker-setup.md#how-hot-reloading-works) for a full explanation of how hot-reloading works inside Docker.

All subsequent commands go through `make`. Run `make help` or see [Developer Reference](docs/developer-reference.md) for the full list.

## Developer Documentation

| Doc | Contents |
|---|---|
| [Local Docker Setup](docs/local-docker-setup.md) | Why Docker, how Sail works, container config, FAQ |
| [Architecture](docs/architecture.md) | Livewire patterns, user scoping, money handling, recurring transactions, key files |
| [Developer Reference](docs/developer-reference.md) | Database, queue, cache, Tinker, code quality, and logs commands |
| [Bank Statement Upload](docs/bank-statement-upload.md) | Import lifecycle, data model, queue flow, deduplication |

## GitHub Actions / CI
The repository includes multiple workflows to keep quality high and demonstrate CI/CD practices:
- **Tests (`tests.yml`):** Installs PHP 8.4 and Node 22, builds assets, and runs PHPUnit to guard core flows like authentication and finance operations.
- **Linter (`lint.yml`):** Runs Laravel Pint and can auto-commit fixes on branches, ensuring consistent styling without manual effort.
- **Static analysis (`static-analysis.yml`):** Executes PHPStan over app, config, routes, database, and tests directories to catch type issues early.
- **Additional checks:** Workflows for build verification, coverage, dependency audits, secret scanning, migrations, and PHP security checks further harden the codebase (see `.github/workflows/`).

## Skills Demonstrated
- Laravel 13 fundamentals: routing to Livewire components, Fortify auth, validation, Eloquent models, and schema migrations.
- Livewire 4 + Volt patterns: server-driven UI state, reusable layouts, and interactive forms without heavy JavaScript.
- Domain modelling: Transactions, categories, budgets, net worth entries, and recurring transaction support with occurrence exceptions.
- Async queue processing: CSV bank statement import pipeline with job retries, duplicate detection, and a staged review/commit workflow.
- Frontend tooling: Vite, Tailwind CSS, and Laravel Vite plugin for modern asset pipelines.
- DevOps & quality: Multi-stage CI with tests, linting, static analysis, coverage, and security scans to mirror professional workflows.
