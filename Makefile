# Every PHP / Composer / Artisan command runs inside the containers; nothing is needed on the host
# except Docker (with Compose) and make. Pass extra arguments with ARGS="...".

.DEFAULT_GOAL := help

# Build the PHP image with the host user's UID/GID so files created in the bind mount stay editable.
COMPOSE := HOST_UID=$(shell id -u) HOST_GID=$(shell id -g) docker compose
ARGS ?=

# Resolve a setting like Compose does: shell environment first, then .env, then a default
# (.env does not exist before the first `make up`).
env_value = $(or $($(1)),$(shell sed -n 's/^$(1)=//p' .env 2>/dev/null | tail -n 1 | tr -d '"\r'),$(2))

.PHONY: help up down restart build ps logs sh artisan composer test mysql

help: ## Show this help
	@awk 'BEGIN {FS = ":.*## "} /^[a-z-]+:.*## / {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
	@echo '  Arguments: make artisan ARGS="route:list", make test ARGS="--filter=Foo", make logs ARGS="app"'

up: ## Build and start the whole stack (first run bootstraps .env, vendor/, APP_KEY, migrations)
	$(COMPOSE) up -d --build --wait
	@echo ''
	@echo 'App:     http://localhost:$(call env_value,APP_PORT,8088)'
	@echo 'Adminer: http://localhost:$(call env_value,ADMINER_PORT,8089) (local development only)'
	@echo 'phpCacheAdmin: http://localhost:$(call env_value,PHPCACHEADMIN_PORT,8090) (local development only)'

down: ## Stop and remove the containers (data volumes are kept)
	$(COMPOSE) down

restart: down up ## Restart the whole stack

build: ## Rebuild the PHP image
	$(COMPOSE) build

ps: ## Show service status and health
	$(COMPOSE) ps

logs: ## Follow logs (all services, or ARGS="app nginx")
	$(COMPOSE) logs -f --tail=100 $(ARGS)

sh: ## Open a shell in the app container
	$(COMPOSE) exec app bash

artisan: ## Run an Artisan command, e.g. ARGS="migrate:status"
	$(COMPOSE) exec app php artisan $(ARGS)

composer: ## Run a Composer command, e.g. ARGS="show"
	$(COMPOSE) exec app composer $(ARGS)

test: ## Run the PHPUnit suite against the test database, e.g. ARGS="--filter=Foo"
	$(COMPOSE) exec app php artisan config:clear --quiet
	$(COMPOSE) exec app php artisan test $(ARGS)

mysql: ## Open a MySQL client on the development database
	$(COMPOSE) exec mysql sh -c 'MYSQL_PWD="$$MYSQL_PASSWORD" mysql -u "$$MYSQL_USER" "$$MYSQL_DATABASE"'
