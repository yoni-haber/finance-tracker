SAIL = ./vendor/bin/sail

.PHONY: help setup up down restart shell artisan test pint phpstan \
        migrate fresh logs build rebuild npm-dev npm-build docker-check

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

docker-check:
	@docker info > /dev/null 2>&1 || (echo "\n❌  Docker is not running. Please start Docker Desktop and try again.\n" && exit 1)

setup: docker-check ## First-time project setup (build image, run migrations, install deps)
	@command -v composer >/dev/null 2>&1 || (echo "\n❌  Composer is not installed on the host. See README for bootstrap instructions.\n" && exit 1)
	composer install
	@test -f .env || cp .env.example .env
	$(SAIL) up -d --build
	$(SAIL) artisan key:generate
	$(SAIL) artisan migrate
	$(SAIL) artisan storage:link
	$(SAIL) npm install
	@echo "\n✅  Setup complete — visit http://localhost:$${APP_PORT:-8080}"

up: docker-check ## Start all containers in the background
	$(SAIL) up -d

down: ## Stop all containers
	$(SAIL) down

restart: ## Restart all containers
	$(SAIL) restart

shell: ## Open a Bash shell in the app container
	$(SAIL) shell

artisan: ## Run an Artisan command, e.g. make artisan cmd="route:list"
	$(SAIL) artisan $(cmd)

test: ## Run the full PHPUnit test suite
	$(SAIL) composer test

pint: ## Fix code style with Laravel Pint
	$(SAIL) exec laravel.test vendor/bin/pint

phpstan: ## Run PHPStan static analysis
	$(SAIL) exec laravel.test vendor/bin/phpstan

migrate: ## Run outstanding database migrations
	$(SAIL) artisan migrate

fresh: ## Wipe the database and re-run all migrations
	$(SAIL) artisan migrate:fresh --seed

logs: ## Tail Laravel application logs via Pail
	$(SAIL) artisan pail --timeout=0

build: ## Rebuild the Sail Docker image (after Dockerfile changes)
	$(SAIL) build --no-cache

rebuild: docker-check ## Rebuild containers from scratch and open a shell in the app container
	$(SAIL) down
	$(SAIL) build --no-cache
	$(SAIL) up -d
	$(SAIL) shell

npm-dev: ## Start the Vite dev server (HMR)
	$(SAIL) npm run dev

npm-build: ## Build frontend assets for production
	$(SAIL) npm run build
