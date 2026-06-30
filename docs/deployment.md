# Deployment (Laravel Cloud)

This app is deployed to **[Laravel Cloud](https://cloud.laravel.com)** - a fully
managed platform for Laravel. You deploy by pushing to `main`; Laravel Cloud
builds the app, runs migrations, serves it over HTTPS, and runs the queue worker.
There are **no servers to manage**.

This guide is written for someone new to deployment. Follow it top to bottom.

---

## How production differs from local

| Concern | Local (Docker/Sail) | Production (Laravel Cloud) |
|---|---|---|
| Web server | `php artisan serve` | Managed, autoscaling |
| Queue worker | `queue:listen` (Supervisor) | Managed worker process |
| Database | MySQL container | Managed MySQL (sleeps when idle) |
| Statement CSVs | `local` disk | Shared object-storage **bucket** (`STATEMENTS_DISK=s3`) |
| Email | Mailpit (fake) | Real provider (Resend/Mailgun) |
| Secrets | `.env` file | Laravel Cloud dashboard (encrypted) |
| HTTPS | none | Automatic |
| Registration | open | **disabled** — create users with `app:create-user` |

---

## One-time setup

### 1. Push the repo to GitHub
Already done: `yoni-haber/finance-tracker`.

### 2. Create the Laravel Cloud app
1. Sign up at [cloud.laravel.com](https://cloud.laravel.com) and choose the
   **Starter** plan ($5/mo + usage, first month free, includes $5/mo credit).
2. **New Application** → authorise GitHub → pick this repo → branch `main`.
3. Choose a **region** close to you (e.g. *EU West (London)*).
4. Enable **scale-to-zero** (Flex compute) and the smallest size (512 MiB) so the
   app sleeps when idle and costs almost nothing.

### 3. Add a database
- Add a **MySQL** database, smallest compute, "sleep after" idle enabled.
- Laravel Cloud injects `DB_*` env vars automatically — do **not** paste
  credentials yourself.

### 4. Add an object-storage bucket (required for CSV imports)
The web request and the queue worker run on **separate machines**, so uploaded
bank-statement CSVs must live on a shared disk.
- Add a **Bucket**. Laravel Cloud injects the `AWS_*` env vars automatically.
- Set `STATEMENTS_DISK=s3` (see env vars below).

### 5. Set environment variables
In the app's **Environment** settings, set:

```dotenv
APP_NAME="Finance Tracker"
APP_ENV=production
APP_DEBUG=false
APP_KEY=                      # click "generate" in the dashboard
APP_URL=https://your-app.laravel.cloud   # or your custom domain

LOG_CHANNEL=stderr            # Laravel Cloud captures stdout/stderr
LOG_LEVEL=warning

# Keep session/cache/queue on the database to avoid paying for Redis
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
CACHE_STORE=database

# File storage
FILESYSTEM_DISK=s3
STATEMENTS_DISK=s3            # bank-statement CSVs live on the shared bucket

BCRYPT_ROUNDS=12

# Mail — see "Email" below
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS="you@your-domain.com"
MAIL_FROM_NAME="Finance Tracker"
```

> `DB_*` and `AWS_*` are injected automatically by the database and bucket — you
> do not set them by hand.

### 6. Configure build, deploy hook, and worker
- **Build commands** (usually auto-detected):
  `composer install --no-dev --optimize-autoloader` and `npm ci && npm run build`.
- **Deploy hook** (runs on every release):
  ```bash
  php artisan migrate --force
  php artisan optimize
  ```
- **Queue worker** process (required for CSV imports), smallest size:
  ```bash
  php artisan queue:work --tries=3 --timeout=60
  ```
- **Scheduler:** not needed — the app has no scheduled tasks yet.

### 7. Email
Email is needed for password resets (and any future notifications).
1. Create a free account at **[Resend](https://resend.com)** (3k/mo free) or
   **Mailgun**.
2. Verify a sending domain by adding the DNS records they show you (allow time
   for DNS to propagate).
3. Put the SMTP/API credentials into the `MAIL_*` env vars above.

### 8. Domain (optional)
- Use the free `*.laravel.cloud` subdomain (zero setup, HTTPS included), **or**
- Add a custom domain in Laravel Cloud and create the DNS records it shows. TLS
  is automatic. Remember to update `APP_URL` (and `SESSION_DOMAIN` if used).

---

## First deploy & verification

1. Merge your work into `main` (via PR). Laravel Cloud auto-builds and deploys.
2. Watch the **build logs**; confirm the deploy hook ran migrations.
3. **Create your account** from the Laravel Cloud command runner / shell:
   ```bash
   php artisan app:create-user
   ```
   (It prompts for name, email, password and creates a pre-verified user.)
4. Smoke test: log in, add a category + transaction, set a budget, upload a
   sample bank CSV (proves the worker + bucket work), trigger a password-reset
   email (proves mail works), enable 2FA.
5. Confirm `/register` returns **404** (public registration is disabled).

---

## Day-to-day operations

| Task | How |
|---|---|
| Deploy a change | `git push` to `main` — auto-deploys, migrations run via the deploy hook |
| Add a user | `php artisan app:create-user` (Cloud shell) |
| View logs | Laravel Cloud dashboard (we log to `stderr`) |
| Database backups | Enable automated backups in the dashboard |
| Cap spending | Set a **spending limit + alert** (e.g. $10) in the dashboard |
| Recover a stuck import | See [bank-statement-upload.md](bank-statement-upload.md#recovering-a-stuck-import) |

---

## Rough cost

For a mostly-idle personal app with scale-to-zero, expect **~$0–5/month**: the
$5 Starter fee is offset by the $5 monthly credit, and the first month is free.
Email (free tier) and the `*.laravel.cloud` subdomain add nothing. A custom
domain is ~$10/year from a registrar.
