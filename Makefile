SHELL := /bin/sh

DOCKER ?= docker
DOCKER_COMPOSE ?= $(DOCKER) compose
export DOCKER_USER ?= $(shell id -u):$(shell id -g)

RUN ?= $(if $(IN_CONTAINER),,$(DOCKER_COMPOSE) run --rm php)
COMPOSER ?= $(RUN) composer

# Container

t: terminal
terminal: var ## (t) Open a shell in the PHP container
	$(RUN) /bin/sh
.PHONY: t terminal

run: ## Run a command in the PHP container: CMD='php --version'
	$(RUN) $(CMD)
.PHONY: run

up: ## Start services
	$(DOCKER_COMPOSE) up --remove-orphans --build --detach
.PHONY: up

down: ## Stop services
	$(DOCKER_COMPOSE) down --remove-orphans
.PHONY: down

# Dependencies

i: install
install: ## (i) Install dependencies
	$(COMPOSER) install
	@rm -f vendor/.lowest
	@touch vendor
.PHONY: i install

u: update
update: ## (u) Update dependencies
	$(COMPOSER) update
	@rm -f vendor/.lowest
	@touch vendor
.PHONY: u update

install-lowest: ## Install lowest possible dependencies
	$(COMPOSER) update --prefer-lowest --prefer-stable
	@touch vendor/.lowest
	@touch vendor
.PHONY: install-lowest

# Quality tools

fixer: var ## Fix code style
	$(RUN) php-cs-fixer fix --diff --verbose $(ARGS)
.PHONY: fixer

fixer-check: var ## Check code style
	$(RUN) php-cs-fixer fix --diff --verbose --dry-run $(ARGS)
.PHONY: fixer-check

rector: var vendor ## Apply Rector rules
	$(RUN) rector process $(ARGS)
.PHONY: rector

rector-check: var vendor ## Check Rector rules
	$(RUN) rector process --dry-run $(ARGS)
.PHONY: rector-check

phpstan: var vendor ## Run static analysis
	$(RUN) phpstan analyze --memory-limit=1G $(ARGS)
.PHONY: phpstan

test: var vendor up ## Run the test suite
	$(RUN) vendor/bin/testo $(ARGS)
.PHONY: test

infect: var vendor up ## Run mutation testing
	$(RUN) infection --show-mutations $(ARGS)
.PHONY: infect

deps-analyze: vendor ## Check for unused/missing dependencies
	$(RUN) composer-dependency-analyser $(ARGS)
.PHONY: deps-analyze

composer-validate: ## Validate composer.json
	$(COMPOSER) validate
.PHONY: composer-validate

composer-normalize: ## Normalize composer.json
	$(COMPOSER) normalize --no-check-lock --no-update-lock --diff
.PHONY: composer-normalize

composer-normalize-check: ## Check composer.json is normalized
	$(COMPOSER) normalize --diff --dry-run
.PHONY: composer-normalize-check

# CI

fix: fixer rector composer-normalize ## Fix code style and normalize composer.json
.PHONY: fix

check: fixer-check rector-check composer-validate composer-normalize-check deps-analyze phpstan test ## Run all checks
.PHONY: check

rescaffold:
	$(DOCKER) run \
	  --rm --init \
	  --interactive --tty \
	  --user $(DOCKER_USER) \
	  --volume .:/project \
	  --pull always \
	  ghcr.io/phpyh/scaffolder:latest \
	  --user-name-default '$(shell git config user.name 2>/dev/null || whoami 2>/dev/null)' \
	  --user-email-default '$(shell git config user.email 2>/dev/null)'
	git add --all 2>/dev/null || true
.PHONY: rescaffold

# ---

var:
	mkdir -p var

vendor: composer.json $(wildcard composer.lock)
	@if [ -f vendor/.lowest ]; then $(MAKE) install-lowest; else $(MAKE) install; fi

help:
	@awk 'BEGIN {FS=":.*?## "} /^# [A-Za-z]/ {printf "%s\033[1;33m[%s]\033[0m\n", (s ? "\n" : ""), substr($$0, 3); s=1} /^[a-zA-Z0-9_-]+:.*## / {printf "\033[32m%-28s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
.PHONY: help

.DEFAULT_GOAL := help
