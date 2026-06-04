# Setup & Developer Reference

## Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) installed and running
- Git (for cloning)

No host PHP, Composer, or Node installation is required. Everything runs inside Docker via [Laravel Sail](https://laravel.com/docs/sail).

## First-Time Setup

```bash
git clone <repo-url>
cd laravel-finance-tracker-app
make setup
```

This single command:
1. Copies `.env.example` → `.env` (if missing)
2. Installs PHP dependencies via a temporary `composer:2` Docker container
3. Builds the PHP 8.5 image from `docker/8.5/Dockerfile` and starts all containers
4. Generates `APP_KEY`, runs migrations, creates the storage symlink
5. Installs npm packages and builds frontend assets

First run takes a few minutes (Docker image build). Subsequent `make up` starts in seconds.

Visit `http://localhost:8080` (or the port set in `APP_PORT`).

## Daily Workflow

```bash
make up        # start containers
make down      # stop containers
make restart   # restart without rebuilding (e.g. after .env change)
make shell     # open a bash shell inside the app container
```

## Makefile Commands

Run `make help` for the full list with descriptions.

| Command | Description |
|---|---|
| **Setup & Infrastructure** | |
| `make setup` | First-time project setup (run once after cloning) |
| `make rebuild` | Rebuild Docker images from scratch — after Dockerfile changes |
| `make reset` | ⚠️ Destroy everything (DB data included), re-run full setup |
| **Containers** | |
| `make up` | Start all containers |
| `make down` | Stop all containers |
| `make restart` | Quick restart without rebuilding |
| `make shell` | Bash shell inside the app container |
| **Code Quality** | Pass `f=<path>` to target a specific file or directory |
| `make test` | Run PHPUnit tests |
| `make lint` | Check style (Pint dry-run) + static analysis (PHPStan) |
| `make pint` | Auto-fix code style with Laravel Pint |
| `make phpstan` | Run PHPStan static analysis |
| `make rector` | Apply automated refactoring with Rector |
| `make infection` | Mutation testing (runs full suite first for coverage) |
| **Database** | |
| `make migrate` | Run pending migrations |
| `make fresh` | ⚠️ Drop all tables, re-run migrations + seeders |
| **Laravel** | |
| `make artisan cmd="..."` | Run any Artisan command |
| `make composer cmd="..."` | Run any Composer command |
| `make logs` | Tail live application logs via Pail |
| **Frontend** | |
| `make npm-dev` | Start Vite dev server with hot-reload |
| `make npm-build` | Build frontend assets for production |

## Database

```bash
make migrate                                    # run pending migrations
make fresh                                      # drop all tables, re-migrate + seed
make artisan cmd="migrate:rollback"             # roll back last batch
make artisan cmd="migrate:rollback --step=2"    # roll back N batches
make artisan cmd="migrate:status"               # show migration status
```

Tests use a separate `finance_tracker_testing` database on the same MySQL container, created automatically by `docker/mysql/create-testing-db.sql`.

## Queue

The app uses the `database` queue driver. A Supervisor-managed `queue:listen` worker runs automatically inside the container.

```bash
# Inspect failed jobs
make artisan cmd="queue:failed"

# Retry a specific failed job
make artisan cmd="queue:retry <id>"

# Retry all failed jobs
make artisan cmd="queue:retry all"

# Clear all failed jobs
make artisan cmd="queue:flush"

# Monitor queue sizes
make artisan cmd="queue:monitor database:10"
```

## Cache & Config

```bash
make artisan cmd="optimize:clear"     # clear all caches
make artisan cmd="optimize"           # re-cache config and routes
```

## Tinker

```bash
make artisan cmd="tinker"

# One-liners
App\Models\Transaction::where('user_id', 1)->count();
App\Models\BankStatementImport::with('importedTransactions')->find($id);
```

## Code Quality

All code quality commands accept `f=<path>` to target a specific file or directory.

```bash
make pint                              # auto-fix code style
make pint f=app/Models/User.php        # fix one file
make lint                              # Pint dry-run + PHPStan
make phpstan                           # static analysis only
make rector                            # automated refactoring
make infection                         # mutation testing
make infection f=UserService.php       # mutate one source file
```

## Testing

### Structure

Tests live in `tests/` and are split into two PHPUnit suites:

- **`tests/Feature/`:** End-to-end tests that boot the full application, hit Livewire components, and verify behaviour through the HTTP layer. Examples: `TransactionManagerTest`, `DashboardTest`, `StatementImportReviewTest`, auth flows.
- **`tests/Unit/`:** Isolated tests for models, support classes, and domain logic. Examples: `MoneyTest`, `TransactionReportTest`, `DuplicateDetectorTest`, model relationship and scope tests.

### Running Tests

```bash
make test                                     # run full suite
make test f=tests/Feature/DashboardTest.php   # run a single file
```

Tests run against the `finance_tracker_testing` MySQL database. `RefreshDatabase` wraps each test in a transaction that rolls back after completion so there is no manual clean-up needed.

### Conventions

- Feature tests extend `Tests\TestCase` and use `RefreshDatabase`.
- Create test data with model factories (`Category::factory()`, `Transaction::factory()`, etc.).
- Livewire components are tested via `Livewire::test(ComponentClass::class)`.
- All user-scoped data must be tested for isolation - `MultiUserIsolationTest` verifies that users cannot access each other's data.

### Mutation Testing

[Infection](https://infection.github.io/) is configured to verify that tests detect logic changes:

```bash
make infection                         # full suite
make infection f=Money.php             # target one source file
```

## Logs

```bash
make logs                                                 # tail all logs
./vendor/bin/sail artisan pail --level=error              # filter by level
./vendor/bin/sail artisan pail --filter="bank statement"  # filter by keyword
```

## Environment Variables

Sail-specific variables in `.env`:

| Variable | Default | Purpose |
|---|---|---|
| `APP_PORT` | `8080` | Host port mapped to the app container |
| `APP_SERVICE` | `laravel.test` | Main service name in `compose.yaml` |
| `COMPOSE_PROJECT_NAME` | `finance-tracker` | Docker container name prefix |
| `VITE_PORT` | `5173` | Vite dev server port |
| `WWWUSER` / `WWWGROUP` | `1000` | Maps container user to host user for file ownership |
| `SAIL_XDEBUG_MODE` | `off` | Set to `develop,debug` for step-debugging |
| `SAIL_XDEBUG_CONFIG` | `client_host=host.docker.internal` | Xdebug IDE connection target |
| `FORWARD_MAILPIT_DASHBOARD_PORT` | `8025` | Mailpit web UI port |

## Docker Configuration

The project uses a custom PHP 8.5 image (`docker/8.5/Dockerfile`) rather than the stock Sail image. It includes only the extensions this project needs: `pdo_mysql`, `mbstring`, `xml`, `zip`, `bcmath`, `intl`, `gd`, `pcntl`, and `pcov` for test coverage.

Supervisor (`docker/8.5/supervisord.conf`) runs two processes inside the container:
1. `php artisan serve` - the Laravel development server
2. `php artisan queue:listen --tries=1` - the queue worker (uses `queue:listen` so code changes are picked up immediately)

### Hot-Reloading

**PHP / Blade / Livewire:** Changes are visible immediately on browser refresh - the project folder is bind-mounted into the container.

**CSS / JavaScript:** Run `make npm-dev` in a second terminal to start the Vite dev server for live injection. Without it, the one-off build from `make setup` is used.

## Mailpit

Mailpit catches all outgoing emails during development. View them at `http://localhost:8025`.

## Troubleshooting

**Port 8080 already in use:** Change `APP_PORT` in `.env`, then `make down && make up`.

**Container exits immediately:** Ensure `.env` exists and `APP_KEY` is set. Run `make artisan cmd="key:generate"` if needed.

**MySQL stays unhealthy:** Another process may be using port 3306 — change `FORWARD_DB_PORT` in `.env`. Or run `make reset` to wipe the volume.
