default:
	docker compose exec php /bin/sh

install: prepare_files start composer_install migrate
restart: stop start
restart_with_rebuild: stop start_with_rebuild
restart_with_override: stop_with_override start_with_override

prepare_files:
	cp .env .env.local

start:
	docker compose up -d

start_with_rebuild:
	docker compose build php
	docker compose up -d

stop:
	docker compose stop

php_sh:
	docker compose exec php sh

composer_install:
	docker compose exec php composer install

composer_update:
	docker compose exec php composer update

test:
	docker compose exec php php bin/phpunit
	docker compose exec php php bin/ecs

migrate:
	docker compose exec php php bin/console doctrine:m:m -n
