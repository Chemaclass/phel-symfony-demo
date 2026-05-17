.PHONY: help install serve test phel-test phpunit db-setup db-reset cache-clear lint repl

PHP      ?= php
HOST     ?= 127.0.0.1
PORT     ?= 8765

help: ## Show this help
	@awk 'BEGIN{FS=":.*##";printf "Targets:\n"} /^[a-zA-Z_-]+:.*##/{printf "  \033[36m%-14s\033[0m %s\n",$$1,$$2}' $(MAKEFILE_LIST)

install: ## Install deps + seed SQLite DB
	composer install

serve: ## Start PHP dev server on $(HOST):$(PORT)
	$(PHP) -S $(HOST):$(PORT) -t public public/index.php

repl: ## Phel REPL (require namespaces, redefine, retest)
	vendor/bin/phel repl

test: ## Run Phel + PHPUnit suites
	composer test

phel-test: ## Run Phel tests only
	composer phel-test

phpunit: ## Run PHPUnit feature tests only
	composer phpunit

db-setup: ## Create DB if missing
	composer db-setup

db-reset: ## Drop and recreate DB
	composer db-reset

cache-clear: ## Clear Phel + Symfony caches
	composer phel-cache-clear
	$(PHP) bin/console cache:clear

lint: ## Lint Phel entrypoint (avoid linting whole src/Phel dir)
	vendor/bin/phel lint src/Phel/app.phel
