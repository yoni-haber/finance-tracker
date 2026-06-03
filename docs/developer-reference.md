# Developer Reference

All commands run inside Docker via Laravel Sail. Docker Desktop must be running. For Artisan commands not covered by a `make` target, use `make artisan cmd="<command>"` or open a shell with `make shell` and run `php artisan` directly.

Run `make help` for a full list of targets.

## Makefile Commands

| Command | What it does |
|---|---|
| `make setup` | First-time setup after cloning (run once) |
| `make up` | Start containers — begin your work session |
| `make down` | Stop containers — end your work session |
| `make restart` | Quick restart without rebuilding (e.g. after `.env` change) |
| `make shell` | Open a bash shell inside the app container |
| `make test` | Run PHPUnit tests (`f=path/to/TestFile.php` for a single file) |
| `make lint` | Check style (Pint dry-run) + static analysis (PHPStan) — no file changes |
| `make pint` | Auto-fix code style (`f=path/to/file.php` to target a file) |
| `make phpstan` | Run static analysis (`f=path/to/file.php` to target a file) |
| `make rector` | Apply automated refactoring (`f=path/to/file.php` to target a file) |
| `make infection` | Run full test suite for coverage, then mutate (`f=source_file.php` to target a file) |
| `make migrate` | Run pending migrations — e.g. after pulling new code |
| `make fresh` | ⚠️ Drop all tables, re-run all migrations + seeders |
| `make logs` | Tail live app logs via Pail (Ctrl+C to stop) |
| `make rebuild` | Rebuild Docker images & restart — after Dockerfile changes |
| `make reset` | ⚠️ Nuke everything (DB data included), re-run full setup |
| `make npm-dev` | Start Vite HMR dev server |
| `make npm-build` | Build frontend assets for production |
| `make artisan cmd="..."` | Run any artisan command |
| `make composer cmd="..."` | Run any composer command |

## Database

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

## Queue

The app uses the `database` queue driver. `ParseBankStatementJob` is dispatched when a CSV is uploaded. The queue worker runs automatically inside the container via Supervisor — the commands below are for manual intervention.

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

## Cache & Config

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

## Tinker (REPL)

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

## Code Quality

All code quality commands support `f=<path>` to target a specific file or directory.

```bash
# Fix code style (Laravel Pint)
make pint
make pint f=app/Models/User.php

# Check style + static analysis without modifying files
make lint
make lint f=app/Services/

# Run static analysis (PHPStan)
make phpstan
make phpstan f=app/Services/

# Apply automated refactoring with Rector
make rector

# Preview Rector changes without applying them
./vendor/bin/sail exec laravel.test vendor/bin/rector --dry-run

# Run Infection mutation tests (runs full test suite first for coverage)
make infection
make infection f=UserService.php

# Check style without making changes (useful in CI)
./vendor/bin/sail exec laravel.test vendor/bin/pint --test

# Run static analysis with higher memory limit (inside container)
vendor/bin/phpstan analyse app --memory-limit=1G

# Run tests with code coverage (requires PCOV — included in the image)
./vendor/bin/sail artisan test --coverage
```

## Logs

```bash
# Tail logs in the terminal
make logs

# Tail and filter to a specific level
./vendor/bin/sail artisan pail --level=error

# Filter logs to queue/import-related messages
./vendor/bin/sail artisan pail --filter="bank statement"
```
