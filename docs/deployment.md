# Deployment & Operations (Laravel Cloud)

This document describes how the app is deployed and operated in production on
[Laravel Cloud](https://cloud.laravel.com), and how to manage it day to day.

This app runs on Laravel Cloud - a fully managed platform for Laravel. There are
no servers to manage: you deploy by pushing to `main`, and Laravel Cloud builds
the app, runs migrations, serves it over HTTPS, and processes background jobs.

---

## 1. At a glance

| Property | Value |
|---|---|
| Platform | Laravel Cloud (Starter plan) |
| Region | EU West (London) - `eu-west-2` |
| App URL | `https://<your-app>.laravel.cloud` |
| Compute | Flex, scale-to-zero (sleeps when idle) |
| Database | Managed MySQL 8.4, Dev tier (512 MiB / 5 GB / 1-day backups) |
| Object storage | S3-compatible bucket on Cloudflare R2 (private) |
| Queue | Managed queue (scale-to-zero), connection `cloud` |
| Email | None - the app sends no email |
| Auth | Fortify; public registration disabled, password reset disabled |
| Deploy trigger | Push / merge to `main` |
| Rough cost | ~$7–12 / month (see §12) |

The stack itself: Laravel 13, PHP 8.5, Livewire + Volt + Flux UI, Blade, Tailwind,
Vite, MySQL, Fortify (auth + 2FA).

---

## 2. How it works (architecture)

### 2.1 The core production constraint

In production, the web container (which handles an HTTP upload) and the
queue worker (which parses the CSV in the background) run on separate
machines with separate, ephemeral local disks. A file written to the web
machine's local disk is simply not there on the worker.

This drove the single most important production change: bank-statement CSVs are
stored on a shared object-storage bucket, not the local disk. The flow is:

1. User uploads a CSV → the web request stores it on the `s3` disk (the R2
   bucket) at `statements/{importId}.csv`.
2. A `ParseBankStatementJob` is dispatched onto the managed queue.
3. The worker picks up the job, streams the file from the bucket to a local
   temporary file (because `SplFileObject` needs a real local path), parses it,
   then deletes the temp file in a `finally` block.
4. Parsed rows are saved; the import is marked `parsed` for the user to review.

The disk used for statements is controlled by `STATEMENTS_DISK` (set to `s3` in
production, defaults to `local` for local development). See
`config/filesystems.php`, `app/Support/BankStatementConfig.php`, and
`app/Support/BankStatement/BankStatementImportProcessor.php`.

### 2.2 Request flow

```
Browser ──> HTTPS ──> Laravel Cloud load balancer ──> Web container (Laravel + Livewire)
                                                      │
                              dispatch job            ├──> MySQL (managed)
                              to "cloud" queue ──────>│
                                                      └──> R2 bucket (statements/*.csv)
                                                             ▲
Managed queue (scale-to-zero) ── reads job ── worker ────────┘ streams CSV to temp file, parses
```

Because the app sits behind Laravel Cloud's load balancer, `bootstrap/app.php`
calls `->trustProxies(at: '*')` so the framework sees the real client IP and the
correct `https` scheme (for secure cookies, URL generation, and rate limiting).

### 2.3 Why these driver choices

- Session = cookie, cache = database, queue = cloud: no Redis/Valkey
  instance is provisioned, which keeps cost down. The managed queue provides the
  `cloud` connection automatically.
- Logs → `laravel-cloud-socket` channel: captured natively by the Laravel
  Cloud log viewer (do not switch this to `stderr`).

---

## 3. Infrastructure components

### 3.1 Application
- Created in Laravel Cloud from the GitHub repo `yoni-haber/finance-tracker`,
  branch `main`.
- Starter plan, EU West (London) region.
- Flex compute with scale-to-zero and the smallest size - the app sleeps when
  idle and wakes on the next request, so you pay almost nothing while unused.

### 3.2 Database
- Managed MySQL 8.4, named `main`, Dev tier: 512 MiB RAM, 5 GB storage,
  1-day backups. Chosen because the data is tiny (transactions/categories/budgets
  are kilobytes) - the Prod tier (2 GiB / 20 GB) would be wasted money.
- Attached to the app, so the `DB_*` environment variables are injected
  automatically - credentials are never copied by hand.
- Private by default (not reachable from the public internet). See §9 for
  connecting a GUI safely.

### 3.3 Object storage bucket (CSV statements)
- S3-compatible bucket backed by Cloudflare R2
  (endpoint `https://<hash>.eu.r2.cloudflarestorage.com`, region `auto`).
- Name: `finance_tracker_statements`. Visibility: private (these are people's
  bank statements - never public). EU jurisdiction enabled for data residency.
- Disk name `s3` - deliberately matches the `s3` disk already defined in
  `config/filesystems.php`, so the injected credentials wire straight into it with
  no code change.
- Access key permission: read & write (web writes/deletes, worker reads).
- Laravel Cloud injects the bucket as `LARAVEL_CLOUD_DISK_CONFIG` (a JSON blob)
  plus `FILESYSTEM_DISK=s3`.
- Required package: the framework's S3 driver needs
  `league/flysystem-aws-s3-v3` - deploy fails otherwise.

### 3.4 Queue (managed)
- On the Starter plan, dedicated worker clusters are Growth-plan only, so we
  use the Managed queue (scale-to-zero) from the environment's Compute
  section. It wakes when a job arrives and sleeps when idle.
- It injects `QUEUE_CONNECTION=cloud` for the whole environment, so the app
  dispatches to - and the managed queue processes - the same `cloud` connection.
- Settings used: name `default`, memory 256 MiB, max workers 3, polling 5s,
  graceful shutdown 90s. Worker-level `tries`/`timeout` are effectively overridden
  by the job itself, which declares `tries = 3` and `timeout = 60` in code
  (`app/Jobs/ParseBankStatementJob.php` via `BankStatementConfig`). Consider a
  visibility timeout comfortably above the 60s job timeout (e.g. 120s) for headroom
  - the processor also has an atomic `uploaded → parsing` claim that prevents
  double-processing if a job is ever redelivered.

---

## 4. Environment variables

`DB_*`, the bucket config (`LARAVEL_CLOUD_DISK_CONFIG` / `FILESYSTEM_DISK`), and
`QUEUE_CONNECTION=cloud` are injected automatically by the attached database,
bucket, and managed queue. Most `APP_*` / `LOG_*` values are auto-injected too.

### 4.1 Auto-injected

```dotenv
APP_NAME="finance-tracker"
APP_ENV=production
APP_DEBUG=false
APP_URL="https://<your-app>.laravel.cloud"

LOG_CHANNEL=laravel-cloud-socket
LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter

DB_CONNECTION=mysql
DB_HOST=db-<cluster-id>.eu-west-2.db.laravel.cloud
DB_PORT=3306
DB_DATABASE=main
DB_USERNAME=<redacted>
DB_PASSWORD=<redacted>

SESSION_DRIVER=cookie
CACHE_STORE=database
SCHEDULE_CACHE_DRIVER=database

FILESYSTEM_DISK=s3
LARAVEL_CLOUD_DISK_CONFIG='[{"disk":"s3","is_default":true, ... ,"endpoint":"https://<hash>.eu.r2.cloudflarestorage.com", ...}]'

QUEUE_CONNECTION=cloud          # injected by the managed queue
VITE_APP_NAME="${APP_NAME}"
```

### 4.2 Custom variables

```dotenv
APP_KEY=base64:<redacted>       # use the dashboard "Generate" button - never invent one
STATEMENTS_DISK=s3              # REQUIRED: routes bank-statement CSVs to the bucket
SESSION_SECURE_COOKIE=true      # session cookie only sent over HTTPS
LOG_LEVEL=warning               # reduce log noise/cost
```

> `STATEMENTS_DISK=s3` is the critical one. Without it the code falls back to
> the `local` disk and imports break in production (the worker can't find the file).
>
> `QUEUE_CONNECTION` does not need to be added manually - it is injected as
> `cloud` by the managed queue, and the app's config also defaults it to `database`
> if ever unset.

---

## 5. Build & deploy pipeline

Deployment is triggered by a push/merge to `main`.

- Build commands (auto-detected by Laravel Cloud):
  ```bash
  composer install --no-dev --optimize-autoloader
  npm ci && npm run build
  ```
- Deploy hook (runs on every release):
  ```bash
  php artisan migrate --force
  php artisan optimize
  ```
  `migrate --force` applies new migrations non-interactively; `optimize` caches
  config/routes/views for speed.
- Scheduler: not configured - the app has no scheduled tasks yet.

---

## 6. Authentication & user management

This is a closed app for the owner plus a few trusted users. There are no public
sign-ups and no email.

- Registration is disabled - `/register` returns 404 (route removed from
  `routes/auth.php`, view deleted).
- Password reset is disabled - `/forgot-password` and `/reset-password/{token}`
  return 404. The app sends no email, so the self-service flow could never deliver
  a link. The login page's "Forgot your password?" link auto-hides (guarded by
  `@if (Route::has('password.request'))`).
- 2FA is available via Fortify (authenticator app + recovery codes) - enable it
  on each account under Settings.
- Accounts created via the command are pre-verified (email verification is
  skipped), so users can log in immediately.

### 6.1 Create a user

Run from the Laravel Cloud Commands tab. The Commands tab is
non-interactive (no TTY) - you must pass every value as a flag.

```bash
php artisan app:create-user --name="Their Name" --email=them@example.com --password="a-strong-password"
```

### 6.2 Reset a user's password

```bash
php artisan app:reset-password --email=them@example.com --password="a-new-password"
```

> Passwords are bcrypt hashes - one-way. They cannot be decoded/decrypted (that
> is what keeps them safe even if the database leaks). "Resetting" simply writes a
> new hash. The User model's `'password' => 'hashed'` cast applies bcrypt
> automatically.

---

## 7. Production-readiness code changes (history)

The work to make the app deployable was delivered in three PRs (all merged to
`main`, all gated by CI):

- #147 - Prepare app for production:
  - Configurable statements disk (`STATEMENTS_DISK`, `config/filesystems.php`,
    `BankStatementConfig::statementsDisk()`/`statementPath()`); processor streams
    the CSV from the disk to a temp file instead of using `->path()`.
  - Disabled public registration; added `app:create-user`.
  - `->trustProxies(at: '*')` in `bootstrap/app.php`.
  - Supporting docs and `.env.example`; `memory_limit = 512M` in
    `docker/8.5/php.ini` (dev/CI only - does not affect production) to stop
    coverage runs OOM-ing; Rector/Infection CI fixes.
- #148 - Add S3 Flysystem adapter: `league/flysystem-aws-s3-v3`, required for
  the `s3` disk to talk to the bucket.
- #149 - Replace public password reset with an admin command: removed the
  forgot/reset routes + views; added `app:reset-password`; rewrote the
  password-reset tests.

---

## 8. Day-to-day operations

| Task | How |
|---|---|
| Deploy a change | `git push` / merge to `main` - auto-builds, migrations run via the deploy hook |
| Add a user | `php artisan app:create-user --name=… --email=… --password=…` (Commands tab, flags required) |
| Reset a password | `php artisan app:reset-password --email=… --password=…` (Commands tab) |
| View logs | Laravel Cloud dashboard (the `laravel-cloud-socket` channel) |
| Inspect the DB with a GUI | Enable the public endpoint temporarily - see §9 |
| Recover a stuck import | See [bank-statement-upload.md](bank-statement-upload.md#recovering-a-stuck-import) |
| Database backups | Automated by Laravel Cloud (Dev tier = 1-day retention) |

---

## 9. Connecting to the database from a GUI

The production database is private by default - a direct connection attempt
returns "No route to host" because there is no public endpoint and (on this
plan) no IP allowlist.

To connect:

1. In the database settings, toggle Enable public endpoint. The host changes to
   the `.public.` variant, e.g.
   `db-<cluster-id>.eu-west-2.public.db.laravel.cloud`.
2. Laravel Cloud shows a connection string of the form
   `mysql://<user>:<password>@<public-host>?name=…`. Map it into your client:
   - Host: `db-<cluster-id>.eu-west-2.public.db.laravel.cloud`
   - Port: `3306`
   - Database: `main`
   - User / Password: the injected `DB_USERNAME` / `DB_PASSWORD`
   - The `?name=…` part is only a display label.
   - Or paste a JDBC URL:
     `jdbc:mysql://db-<cluster-id>.eu-west-2.public.db.laravel.cloud:3306/main`
3. In the client's SSL settings, require TLS (Laravel Cloud enforces encrypted
   connections). Download the MySQL driver if prompted.

> ⚠️ Security: the public endpoint exposes the production database to the entire
> internet, protected only by the password + TLS. Turn it back off when you're
> done. Treat the DB password as sensitive; rotate it in the dashboard if it's
> ever shown somewhere it shouldn't be. The app itself never needs the public
> endpoint - it connects over the private network.

---

## 10. Monitoring & logs

- Logs: Laravel Cloud dashboard, via the `laravel-cloud-socket` channel
  (JSON-formatted). `LOG_LEVEL=warning` keeps the volume (and cost) down; lower it
  to `debug` temporarily when diagnosing an issue.
- Imports: a healthy import goes `uploaded → parsing → parsed`. If it stays
  `pending`/`processing`, suspect the managed queue; if it goes `failed`, suspect
  the bucket/disk (check the logged error - the processor logs
  `Bank statement parsing failed` with context).
- Optional later: Laravel Pulse (free, self-hosted dashboard) for app
  metrics.

---

## 11. Backups, spending limit & safety

- Automated DB backups: enabled on the database (Dev tier retains 1 day).
  Acceptable here because data changes slowly and bank CSVs can be re-imported.
- Spending limit: hard cap set at $15 per month.
- Disaster recovery: redeploy from `main` (immutable in Git) + restore the
  latest DB backup. Statement CSVs live in the bucket; parsed data lives in MySQL.

---

## 12. Security posture

- HTTPS everywhere (automatic TLS); `APP_DEBUG=false` so stack traces never leak.
- `SESSION_SECURE_COOKIE=true`; trusted proxies configured for correct scheme/IP.
- No public registration, no public password reset, no inbound email surface.
- Secrets (DB password, bucket keys, `APP_KEY`) live encrypted in the dashboard,
  never in the repo. This doc uses placeholders only - never commit real
  credentials.
- Database public endpoint kept off except when briefly needed for a GUI.
- Passwords stored as bcrypt hashes; 2FA available.

---

## 13. Troubleshooting

| Symptom | Cause & fix |
|---|---|
| `In Interactivity.php line 32: Required.` running a command | The Commands tab is non-interactive - pass all values as flags (e.g. `--name`, `--email`, `--password`). |
| DB GUI: "No route to host" | DB is private - enable the public endpoint (§9), require SSL; disable it again afterwards. |
| Import stuck on `pending`/`processing` | Managed queue not processing - check it's enabled and `QUEUE_CONNECTION=cloud` is injected; check logs. |
| Import goes to `failed` | Usually the bucket/disk - confirm `STATEMENTS_DISK=s3` is set and the bucket is attached; check the logged error context. |
| Login/session errors after deploy | Ensure `APP_KEY` is set (Generate button) and `APP_URL` matches the real URL. |

---

## 14. Quality gates (CI)

Every PR to `main` is gated by GitHub Actions before it can deploy:

- PHPUnit (full suite, 500+ tests)
- PHPStan level 8
- Laravel Pint (code style)
- Rector (dry-run - must be clean)
- Infection (mutation testing on changed code)
- Dependency/security audits

This is the safety net behind "just push to `main`": broken changes are caught
before they reach production.
