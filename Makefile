SHELL := /bin/sh

DOCKER_COMPOSE ?= docker compose
export DOCKER_USER ?= $(shell id -u):$(shell id -g)
RUN ?= $(if $(IN_CONTAINER),,$(DOCKER_COMPOSE) run --rm php)
COMPOSER ?= $(RUN) composer

# (P=<name> narrows the quality tools and the tests to packages/<name>)
P ?=
PATHS = $(if $(P),packages/$(P),bin/monorepo monorepo.php tools packages)

# Container

t: terminal
terminal: var ## (t) Open a shell in the PHP container
	$(RUN) /bin/sh
.PHONY: t terminal

up: ## Start services
	$(DOCKER_COMPOSE) up --remove-orphans --detach --wait
.PHONY: up

down: ## Stop services
	$(DOCKER_COMPOSE) down --remove-orphans
.PHONY: down

# Dependencies

i: install
install: ## (i) Install dependencies
	$(COMPOSER) install
	@touch vendor
.PHONY: i install

u: update
update: ## (u) Update dependencies
	$(COMPOSER) update
	@touch vendor
.PHONY: u update

# Monorepo

sync: vendor ## Regenerate files derived from the manifests, then update the lock
	$(RUN) bin/monorepo sync
	$(COMPOSER) normalize --no-check-lock --no-update-lock
	$(COMPOSER) update --minimal-changes
	@touch vendor
.PHONY: sync

new-package: vendor ## Scaffold a package: NAME=byte-buffer LAYER=primitive DESC='Growable byte buffer'
	$(RUN) bin/monorepo new '$(NAME)' '$(LAYER)' '$(DESC)'
	$(COMPOSER) normalize --no-check-lock --no-update-lock
	$(COMPOSER) update --minimal-changes
	@touch vendor
.PHONY: new-package

monorepo-check: vendor ## Validate manifests, layers and dependencies, fail if a generated file is stale
	$(RUN) bin/monorepo check
	$(COMPOSER) validate --strict
	$(COMPOSER) normalize --dry-run --diff
.PHONY: monorepo-check

# Quality

fixer: var ## Fix code style [P=]
	$(RUN) php-cs-fixer fix --config=tools/php-cs-fixer.php --path-mode=intersection --diff $(ARGS) $(PATHS)
.PHONY: fixer

fixer-check: var ## Check code style [P=]
	$(RUN) php-cs-fixer fix --config=tools/php-cs-fixer.php --path-mode=intersection --diff --dry-run $(ARGS) $(PATHS)
.PHONY: fixer-check

rector: var vendor ## Apply Rector rules [P=]
	$(RUN) rector process --config=tools/rector.php $(ARGS) $(PATHS)
.PHONY: rector

rector-check: var vendor ## Check Rector rules [P=]
	$(RUN) rector process --config=tools/rector.php --dry-run $(ARGS) $(PATHS)
.PHONY: rector-check

phpstan: var vendor ## Run static analysis [P=]
	$(RUN) phpstan analyze --configuration=tools/phpstan.neon --memory-limit=1G $(ARGS) $(PATHS)
.PHONY: phpstan

deps-analyze: vendor ## Check for unused and shadow dependencies
	$(RUN) composer-dependency-analyser $(ARGS)
.PHONY: deps-analyze

test: var vendor ## Run tests [P=]
	$(RUN) vendor/bin/testo $(if $(P),--suite=$(P)) $(ARGS)
.PHONY: test

infect: var vendor ## Run mutation testing [P=]
	$(RUN) infection --configuration=tools/infection.json5 --show-mutations $(if $(P),--filter=packages/$(P)/src --test-framework-options=--suite=$(P)) $(ARGS)
.PHONY: infect

# CI

fix: fixer rector ## Fix code style and apply Rector rules [P=]
.PHONY: fix

check: monorepo-check fixer-check rector-check phpstan deps-analyze test ## Run all checks
.PHONY: check

# ---

var:
	mkdir -p var

vendor: composer.json composer.lock
	$(MAKE) install

help:
	@awk 'BEGIN {FS=":.*?## "} /^# [A-Za-z]/ {printf "%s\033[1;33m[%s]\033[0m\n", (s ? "\n" : ""), substr($$0, 3); s=1} /^[a-zA-Z0-9_-]+:.*## / {printf "\033[32m%-20s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
.PHONY: help

.DEFAULT_GOAL := help
