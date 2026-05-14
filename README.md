# Laravel Finance Tracker

## Project Overview
Laravel Finance Tracker is a personal budgeting and finance dashboard built with the Laravel 13 Livewire starter kit. It lets authenticated users record transactions, group them into categories, set monthly budgets, track net worth entries, and review reports that summarise income vs. expenses over time. All data is scoped per user.

- **Framework:** Laravel 13 with Fortify authentication, Livewire 4, and Volt/Flux for server-driven UI components.
- **Language:** PHP 8.4+.
- **Frontend:** Blade-based Livewire views enhanced by Volt components; assets compiled with Vite, Tailwind CSS, and the Laravel Vite plugin.
- **Database:** SQLite by default for easy local setup (switchable to MySQL/PostgreSQL via `.env`).
- **Tooling:** Composer for PHP dependencies, npm for frontend tooling, and Docker / Laravel Sail for a consistent local development environment.

## Key Design Principles
- **Framework conventions first:** Routes point directly to Livewire classes and Volt pages, leaning on Laravel defaults instead of custom routing or service layers for clarity.
- **Separation of concerns:** Livewire components own screen-level interactions, Eloquent models handle data, and support classes (e.g., `App\Support\TransactionReport`) encapsulate reporting logic. Views stay thin and presentation-focused.
- **Simplicity over premature optimisation:** SQLite works out of the box, migrations seed the necessary schema, and scripts automate common tasks to allow development rather than environment wrangling.
- **Learning-first architecture:** The code follows Laravel’s directory conventions and uses explicit method naming (`mount`, `render`, `save`, `edit`, `delete`) to make the request/component lifecycle easy to follow.

## Directory Structure Explained
- **`app/Livewire`** – Screen-level components such as `Dashboard`, `TransactionManager`, `CategoryManager`, `BudgetManager`, `NetWorthTracker`, and `ReportsHub`. Each class manages its own state, validation rules, and rendering logic.
- **`app/Models`** – Eloquent models for `Transaction`, `Category`, `Budget`, `NetWorthEntry`, and related domain entities. They map database tables and relationships using Laravel’s Active Record pattern.
- **`app/Support`** – Reusable domain services such as `TransactionReport` and `Money` that perform calculations and reporting, keeping Livewire components lean.
- **`routes/web.php`** – Declares authenticated routes that point directly to Livewire classes and Volt-powered settings pages, demonstrating Laravel’s route-to-component workflow.
- **`resources/views`** – Blade and Livewire templates (including Volt partials) that render layouts, components, and screen views. Styles are compiled via Tailwind and Vite for a clean developer experience. 
- **`database/migrations` & `database/factories`** – Schema definitions and factories for seeding test data, ensuring the app is reproducible across machines.  
- **`config` & `bootstrap`** – Standard Laravel configuration and bootstrap files; largely unchanged to keep the focus on Laravel defaults and readability.  
- **`tests`** – Feature and unit tests (PHPUnit) that exercise critical flows and support CI automation.

## How the Application Works
1. **Request flow:** Authenticated routes defined in `routes/web.php` map to Livewire classes (e.g., `/transactions` → `TransactionManager`). Livewire handles request/response cycles server-side and re-renders Blade fragments as state changes.  
2. **Stateful components:** Each Livewire class exposes public properties for form state (amount, date, category, recurrence) and lifecycle hooks (`mount`, `render`, `updatedIsRecurring`) to set defaults and react to user interactions.
3. **Validation and persistence:** Actions like `save`, `edit`, and `delete` validate input, enforce user scoping with `Auth::id()`, and use Eloquent to insert or update rows. Recurring transactions support occurrence exceptions to omit specific dates.  
4. **Querying and reporting:** The dashboard gathers monthly transactions via `TransactionReport`, calculates income/expense totals, evaluates budgets, and emits chart data for category breakdowns, showcasing separation between reporting logic and UI.
5. **Views and layout:** Components render Blade templates under `resources/views/livewire`, wrapped in a shared layout (`components.layouts.app`) that wires Livewire/Volt assets, Tailwind styles, and Vite-built scripts for a cohesive UI.

## Running the Project Locally

This project uses **Docker / Laravel Sail** for local development. It handles PHP, SQLite, and all dependencies inside containers — no need to install PHP or Node on your machine beyond the initial bootstrap.

**What is Laravel Sail?** Sail is Laravel's built-in Docker development environment. It wraps `docker compose` with a simple `./vendor/bin/sail` command, and this project adds a `Makefile` on top so commands are even shorter.

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

3. **Visit the app:** Open `http://localhost:8080` in your browser (or whatever `APP_PORT` is set to in `.env`) and register a user.

