# Local Development with Docker and Laravel Sail

---

## Why Docker?

Before this change, running the project locally meant installing and managing several
tools directly on your machine:

- PHP 8.4 (exact version)
- Composer
- Node 22 (exact version)
- npm
- SQLite

If you worked on multiple projects with different PHP versions, you would need a version
manager like `asdf` or `phpenv` to switch between them. If a teammate used a slightly
different version, things could break in subtle ways.

**Docker solves this by packaging the entire environment — the right PHP version,
all the right system libraries, the queue worker — into containers that run the same
way on every machine.** Once Docker Desktop is installed and running, everything else
is handled for you.

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

1. A Docker image for PHP 8.4 — this project builds a custom Debian-based image
   (see `docker/8.4/Dockerfile`) rather than using Sail's stock Ubuntu image, but
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
context to `./docker/8.4` (not the project root), so this root `.dockerignore` does
**not** affect the image built by `make build` or `make setup`. The `./docker/8.4/`
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

- **Builds from `docker/8.4/Dockerfile`** — the PHP 8.4 image with all required
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

### `docker/8.4/` — the Sail PHP image

These files define the Docker image that Sail builds and runs.

#### `docker/8.4/Dockerfile`

The recipe for the PHP 8.4 container. It is based on the official `php:8.4-cli`
image (Debian) and installs only what this project actually needs:

- PHP 8.4 with the extensions the app requires: `pdo_sqlite`, `mbstring`,
  `xml`, `zip`, `bcmath`, `intl`, `gd`, `pcntl`
- `pcov` for fast test coverage (Xdebug is available via `SAIL_XDEBUG_MODE`
  but not installed by default — keeping the image lean)
- Composer (the PHP package manager)
- Node 22 and npm (for building front-end assets with Vite)
- Supervisor (a process manager — explained below)

The stock Sail Dockerfile installs many extras this project does not use
(MongoDB, Redis, PostgreSQL, MySQL, Swoole, Imagick, Playwright, Bun, Yarn…).
The trimmed-down version here builds significantly faster and is less likely to
fail due to a third-party package repository being temporarily unavailable.

#### `docker/8.4/php.ini`

A small PHP configuration file that applies inside the container. The defaults
shipped with Sail are kept as-is.

#### `docker/8.4/start-container`

The entrypoint script that runs when the container starts. It:

1. Sets the `sail` user's ID to match your host machine user (so file permissions
   work correctly)
2. Starts **Supervisord** (a process manager) which in turn starts the PHP server
   and the queue worker

#### `docker/8.4/supervisord.conf`

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
| `make setup` | Checks for Composer, `composer install`, copies `.env.example` → `.env` (if missing), then `sail up --build`, `artisan key:generate`, `artisan migrate`, `artisan storage:link`, `npm install`, `npm run build` |
| `make up` | `sail up -d` (start containers in the background) |
| `make down` | `sail down` (stop containers) |
| `make shell` | `sail shell` (open a bash shell inside the app container) |
| `make test` | `sail composer test` (run PHPUnit) |
| `make migrate` | `sail artisan migrate` |
| `make npm-dev` | `sail npm run dev` (start Vite hot-reloading) |
| `make logs` | `sail artisan pail` (tail log output) |
| `make build` | Rebuilds the Docker image without layer cache (`sail build --no-cache`) |
| `make rebuild` | Tears everything down, rebuilds from scratch, starts back up, and opens a shell — use this after Dockerfile changes when you want to verify the new image immediately |

The Makefile also has a **`docker-check` preflight step** on `setup` and `up` that
runs `docker info` first. If Docker Desktop is not running it prints a clear error
message. The `setup` target additionally checks that Composer is available on the
host before proceeding.

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

### `phpunit.xml` — use a file-based test database

One line changed:

```xml
<!-- Before -->
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>

<!-- After -->
<env name="DB_DATABASE" value="testing"/>
```

The original `:memory:` SQLite setting was replaced with a file-based database
named `testing` (written to the project root at runtime). The reason is that
SQLite in-memory databases do not survive across separate database connections
— if any part of the test infrastructure opens a second connection (which Sail's
environment can trigger), it receives a blank database and tests fail silently.
Using a file-based database avoids this by making the schema visible to all
connections.

The `testing` file is generated automatically when you run the test suite and is
listed in `.gitignore` — you do not need to create or manage it manually.

---

## How it all fits together

When you run `make setup` for the first time, here is what happens step by step:

1. **Composer check** — verifies that Composer is available on the host. If not,
   it prints a clear error and stops.

2. **`composer install`** — runs on your host machine (the only step that does).
   This downloads Laravel Sail (among other packages) into `vendor/`, which is
   needed before Docker can take over.

3. **`.env` copy** — if no `.env` file exists yet, it is copied from `.env.example`
   so the app has working defaults.

4. **`sail up -d --build`** — Docker reads `compose.yaml`, builds the PHP 8.4 image
   from `docker/8.4/Dockerfile` (this takes a few minutes the first time), and
   starts two containers: `laravel.test` and `mailpit`. The `-d` flag runs them in
   the background so your terminal is free.

5. **`sail artisan key:generate`** — runs `php artisan key:generate` *inside* the
   `laravel.test` container. This writes `APP_KEY` into your `.env` file.

6. **`sail artisan migrate`** — runs inside the container, creates the SQLite
   database file at `database/database.sqlite`, and runs all migrations.

7. **`sail artisan storage:link`** — creates the `public/storage` → `storage/app/public`
   symlink so publicly accessible file uploads work correctly.

8. **`sail npm install`** — runs `npm install` inside the container, downloading
   front-end dependencies into `node_modules/`.

9. **`sail npm run build`** — compiles front-end assets (CSS, JS) into `public/build/`.
   This one-off production build is what makes the app load correctly when visiting
   the URL after setup, since `public/build/` is gitignored and must be generated
   locally.

After setup, **only `make up`** is needed to start the environment on subsequent
days. Containers start in seconds once the image is already built.

---

## Frequently Asked Questions

**Why is there a `testing` file in the project root?**

That file is the SQLite database used by PHPUnit when you run `make test` or
`composer test`. It is created automatically by the test suite and is listed in
`.gitignore` — you can safely ignore it. If you delete it, it will be recreated
the next time tests run.

**Do I need to rebuild the image after changing PHP code?**

No. Your project folder is mounted directly into the container (the
`.:/var/www/html` line in `compose.yaml`). PHP sees your changes immediately,
just like running `php artisan serve` locally.

**When do I need to rebuild the image?**

Only when `docker/8.4/Dockerfile` changes — for example if a new PHP extension
needs to be added. Use `make rebuild` to tear down, rebuild from scratch, and
drop into a shell so you can verify the result. Use `make build` if you just want
to rebuild without restarting or opening a shell.

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

This stops and removes the containers, but your code, database file
(`database/database.sqlite`), and `vendor/` folder are all on your host machine
via the bind mount, so nothing is lost. Next time you run `make up`, everything
picks up where it left off.
