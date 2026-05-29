SAIL = ./vendor/bin/sail
APP_SERVICE := $(shell [ -f .env ] && grep -E '^APP_SERVICE=' .env 2>/dev/null | head -n1 | cut -d'=' -f2- || echo laravel.test)

.PHONY: help setup up down restart shell artisan composer test pint phpstan rector \
        migrate fresh logs build rebuild reset npm-dev npm-build docker-check

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

docker-check:
	@docker info > /dev/null 2>&1 || (echo "\nDocker is not running. Please start Docker and try again.\n" && exit 1)

setup: docker-check ## First-time project setup — only Docker required, no host PHP/Composer needed
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

composer: ## Run a Composer command, e.g. make composer cmd="require vendor/package"
	$(SAIL) composer $(cmd)

test: ## Run the full PHPUnit test suite
	$(SAIL) composer test

pint: ## Fix code style with Laravel Pint
	$(SAIL) exec $(APP_SERVICE) vendor/bin/pint

phpstan: ## Run PHPStan static analysis
	$(SAIL) exec $(APP_SERVICE) vendor/bin/phpstan --memory-limit=1G

rector: ## Apply automated refactoring with Rector
	$(SAIL) exec $(APP_SERVICE) vendor/bin/rector

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

reset: docker-check ## Destroy all containers and volumes, then run setup from scratch
	$(SAIL) down -v
	make setup

npm-dev: ## Start the Vite dev server (HMR)
	$(SAIL) npm run dev

npm-build: ## Build frontend assets for production
	$(SAIL) npm run build
