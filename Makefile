SHELL := /bin/sh

DOCKER_COMPOSE ?= docker compose
export DOCKER_USER ?= $(shell id -u):$(shell id -g)
RUN ?= $(if $(IN_CONTAINER),,$(DOCKER_COMPOSE) run --rm php)
COMPOSER ?= $(RUN) composer

# (P=<name> narrows the quality tools and the tests to packages/<name>)
P ?=
PATHS = $(if $(P),packages/$(P))

# Container

t: terminal
terminal: var ## (t) Open a shell in the PHP container
	$(RUN) /bin/sh
.PHONY: t terminal

up: ## Start services
	$(if $(IN_CONTAINER),,$(DOCKER_COMPOSE) up --remove-orphans --detach --wait)
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

split: vendor ## Split packages/* of main into main of thesis-php/<name>, pushes only with SPLIT_REMOTE and GITHUB_TOKEN
	$(if $(IN_CONTAINER),,$(DOCKER_COMPOSE) run --rm -e SPLIT_REMOTE -e GITHUB_TOKEN php) bin/monorepo split
.PHONY: split

# (The release takes COMMIT through the environment, like the workflow. After --: a value that starts with -
# is not an option)
export COMMIT ?= HEAD
release: vendor ## Release the changelog headings at COMMIT without a tag; pushes only with SPLIT_REMOTE [COMMIT=]
	$(if $(IN_CONTAINER),,$(DOCKER_COMPOSE) run --rm -e SPLIT_REMOTE -e GITHUB_TOKEN php) bin/monorepo release -- "$$COMMIT"
.PHONY: release

github: vendor ## Bring thesis-php/<name> to the state of the manifests, needs GITHUB_TOKEN [ARGS=--dry-run]
	$(if $(IN_CONTAINER),,$(DOCKER_COMPOSE) run --rm -e GITHUB_TOKEN -e PACKAGIST_USERNAME -e PACKAGIST_TOKEN php) bin/monorepo github $(ARGS)
.PHONY: github

# Quality

fixer: var vendor ## Fix code style [P=]
	$(RUN) vendor/bin/php-cs-fixer fix $(if $(P),--path-mode=intersection) --diff $(ARGS) $(PATHS)
.PHONY: fixer

fixer-check: var vendor ## Check code style [P=]
	$(RUN) vendor/bin/php-cs-fixer fix $(if $(P),--path-mode=intersection) --diff --dry-run $(ARGS) $(PATHS)
.PHONY: fixer-check

rector: var vendor ## Apply Rector rules [P=]
	$(RUN) vendor/bin/rector process $(ARGS) $(PATHS)
.PHONY: rector

rector-check: var vendor ## Check Rector rules [P=]
	$(RUN) vendor/bin/rector process --dry-run $(ARGS) $(PATHS)
.PHONY: rector-check

phpstan: var vendor ## Run static analysis [P=]
	$(RUN) vendor/bin/phpstan analyze --memory-limit=1G $(ARGS) $(PATHS)
.PHONY: phpstan

deps-analyze: vendor ## Check for unused and shadow dependencies
	$(RUN) vendor/bin/composer-dependency-analyser $(ARGS)
.PHONY: deps-analyze

test: var vendor up ## Run tests [P=]
	$(RUN) vendor/bin/testo $(if $(P),--suite=$(P)) $(ARGS)
.PHONY: test

# (A package that runs on an older PHP than the monorepo is copied to var/alone/<name>, installed there
# with the newest dependencies its composer.json allows and tested on PHP_VERSION. A monorepo package it requires
# comes from Packagist, as released, not from packages/.
# ARCH=386 runs the tests on 32-bit PHP of IMAGE, the official image by default: ours has no i386 build,
# Composer installs in it all the same)
ARCH ?=
IMAGE ?= php:$(or $(PHP_VERSION),8.6)-cli
test-alone: var ## Run the tests of a package installed alone P= [PHP_VERSION=] [ARCH=386] [IMAGE=]
	$(if $(P),,$(error P= names the package))
	rm -rf var/alone/$(P)
	mkdir -p var/alone
	cp -R packages/$(P) var/alone/$(P)
	$(RUN) sh -c 'cd var/alone/$(P) && composer update --no-interaction --no-progress'
ifeq ($(ARCH),386)
	docker run --rm --platform linux/386 --user $(DOCKER_USER) --volume "$(CURDIR)":/workspace \
		--workdir /workspace/var/alone/$(P) $(IMAGE) vendor/bin/testo --config=../../../testo-alone.php $(ARGS)
else
	$(RUN) sh -c 'cd var/alone/$(P) && vendor/bin/testo --config=../../../testo-alone.php $(ARGS)'
endif
.PHONY: test-alone

# (In GitHub Actions Tally annotates the benchmarks and writes the job summary. The summary file of the runner is not
# in the container: the report goes to var/, and the job appends it)
bench: var vendor ## Count the benchmarks under Valgrind and compare them with the expected numbers [P=]
	$(if $(IN_CONTAINER),,$(DOCKER_COMPOSE) run --rm -e GITHUB_ACTIONS -e GITHUB_RUN_ID -e GITHUB_REPOSITORY $(if $(GITHUB_ACTIONS),-e GITHUB_WORKSPACE=/workspace -e GITHUB_STEP_SUMMARY=/workspace/var/tally-summary.md) php) vendor/bin/tally run --output=var/tally $(ARGS) $(if $(P),packages/$(P)/benchmarks)
.PHONY: bench

bench-accept: var vendor ## Make the results of the last `make bench` the expected numbers [P=]
	$(RUN) vendor/bin/tally accept $(ARGS) var/tally $(if $(P),packages/$(P)/benchmarks)
.PHONY: bench-accept

# (Coverage of one package by its own tests, uploaded by the job coverage of check.yaml (agents/tests.md).
# A run of every suite at once exhausts 2 GB in Xdebug)
coverage: var vendor $(if $(filter amqp,$(P)),up) ## Collect the coverage of a package by its tests into var/coverage/<name>.xml P=
	$(if $(P),,$(error P= names the package))
	$(RUN) env XDEBUG_MODE=coverage vendor/bin/testo --suite=$(P) --coverage --coverage-clover=var/coverage/$(P).xml $(ARGS)
.PHONY: coverage

# (The score of a package goes to var/infection/<name>.json: the job infect of infection.yaml sends it
# to the Stryker Dashboard for the badge of the package (agents/tests.md))
infect: var vendor ## Run mutation testing [P=]
	$(RUN) php -d memory_limit=2G vendor/bin/infection --show-mutations --threads=max $(if $(P),--test-framework-options=--suite=$(P) --logger-summary-json=var/infection/$(P).json packages/$(P)/src/) $(ARGS)
.PHONY: infect

# CI

fix: rector fixer ## Apply Rector rules, then fix code style: Rector prints chains in its own way [P=]
.PHONY: fix

check: fixer-check rector-check phpstan deps-analyze test ## Run all checks
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
