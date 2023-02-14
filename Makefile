DOCKER_COMP = docker compose --env-file=.env.local

# Docker containers
PHP_CONT = $(DOCKER_COMP) exec php

# Executables
PHP      = $(PHP_CONT) php
COMPOSER = $(PHP_CONT) composer
SYMFONY  = $(PHP_CONT) bin/console

# Misc
.DEFAULT_GOAL = help
.PHONY        : help build up start down logs sh composer vendor sf cc

prepare_files:
	@if [ ! -f .env.local ]; then cp .env.local.dist .env.local; fi

## —— 🎵 🐳 The Symfony Docker Makefile 🐳 🎵 ——————————————————————————————————
help: ## Outputs this help screen
	@grep -E '(^[a-zA-Z0-9\./_-]+:.*?##.*$$)|(^##)' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}{printf "\033[32m%-30s\033[0m %s\n", $$1, $$2}' | sed -e 's/\[32m##/[33m/'

## —— Docker 🐳 ————————————————————————————————————————————————————————————————
build: ## Builds the Docker images
	@$(DOCKER_COMP) build

rebuild: ## Rebuilds the Docker images
	@$(DOCKER_COMP) build --pull --no-cache

up: ## Start the docker hub in detached mode (no logs)
	@$(DOCKER_COMP) up --detach

start: prepare_files build up ## Build and start the containers

stop: ## Stop the docker hub
	@$(DOCKER_COMP) stop

down: ## Stop the docker hub
	@$(DOCKER_COMP) down --remove-orphans

logs: ## Show live logs
	@$(DOCKER_COMP) logs --tail=0 --follow

sh: ## Connect to the PHP FPM container
	@$(PHP_CONT) sh

## —— Composer 🧙 ——————————————————————————————————————————————————————————————
composer: ## Run composer, pass the parameter "c=" to run a given command, example: make composer c='req symfony/orm-pack'
	@$(eval c ?=)
	@$(COMPOSER) $(c)

vendor: ## Install vendors according to the current composer.lock file
vendor: c=install --prefer-dist --no-dev --no-progress --no-scripts --no-interaction
vendor: composer

## —— Symfony 🎵 ———————————————————————————————————————————————————————————————
sf: ## List all Symfony commands or pass the parameter "c=" to run a given command, example: make sf c=about
	@$(eval c ?=)
	@$(SYMFONY) $(c)

cc: c=c:c ## Clear the cache
cc: sf

## —— App 🛠 ———————————————————————————————————————————————————————————————
test: ## Run tests
	@$(PHP_CONT) bin/phpunit

run: ## Run bot
	@$(PHP_CONT) /usr/bin/supervisord

restart: ## Restart bot
	@$(PHP_CONT) /usr/bin/supervisorctl restart all

out: ## Get consumers output
	@$(PHP_CONT) tail -f /srv/app/var/log/bot-supervizord-out.log

crit: ## Get dev CRITICAL
	@$(PHP_CONT) tail -f /srv/app/var/log/dev.log | grep CRIT

warn: ## Get dev WARNING
	@$(PHP_CONT) tail -f /srv/app/var/log/dev.log | grep WARN

out-warn: ## Get consumers "warning" output
	@$(PHP_CONT) tail -f /srv/app/var/log/bot-supervizord-out.log | grep '!!'
