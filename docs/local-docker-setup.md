# Local Development with Docker and Laravel Sail

---

## Why Docker?

Running the project locally without Docker would mean installing and managing the
correct PHP version, Composer, Node, npm, and MySQL directly on your machine — and
keeping them in sync with every project you work on.

**Docker solves this by packaging the entire environment — the right PHP version,
all the right system libraries, the queue worker — into containers that run the same
way on every machine.** Once Docker Desktop is installed and running, everything else
is handled automatically, including the initial Composer bootstrap.

**Prerequisites:** Install [Docker Desktop](https://www.docker.com/products/docker-desktop/) and start it.

---

## What is Docker?

Docker is a tool that runs applications inside lightweight, isolated environments
called **containers**. You can think of a container as a tiny computer running inside
your computer, with its own operating system, software, and file system.

The container is defined by a **Dockerfile** — a recipe that says "start with this
Linux image, install these packages, copy these files." You build that recipe into an
**image** (a snapshot), and then you run the image as a container.

**Docker Compose** is a tool on top of Docker that lets you define and start
*multiple* containers together using a single configuration file. For this project,
that means the Laravel app and a mail-catching tool (Mailpit) can all start with
one command.

---

## What is Laravel Sail?

[Laravel Sail](https://laravel.com/docs/sail) is Laravel's official Docker development
environment. It ships as a Composer package (`laravel/sail`) and provides:

1. A Docker image for PHP 8.5 — this project builds a custom Debian-based image
   (see `docker/8.5/Dockerfile`) rather than using Sail's stock Ubuntu image, but
   the extensions and tooling are equivalent
2. A shell script at `./vendor/bin/sail` that wraps the `docker compose` command
   so you don't need to remember long Docker syntax
3. A `docker compose` file (`compose.yaml`) that wires everything together

Sail is **only for local development**. It is not used in production.

---

## Files Added or Changed

### `.dockerignore` — excluded from Docker build context

The `.dockerignore` file at the project root tells Docker which files to exclude when
evaluating build context. However, note that `compose.yaml` sets the Docker build
context to `./docker/8.5` (not the project root), so this root `.dockerignore` does
**not** affect the image built by `make rebuild` or `make setup`. The `./docker/8.5/`
directory contains only configuration files (Dockerfile, php.ini, start-container,
supervisord.conf), so there are no large directories to exclude there anyway.

The file remains useful as a general safety net for any future tooling that uses the
project root as a Docker build context.

### `compose.yaml` — the development environment definition

This is the Docker Compose configuration file for local development. It defines two
services (containers) that run together:

```
laravel.test   ← the Laravel application
mailpit        ← a local email catcher
```

#### `laravel.test`

This is the main app container. Key things it does:

- **Builds from `docker/8.5/Dockerfile`** — the PHP 8.5 image with all required
  extensions and tools
- **Mounts your project folder** into the container at `/var/www/html` (the
  `.:/var/www/html` volume line). This means edits you make on your machine are
  instantly visible inside the container — no rebuild needed
- **Exposes ports** so your browser can reach the app:
  - Port `8080` → the Laravel app (so you visit `http://localhost:8080`)
  - Port `5173` → the Vite dev server (used for hot-reloading CSS/JS)
- **Sets `WWWUSER`** to your host machine's user ID so files created inside the
  container are owned by you, not by root
- **Restarts automatically** (`restart: unless-stopped`) if the container crashes
- **Waits for Mailpit to be healthy** before starting, using a `depends_on`
  condition with a health check

#### `mailpit`

Mailpit is a fake mail server for development. When the app sends an email
(password reset, welcome email, etc.), Mailpit catches it instead of actually
sending it. You can then view the email in a browser at `http://localhost:8025`.

The Mailpit image is pinned to a specific version (`v1.29`) rather than using
`:latest`, so builds are reproducible and won't break if Mailpit ships a
breaking change. A health check is configured so the app container waits until
Mailpit is actually ready to accept connections before starting.

This prevents accidentally sending real emails during development.

---

### `docker/8.5/` — the Sail PHP image

These files define the Docker image that Sail builds and runs.

> **Tip:** Run `make rebuild` after changing the Dockerfile to rebuild the image
> and restart containers.

#### `docker/8.5/Dockerfile`

The recipe for the PHP 8.5 container. It is based on the official `php:8.5-cli`
image (Debian) and installs only what this project actually needs:

- PHP 8.5 with the extensions the app requires: `pdo_sqlite`, `pdo_mysql`, `mbstring`,
  `xml`, `zip`, `bcmath`, `intl`, `gd`, `pcntl`
- `pcov` for fast test coverage (Xdebug is available via `SAIL_XDEBUG_MODE`
  but not installed by default — keeping the image lean)
- Composer (the PHP package manager)
- Node 22 and npm (for building front-end assets with Vite)
- Supervisor (a process manager — explained below)

The stock Sail Dockerfile installs many extras this project does not use
(MongoDB, Redis, PostgreSQL, Swoole, Imagick, Playwright, Bun, Yarn…).
The trimmed-down version here builds significantly faster and is less likely to
fail due to a third-party package repository being temporarily unavailable.

#### `docker/8.5/php.ini`

A small PHP configuration file that applies inside the container. The defaults
shipped with Sail are kept as-is.

#### `docker/8.5/start-container`

The entrypoint script that runs when the container starts. It:

1. Sets the `sail` user's ID to match your host machine user (so file permissions
   work correctly)
2. Starts **Supervisord** (a process manager) which in turn starts the PHP server
   and the queue worker

#### `docker/8.5/supervisord.conf`

Supervisord is a process manager. Inside the container it runs two things at once:

1. **`php artisan serve`** — the Laravel development server
2. **`php artisan queue:listen --tries=1`** — the queue worker that processes
   background jobs (e.g. the bank statement CSV import). `queue:listen` (rather
   than `queue:work`) is used intentionally so that code changes are picked up
   immediately without needing to restart the worker. The `--tries=1` setting
   matches the `--tries=1` in the `composer dev` script this project previously used, for consistency.

Without Supervisord, a container can only run one process. Supervisord is the
standard solution for running multiple processes in one container during development.

---

### `Makefile` — shorthand commands

A `Makefile` is a file that defines shortcut commands you can run with `make`.
Instead of typing `./vendor/bin/sail composer test` every time, you type `make test`.

Run `make help` to see all available commands with descriptions.

Key commands:

| Command | What it runs internally |
|---|---|
| `make setup` | Copies `.env.example` → `.env` (if missing), installs PHP deps via a temporary `composer:2` Docker container, then `sail up --build`, `artisan key:generate`, `artisan migrate`, `artisan storage:link`, `npm install`, `npm run build` |
| `make up` | `sail up -d` — start containers (begin your work session) |
| `make down` | `sail down` — stop containers (end your work session) |
| `make restart` | `sail restart` — quick restart without rebuilding (e.g. after `.env` change) |
| `make shell` | `sail shell` — open a bash shell inside the app container |
| `make test` | Run PHPUnit test suite — pass `f=path/to/TestFile.php` to run a single file |
| `make lint` | Check code style (Pint dry-run) + static analysis (PHPStan) without modifying files |
| `make pint` | Auto-fix code style — pass `f=path/to/file.php` to target a specific file |
| `make phpstan` | Run PHPStan static analysis — pass `f=path/to/file.php` to target a specific file |
| `make rector` | Apply automated refactoring with Rector — pass `f=path/to/file.php` to target a specific file |
| `make infection` | Run full test suite for coverage, then mutate source files — pass `f=source_file.php` to target one file |
| `make migrate` | `sail artisan migrate` — run pending migrations (e.g. after pulling new code) |
| `make fresh` | ⚠️ Drop all tables, re-run all migrations + seeders |
| `make rebuild` | Tears down containers, rebuilds Docker images from scratch, and restarts — use after Dockerfile changes |
| `make reset` | ⚠️ Destroys all containers and volumes (DB data lost), then re-runs `make setup` from scratch |
| `make npm-dev` | `sail npm run dev` — start Vite hot-reloading dev server |
| `make npm-build` | `sail npm run build` — build optimised frontend assets for production |
| `make logs` | `sail artisan pail` — tail live application logs (Ctrl+C to stop) |
| `make artisan cmd="..."` | Run any Artisan command inside the container, e.g. `make artisan cmd="route:list"` |
| `make composer cmd="..."` | Run any Composer command inside the container, e.g. `make composer cmd="require vendor/package"` |

The Makefile has a **`docker-check` preflight step** on `setup`, `up`, `rebuild`,
and `reset` that runs `docker info` first. If Docker Desktop is not running it
prints a clear error message.

---

### `.env.example` — new environment variables for Sail

The `.env.example` file is a template that developers copy to `.env`. Several
Sail-specific variables were added:

| Variable | Default | What it does |
|---|---|---|
| `APP_PORT` | `8080` | Which port on your machine maps to port 80 in the container |
| `APP_SERVICE` | `laravel.test` | The name of the main service in `compose.yaml` — Sail uses this to know which container to run commands in |
| `COMPOSE_PROJECT_NAME` | `finance-tracker` | Gives Docker containers a consistent name prefix across all developers |
| `VITE_PORT` | `5173` | The port the Vite dev server listens on inside the container |
| `WWWUSER` | `1000` | Maps the container's `sail` user to your host user ID for correct file ownership |
| `WWWGROUP` | `1000` | Maps the container's `sail` group to your host group ID |
| `SAIL_XDEBUG_MODE` | `off` | Set to `develop,debug` to enable Xdebug for step-debugging in your IDE |
| `SAIL_XDEBUG_CONFIG` | `client_host=host.docker.internal` | Tells Xdebug where to connect back to (your IDE on the host machine) |
| `MAIL_MAILER` | `smtp` | In Sail, mail is sent to Mailpit via SMTP |
| `MAIL_HOST` | `mailpit` | Sail resolves this to the Mailpit container automatically |
| `MAIL_PORT` | `1025` | The port Mailpit listens on for incoming mail |
| `FORWARD_MAILPIT_PORT` | `1025` | The port exposed to your host machine for SMTP |
| `FORWARD_MAILPIT_DASHBOARD_PORT` | `8025` | The port to view caught emails in the browser |

These variables are used by Docker Compose and Sail. They have no effect outside the container.

---

### `vite.config.js` — bind Vite to all interfaces

Three lines were added to the `server` config block:

```js
server: {
    host: '0.0.0.0',              // ← new
    port: parseInt(process.env.VITE_PORT ?? 5173),  // ← new
    hmr: {
        host: 'localhost',        // ← new
    },
    cors: true,
},
```

**Why?** By default, Vite only listens on `127.0.0.1` (localhost *inside* the
container). That address is not reachable from outside the container — so your
browser on the host machine would not be able to connect to it, and hot-reloading
would not work.

Setting `host: '0.0.0.0'` makes Vite listen on all network interfaces inside the
container, including the one Docker exposes to your host machine. The `hmr.host`
setting tells the browser to connect HMR back to `localhost` (your machine), which
Docker Desktop routes into the container correctly.

---

### How hot-reloading works

There are two independent mechanisms for seeing your changes without rebuilding the container:

**PHP / Blade / Livewire changes** are visible immediately on the next browser refresh. This works because your entire project folder is mounted directly into the container — the PHP process reads your files from your host machine in real time. No Vite, no rebuild needed.

**CSS / JavaScript / Tailwind changes** require an extra step. These files are compiled into bundles (`public/build/`). `make setup` produces a one-off bundle, which is enough to use the app. If you want changes to CSS or JS to appear in the browser *instantly* (without even refreshing the page), run `make npm-dev` in a second terminal — this starts the Vite dev server, which watches those files and injects changes live.

In short: if you're writing PHP, just refresh the browser. Only start `make npm-dev` if you're actively working on CSS or JavaScript.

---

### `phpunit.xml` — use a dedicated MySQL test database

PHPUnit is configured to run against a separate `finance_tracker_testing` database on
the same MySQL container, keeping test data isolated from the development database.

```xml
<env name="DB_CONNECTION" value="mysql"/>
<env name="DB_HOST" value="mysql"/>
<env name="DB_PORT" value="3306"/>
<env name="DB_DATABASE" value="finance_tracker_testing"/>
<env name="DB_USERNAME" value="sail"/>
<env name="DB_PASSWORD" value="password"/>
```

The `finance_tracker_testing` database is created automatically by the MySQL init
script at `docker/mysql/create-testing-db.sql` when the container first starts.
You do not need to create or manage it manually.

`RefreshDatabase` wraps each test in a transaction that is rolled back after the
test completes, so tests are fast and isolated without re-running migrations on
every test.

---

## How it all fits together

When you run `make setup` for the first time, here is what happens step by step:

1. **`.env` copy** — if no `.env` file exists yet, it is copied from `.env.example`
   so the app has working defaults.

2. **PHP dependency bootstrap** — runs `composer install` inside a temporary
   `composer:2` Docker container that is immediately discarded after use. This
   downloads Laravel Sail (among other packages) into `vendor/`, which is needed
   before the Sail command is available. No host PHP or Composer is required.

3. **`sail up -d --build`** — Docker reads `compose.yaml`, builds the PHP 8.5 image
   from `docker/8.5/Dockerfile` (this takes a few minutes the first time), and
   starts three containers: `laravel.test`, `mysql`, and `mailpit`. The MySQL
   container also runs its SQL init script to create the `finance_tracker_testing`
   database for the test suite. The `-d` flag runs them in the background so your
   terminal is free.

4. **`sail artisan key:generate`** — runs `php artisan key:generate` *inside* the
   `laravel.test` container. This writes `APP_KEY` into your `.env` file.

5. **`sail artisan migrate`** — runs inside the container, connects to the MySQL
   service, and runs all migrations against the `finance_tracker` database.

6. **`sail artisan storage:link`** — creates the `public/storage` → `storage/app/public`
   symlink so publicly accessible file uploads work correctly.

7. **`sail npm install`** — runs `npm install` inside the container, downloading
   front-end dependencies into `node_modules/`.

8. **`sail npm run build`** — compiles front-end assets (CSS, JS) into `public/build/`.
   This one-off production build is what makes the app load correctly when visiting
   the URL after setup, since `public/build/` is gitignored and must be generated
   locally.

After setup, **only `make up`** is needed to start the environment on subsequent
days. Containers start in seconds once the image is already built.

---

## Frequently Asked Questions

**Do I need to rebuild the image after changing PHP code?**

No. Your project folder is mounted directly into the container (the
`.:/var/www/html` line in `compose.yaml`). PHP sees your changes immediately,
just like running `php artisan serve` locally.

**When do I need to rebuild the image?**

Only when `docker/8.5/Dockerfile` changes — for example if a new PHP extension
needs to be added. Use `make rebuild` to tear down, rebuild from scratch, and
restart all containers.

**Where do I see emails sent by the app?**

Open `http://localhost:8025` in your browser while the containers are running.
Mailpit shows all emails the app has sent.

**How do I run a one-off Artisan command?**

```bash
make artisan cmd="route:list"
make artisan cmd="tinker"
```

Or open a shell inside the container and type freely:

```bash
make shell
# now inside the container:
php artisan ...
```

**How do I stop the containers without losing data?**

```bash
make down
```

This stops and removes the containers, but your code and `vendor/` folder are on
your host machine via the bind mount, and your MySQL data is stored in the
`sail-mysql` named Docker volume — so nothing is lost. Next time you run `make up`,
everything picks up where it left off.

**How do I completely reset the environment (wipe the database)?**

```bash
make reset
```

This runs `sail down -v` (which removes containers and the named MySQL volume) and
then re-runs `make setup` from scratch. Use this if migrations are in an inconsistent
state or you want a clean slate.

---

## Troubleshooting

**`make setup` fails with "permission denied" on the Composer Docker step**

On Linux, the `composer:2` container runs as your host user (`id -u`/`id -g`). If
your user ID is `0` (root), drop the `-u` flag or run as a non-root user.

**The app container exits immediately after `sail up`**

Check that `.env` exists and `APP_KEY` is set. If you skipped `make setup`, run:
```bash
make artisan cmd="key:generate"
make migrate
```

**MySQL container stays unhealthy and the app never starts**

The app container waits for MySQL to pass its health check before starting. Common
causes:
- Another process is already using port 3306 on your host — change `FORWARD_DB_PORT`
  in `.env` to a free port.
- The `sail-mysql` volume contains data from an incompatible MySQL version — run
  `make reset` to wipe it.

**Port 8080 is already in use**

Change `APP_PORT` in `.env` to a free port (e.g. `APP_PORT=8081`), then `make down && make up`.

**`vendor/bin/sail: No such file or directory`**

The `vendor/` directory is missing. Run the bootstrap step manually:
```bash
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/app" \
    -w /app \
    -e HOME=/tmp \
    composer:2 install --no-interaction --prefer-dist --no-progress --no-scripts --ignore-platform-reqs
```
Then continue with `make setup`.
