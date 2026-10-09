# Finance Tracker

A personal budgeting and finance dashboard. Record transactions, organise them into categories, set monthly budgets, track net worth over time, and import bank statement CSVs, all scoped per user with full authentication.

## Features

- **Transactions:** Log income and expenses with categories, dates, and descriptions; support for recurring transactions (weekly, monthly, quarterly, yearly) with per-occurrence exceptions
- **Categories:** Organise transactions with a two-level hierarchy (parent → subcategory), split by income and expense types
- **Budgets:** Set monthly spending limits and investment goals, with progress on the dashboard
- **Net worth tracking:** Record periodic net worth snapshots with itemised assets and liabilities
- **Reports:** Visualise income vs. expenses over time with category breakdowns and chart data
- **Bank statement import:** Upload CSV files from any bank, configure column mappings per provider, review staged transactions with duplicate detection, then commit to your history
- **Upcoming Payments:** Keep independent one-off, quarterly and yearly payment plans with an optional note. See every occurrence due in the next 12 months, plus the next date for overdue and later plans. Mark the earliest occurrence done or undo it without changing transactions, reports or budget actuals. Add and edit on the planning page; the next three occurrences appear at the bottom of the dashboard
- **Dashboard:** Monthly income, spending, investing, and calculated savings alongside budget progress and category charts

## Tech Stack

- **Backend:** Laravel 13, PHP 8.5, Fortify authentication
- **Frontend:** Livewire 4, Volt, Flux UI, Blade, Tailwind CSS, Vite
- **Database:** MySQL 9.7
- **Infrastructure:** Docker / Laravel Sail, Supervisor (web server + queue worker)

## Quick Start

**Prerequisite:** [Docker Desktop](https://www.docker.com/products/docker-desktop/) installed and running.

```bash
git clone <repo-url>
cd finance-tracker
make setup
```

Public registration is disabled. Create an account from the CLI:

```bash
make artisan cmd="app:create-user"
```

Then visit `http://localhost:8080` and log in. See [docs/setup.md](docs/setup.md) for environment configuration, commands reference, and troubleshooting. For deploying and operating the app in production, see [docs/deployment.md](docs/deployment.md).

To start the Vite dev server for live CSS/JS reloading:

```bash
make npm-dev
```

## CI

GitHub Actions runs build verification, Pint, PHPStan, Rector, migrations, PHPUnit with coverage, and focused Pest Browser tests on pushes to `main` and pull requests. Security checks audit Composer and npm dependencies on those events and weekly. Infection checks changed application PHP on pull requests. Dependabot checks for GitHub Actions, Composer, and npm updates weekly. See `.github/workflows/` and `.github/dependabot.yml` for details.
