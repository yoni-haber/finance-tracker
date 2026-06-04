# Finance Tracker

A personal budgeting and finance dashboard. Record transactions, organise them into categories, set monthly budgets, track net worth over time, and import bank statement CSVs, all scoped per user with full authentication.

## Features

- **Transactions:** Log income and expenses with categories, dates, and descriptions; support for recurring transactions (weekly, monthly, yearly) with per-occurrence exceptions
- **Categories:** Organise transactions with a two-level hierarchy (parent → subcategory), split by income and expense types
- **Budgets:** Set monthly spending limits per expense category and track actuals on the dashboard
- **Net worth tracking:** Record periodic net worth snapshots with itemised assets and liabilities
- **Reports:** Visualise income vs. expenses over time with category breakdowns and chart data
- **Bank statement import:** Upload CSV files from any bank, configure column mappings per provider, review staged transactions with duplicate detection, then commit to your history
- **Dashboard:** Monthly overview combining transaction summaries, budget progress, and category charts

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

Visit `http://localhost:8080` and register a user. See [docs/setup.md](docs/setup.md) for environment configuration, commands reference, and troubleshooting.

To start the Vite dev server for live CSS/JS reloading:

```bash
make npm-dev
```

## CI

GitHub Actions workflows run tests, linting (Pint), static analysis (PHPStan), Rector dry-runs, mutation testing (Infection), coverage, dependency audits, secret scanning, migration checks, and PHP security checks on every push. See `.github/workflows/` for details.
