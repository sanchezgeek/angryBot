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

## —— 🎵 🐳 The Symfony Docker Makefile 🐳 🎵 ——————————————————————————————
help: ## Outputs this help screen
	@grep -E '(^[a-zA-Z0-9\./_-]+:.*?##.*$$)|(^##)' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}{printf "\033[32m%-30s\033[0m %s\n", $$1, $$2}' | sed -e 's/\[32m##/[33m/'

## —— Docker 🐳 ————————————————————————————————————————————————————————————
build: ## Builds the Docker images
	@$(DOCKER_COMP) build

rebuild: ## Rebuilds the Docker images
	@$(DOCKER_COMP) build --pull --no-cache

up: ## Start the docker hub in detached mode (no logs)
	@$(DOCKER_COMP) up --detach

start: prepare_files build up ## Build and start the containers

dc-stop: ## Stop the docker hub
	@$(DOCKER_COMP) stop

down: ## Stop the docker hub
	@$(DOCKER_COMP) down --remove-orphans

logs: ## Show live logs
	@$(DOCKER_COMP) logs --tail=0 --follow

sh: ## Connect to the PHP FPM container
	@$(PHP_CONT) sh

## —— Composer 🧙 ——————————————————————————————————————————————————————————
composer: ## Run composer, pass the parameter "c=" to run a given command, example: make composer c='req symfony/orm-pack'
	@$(eval c ?=)
	@$(COMPOSER) $(c)

vendor: ## Install vendors according to the current composer.lock file
vendor: c=install --prefer-dist --no-dev --no-progress --no-scripts --no-interaction
vendor: composer

## —— Symfony 🎵 ———————————————————————————————————————————————————————————
sf: ## List all Symfony commands or pass the parameter "c=" to run a given command, example: make sf c=about
	@$(eval c ?=)
	@$(SYMFONY) $(c)

cc: c=c:c ## Clear the cache
cc: sf

## —— App 🛠 ————————————————————————————————————————————————————————————————
test: ## Run tests
	@$(eval gr ?= )
	@$(PHP_CONT) bin/phpunit --testdox $(gr)

run: ## Run bot
	@$(PHP_CONT) /usr/bin/supervisord

reload: ## Reload supervisor config
	@$(PHP_CONT) /usr/bin/supervisorctl reload

restart: ## Restart bot
	@$(PHP_CONT) /usr/bin/supervisorctl restart all

stop: ## Stop bot
	@$(PHP_CONT) /usr/bin/supervisorctl stop all

crit: ## Get dev CRITICAL
	@$(PHP_CONT) tail -f /srv/app/var/log/dev.log | grep CRIT

warn: ## Get dev WARNING
	@$(PHP_CONT) tail -f /srv/app/var/log/dev.log | grep WARN

out-warn: ## Get consumers "warning" output
	@$(PHP_CONT) tail -f /srv/app/var/log/bot-supervizord-out.log | grep '!!'

out: ## Get consumers output
	@$(PHP_CONT) tail -f /srv/app/var/log/bot-supervizord-out.log


## —— Position 📉 ———————————————————————————————————————————————————————————

sl-info: ## Get position SLs info ("s=" - to specify `position_side`, "p=" - to specify `pnlStep`, "sp=" - to specify `showPnl`, example: sl-info s=sell p=30)
	@$(eval s ?=)
	@$(eval p ?= 30)
	@$(eval a ?= )
	@$(eval sp ?= )
	@$(eval stp ?= )
	@$(PHP_CONT) ./bin/console sl:info $(s) -p $(p) --aggregateWith='$(a)' $(sp) $(stp)

## —— SHORT 🐻 ——
s-info: ## Get SHORT-position SLs info ("p=" - to specify `pnlStep`, example: s-info p=30)
s-info: s=sell
s-info: sl-info

p-s-info: ## Get SHORT-position SLs info with PNL ("p=" - to specify `pnlStep`, example: s-info p=30)
p-s-info: s=sell
p-s-info: sp=--showPnl
p-s-info: sl-info

## —— LONG 🐂 ——
b-info: ## Get LONG-position SLs info ("p=" - to specify `pnlStep`, example: b-info p=30)
b-info: s=buy
b-info: sl-info

p-b-info: ## Get LONG-position SLs info with PNL ("p=" - to specify `pnlStep`, example: p-b-info p=30)
p-b-info: s=buy
p-b-info: sp=--showPnl
p-b-info: sl-info

pos-m-info: ## Get position info on price movement ("s=" - to specify `position_side` "t=" - to specify `to` price, example: m-info s=sell t=30000)
	@$(eval s ?=)
	@$(eval t ?=)
	@$(PHP_CONT) ./bin/console p:move-info $(s) -t $(t)

## —— SHORT 🐻 ——
s-m-info: ## Get SHORT-position info on price movement ("t=" - to specify `to` price, example: s-m-info t=30000)
s-m-info: s=sell
s-m-info: pos-m-info
## —— LONG 🐂 ——
l-m-info: ## Get LONG-position info on price movement ("t=" - to specify `to` price, example: l-m-info t=30000)
l-m-info: s=buy
l-m-info: pos-m-info
