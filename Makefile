# IncidentFlow — common tasks.
#
# `make help` lists everything. Targets are thin wrappers around the commands
# they run, on purpose: nobody should have to read this file to know what a
# target does to their machine.

.DEFAULT_GOAL := help
.PHONY: help up down logs shell test test-api test-realtime test-web lint build \
        migrate seed fresh keys e2e prune clean

COMPOSE      := docker compose
COMPOSE_PROD := docker compose -f docker-compose.yml -f docker-compose.prod.yml

help: ## Show this help
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

## ---------------------------------------------------------------- development

up: ## Build and start the whole stack on http://localhost:8080
	$(COMPOSE) up --build

down: ## Stop everything and remove the volumes
	$(COMPOSE) down -v

logs: ## Tail every service's logs
	$(COMPOSE) logs -f --tail 100

shell: ## Open a shell in the API container
	$(COMPOSE) exec api sh

## ---------------------------------------------------------------------- tests

test: test-api test-realtime test-web ## Run every suite

test-api: ## API: 77 tests against in-memory SQLite
	cd api && php vendor/bin/phpunit

test-realtime: ## Realtime: hub and fan-out logic
	cd realtime && npm test

test-web: ## Web: unit tests
	cd web && npm test

e2e: ## End-to-end against the running stack (needs `make up` first)
	cd web && npm run e2e

lint: ## Formatting and static checks across all three projects
	cd api && php vendor/bin/pint --test
	cd realtime && npm run lint && npm run typecheck
	cd web && npm run lint && npm run typecheck

build: ## Production build of every image
	$(COMPOSE_PROD) build

## ----------------------------------------------------------------- database

migrate: ## Apply pending migrations
	$(COMPOSE) exec api php artisan migrate --force

seed: ## Load the demo organization and 90 days of incident history
	$(COMPOSE) exec api php artisan db:seed --force

fresh: ## Drop everything and rebuild the schema with demo data
	$(COMPOSE) exec api php artisan migrate:fresh --seed --force

prune: ## Report what the housekeeping job would delete
	$(COMPOSE) exec api php artisan incidentflow:prune --dry-run

## ------------------------------------------------------------------ security

keys: ## Generate the RS256 pair. Signs every session out immediately.
	$(COMPOSE) exec api php artisan jwt:keys --force
	$(COMPOSE) restart api realtime horizon scheduler

clean: ## Remove build output and dependencies
	rm -rf api/vendor realtime/node_modules realtime/dist web/node_modules web/dist