**Optional — start the Vite dev server** if you are actively editing CSS or JavaScript:
   ```bash
   make npm-dev   # run in a second terminal; keep it running while you work
   ```
   Without this, a one-off asset build was already done by `make setup` so the app works fine. You only need Vite running if you want CSS/JS changes to appear in the browser instantly without a manual rebuild. See [How hot-reloading works](#how-hot-reloading-works) below for more detail.

**Day-to-day commands** (Docker must be running; no local PHP or Node needed):

| Command | What it does |
|---|---|
| `make up` | Start all containers in the background |
| `make down` | Stop all containers |
| `make shell` | Open a bash shell inside the app container |
| `make test` | Run the PHPUnit test suite |
| `make migrate` | Run outstanding database migrations |
| `make fresh` | Wipe the database and re-run all migrations |
| `make logs` | Tail application logs |
| `make pint` | Fix code style |
| `make phpstan` | Run static analysis |

### How hot-reloading works

There are two independent mechanisms for seeing your changes without rebuilding the container:

**PHP / Blade / Livewire changes** are visible immediately on the next browser refresh. This works because your entire project folder is mounted directly into the container — the PHP process reads your files from your Mac in real time. No Vite, no rebuild needed.

**CSS / JavaScript / Tailwind changes** require an extra step. These files are compiled into bundles (`public/build/`). `make setup` produces a one-off bundle, which is enough to use the app. If you want changes to CSS or JS to appear in the browser *instantly* (without even refreshing the page), run `make npm-dev` in a second terminal — this starts the Vite dev server, which watches those files and injects changes live.

In short: if you're writing PHP, just refresh the browser. Only start `make npm-dev` if you're actively working on CSS or JavaScript.

### Makefile
All commands go through `make`. Run `make help` to see the full list.

| Command | What it does |
|---|---|
| `make setup` | First-time setup |
| `make up` / `make down` | Start / stop containers |
| `make test` | Run PHPUnit |
| `make pint` | Fix code style |
| `make phpstan` | Run static analysis |
| `make shell` | Shell into the app container |
| `make build` | Rebuild the Docker image without cache |
| `make rebuild` | Tear down, rebuild from scratch, start back up, and open a shell |

For any `artisan` command not covered by a `make` target, either use `make artisan cmd="<command>"` or open a shell inside the container with `make shell` and run `php artisan` directly.

### Database
```bash
# Run outstanding migrations
./vendor/bin/sail artisan migrate

# Wipe the database and re-run all migrations from scratch
./vendor/bin/sail artisan migrate:fresh

# Wipe and re-run migrations, then seed
./vendor/bin/sail artisan migrate:fresh --seed

# Roll back the most recent batch of migrations
./vendor/bin/sail artisan migrate:rollback

# Roll back a specific number of batches
./vendor/bin/sail artisan migrate:rollback --step=2

# Show migration status
./vendor/bin/sail artisan migrate:status
```

### Queue
The app uses the `database` queue driver. `ParseBankStatementJob` is dispatched when a CSV is uploaded and handles parsing with 3 retries and a 60-second timeout. The queue worker runs automatically inside the container (via Supervisor) — the commands below are for manual intervention.

```bash
# Show all failed jobs
./vendor/bin/sail artisan queue:failed

# Retry a specific failed job by its ID
./vendor/bin/sail artisan queue:retry <id>

# Retry all failed jobs
./vendor/bin/sail artisan queue:retry all

# Delete a specific failed job
./vendor/bin/sail artisan queue:forget <id>

# Clear all failed jobs
./vendor/bin/sail artisan queue:flush

# Monitor queue sizes (useful for spotting backlogs)
./vendor/bin/sail artisan queue:monitor database:10

# Dispatch ParseBankStatementJob manually for a given import ID (useful for debugging)
./vendor/bin/sail artisan tinker --execute="App\Jobs\ParseBankStatementJob::dispatch(<import_id>);"

# Reset a stuck import back to 'uploaded' so the job will re-process it
./vendor/bin/sail artisan tinker --execute="App\Models\BankStatementImport::find(<id>)->update(['status' => 'uploaded']);"
```

### Cache & Config
```bash
# Clear all caches in one go
./vendor/bin/sail artisan optimize:clear

# Clear individual caches
./vendor/bin/sail artisan config:clear
./vendor/bin/sail artisan route:clear
./vendor/bin/sail artisan view:clear
./vendor/bin/sail artisan cache:clear

# Re-cache config and routes for production
./vendor/bin/sail artisan optimize
```

### Tinker (REPL)
```bash
# Open an interactive REPL with the full app context
./vendor/bin/sail artisan tinker

# Useful one-liners:
# Count transactions for the first user
App\Models\Transaction::where('user_id', 1)->count();

# Inspect an import and its status
App\Models\BankStatementImport::with('importedTransactions')->find(<id>);

# Inspect failed imported transactions in an import
App\Models\ImportedTransaction::where('import_id', <id>)->where('is_committed', false)->get();
```

### Code Quality
```bash
# Fix code style (Laravel Pint)
make pint

# Check style without making changes (useful in CI)
./vendor/bin/sail exec laravel.test vendor/bin/pint --test

# Run static analysis (PHPStan)
make phpstan

# Run tests with code coverage (requires PCOV — included in the image)
./vendor/bin/sail artisan test --coverage
```

### Logs
```bash
# Tail logs in the terminal
make logs

# Tail and filter to a specific level
./vendor/bin/sail artisan pail --level=error

# Filter logs to queue/import-related messages
./vendor/bin/sail artisan pail --filter="bank statement"
```

## GitHub Actions / CI
The repository includes multiple workflows to keep quality high and demonstrate CI/CD practices:
- **Tests (`tests.yml`):** Installs PHP 8.4 and Node 22, builds assets, and runs PHPUnit to guard core flows like authentication and finance operations.
- **Linter (`lint.yml`):** Runs Laravel Pint and can auto-commit fixes on branches, ensuring consistent styling without manual effort.
- **Static analysis (`static-analysis.yml`):** Executes PHPStan over app, config, routes, database, and tests directories to catch type issues early.
- **Additional checks:** Workflows for coverage, dependency audits, secret scanning, migrations, and PHP security checks further harden the codebase (see `.github/workflows/`). 

## Skills Demonstrated
- Laravel 13 fundamentals: routing to Livewire components, Fortify auth, validation, Eloquent models, and schema migrations.  
- Livewire 4 + Volt patterns: server-driven UI state, reusable layouts, and interactive forms without heavy JavaScript. 
- Domain modelling: Transactions, categories, budgets, net worth entries, and recurring transaction support with occurrence exceptions.
- Frontend tooling: Vite, Tailwind CSS, and Laravel Vite plugin for modern asset pipelines.
- DevOps & quality: Multi-stage CI with tests, linting, static analysis, coverage, and security scans to mirror professional workflows.
