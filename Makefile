SAIL = ./vendor/bin/sail
APP_SERVICE := $(shell [ -f .env ] && grep -E '^APP_SERVICE=' .env 2>/dev/null | head -n1 | cut -d'=' -f2- || echo laravel.test)

.PHONY: help setup up down restart shell artisan composer test pint phpstan rector \
        migrate fresh logs rebuild reset npm-dev npm-build docker-check infection lint

help: ## Show available commands
	@printf '\n\033[1mUsage:\033[0m make <command> [args]\n'
	@printf '\n\033[1;33m  Setup & Infrastructure\033[0m\n'
	@printf '  \033[36m%-18s\033[0m %s\n' "setup" "First-time project setup after cloning (run once)"
	@printf '  \033[36m%-18s\033[0m %s\n' "rebuild" "Rebuild Docker images & restart — after Dockerfile changes"
	@printf '  \033[36m%-18s\033[0m %s\n' "reset" "⚠️ Nuke everything (DB data included), re-run full setup"
	@printf '\n\033[1;33m  Containers\033[0m\n'
	@printf '  \033[36m%-18s\033[0m %s\n' "up" "Start all containers — run this to begin your day"
	@printf '  \033[36m%-18s\033[0m %s\n' "down" "Stop all containers — run this when you are done"
	@printf '  \033[36m%-18s\033[0m %s\n' "restart" "Quick restart without rebuilding (e.g. after .env change)"
	@printf '  \033[36m%-18s\033[0m %s\n' "shell" "Open a bash shell inside the app container"
	@printf '\n\033[1;33m  Code Quality\033[0m       Pass \033[33mf=<path>\033[0m to target a specific file or dir\n'
	@printf '  \033[36m%-18s\033[0m %s\n' "lint" "Check style (Pint dry-run) + static analysis (PHPStan)"
	@printf '  \033[36m%-18s\033[0m %s\n' "pint" "Auto-fix code style with Laravel Pint"
	@printf '  \033[36m%-18s\033[0m %s\n' "phpstan" "Run PHPStan static analysis"
	@printf '  \033[36m%-18s\033[0m %s\n' "rector" "Apply automated refactoring with Rector"
	@printf '  \033[36m%-18s\033[0m %s\n' "infection" "Run mutation tests (runs full suite first for coverage)"
	@printf '  \033[36m%-18s\033[0m %s\n' "test" "Run PHPUnit tests"
	@printf '\n\033[1;33m  Database\033[0m\n'
	@printf '  \033[36m%-18s\033[0m %s\n' "migrate" "Run pending migrations — e.g. after pulling new code"
	@printf '  \033[36m%-18s\033[0m %s\n' "fresh" "⚠️ Drop all tables, re-run all migrations + seeders"
	@printf '\n\033[1;33m  Laravel\033[0m\n'
	@printf '  \033[36m%-18s\033[0m %s\n' "artisan" "Run any artisan command"
	@printf '  \033[36m%-18s\033[0m %s\n' "composer" "Run any composer command"
	@printf '  \033[36m%-18s\033[0m %s\n' "logs" "Tail live application logs via Pail (Ctrl+C to stop)"
	@printf '\n\033[1;33m  Frontend\033[0m\n'
	@printf '  \033[36m%-18s\033[0m %s\n' "npm-dev" "Start Vite dev server with hot-reload"
	@printf '  \033[36m%-18s\033[0m %s\n' "npm-build" "Build frontend assets for production"
	@printf '\n\033[1;33m  Examples:\033[0m\n'
	@printf '    make pint f=app/Models/User.php         \033[2mFix style in one file\033[0m\n'
	@printf '    make phpstan f=app/Services/            \033[2mAnalyse a directory\033[0m\n'
	@printf '    make infection f=UserService.php        \033[2mMutate one source file\033[0m\n'
	@printf '    make test f=tests/Unit/MyTest.php       \033[2mRun a single test file\033[0m\n'
	@printf '    make artisan cmd="make:model Post -m"   \033[2mCreate model with migration\033[0m\n'
	@printf '\n'

docker-check:
	@docker info > /dev/null 2>&1 || (echo "\nDocker is not running. Please start Docker and try again.\n" && exit 1)

setup: docker-check ## First-time setup after cloning (run once) — only Docker required
	@test -f .env || cp .env.example .env
	@echo "→ Installing PHP dependencies inside a temporary Docker container…"
	@docker run --rm \
		-u "$$(id -u):$$(id -g)" \
		-v "$(CURDIR):/app" \
		-w /app \
		-e HOME=/tmp \
		composer:2 install --no-interaction --prefer-dist --no-progress --no-scripts --ignore-platform-reqs
	$(SAIL) up -d --build
	$(SAIL) artisan key:generate
	$(SAIL) artisan migrate
	$(SAIL) artisan storage:link
	$(SAIL) npm install
	$(SAIL) npm run build
	@echo "\n✅  Setup complete — visit http://localhost:$${APP_PORT:-8080}"

up: docker-check ## Start all containers — begin your work session
	$(SAIL) up -d

down: ## Stop all containers — end your work session
	$(SAIL) down

restart: ## Quick restart without rebuilding (e.g. after .env change)
	$(SAIL) restart

shell: ## Open a Bash shell in the app container
	$(SAIL) shell

artisan: ## Run an Artisan command, e.g. make artisan cmd="route:list"
	$(SAIL) artisan $(cmd)

composer: ## Run a Composer command, e.g. make composer cmd="require vendor/package"
	$(SAIL) composer $(cmd)

test: ## Run PHPUnit tests (f=path/to/TestFile.php for a single file)
	@if [ -n "$(f)" ]; then \
		$(SAIL) exec $(APP_SERVICE) vendor/bin/phpunit $(f); \
	else \
		$(SAIL) composer test; \
	fi

pint: ## Fix code style with Laravel Pint (f=path/to/file.php)
	$(SAIL) exec $(APP_SERVICE) vendor/bin/pint $(f)

phpstan: ## Run PHPStan static analysis (f=path/to/file.php)
	$(SAIL) exec $(APP_SERVICE) vendor/bin/phpstan analyse --memory-limit=1G $(f)

rector: ## Apply automated refactoring with Rector (f=path/to/file.php)
	$(SAIL) exec $(APP_SERVICE) vendor/bin/rector $(f)

infection: ## Run mutation tests — runs full test suite first for coverage, then mutates (f=source file)
	$(SAIL) exec $(APP_SERVICE) vendor/bin/infection --threads=1 $(if $(f),--filter=$(f),)

lint: ## Run Pint (dry-run) + PHPStan together (f=path/to/file.php)
	$(SAIL) exec $(APP_SERVICE) vendor/bin/pint --test $(f)
	$(SAIL) exec $(APP_SERVICE) vendor/bin/phpstan analyse --memory-limit=1G $(f)

migrate: ## Run pending migrations — e.g. after pulling new code
	$(SAIL) artisan migrate

fresh: ## ⚠️ Drop all tables, re-run all migrations + seeders
	$(SAIL) artisan migrate:fresh --seed

logs: ## Tail live application logs via Pail (Ctrl+C to stop)
	$(SAIL) artisan pail --timeout=0

rebuild: docker-check ## Rebuild Docker images & restart — after Dockerfile changes
	$(SAIL) down
	$(SAIL) build --no-cache
	$(SAIL) up -d

reset: docker-check ## ⚠️ Nuke everything (DB data included), re-run full setup
	$(SAIL) down -v
	make setup

npm-dev: ## Start the Vite dev server (HMR)
	$(SAIL) npm run dev

npm-build: ## Build frontend assets for production
	$(SAIL) npm run build
