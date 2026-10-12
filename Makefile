.PHONY: up down logs sh test migrate seed serve worker db-create local-test local-seed

up:
	docker compose up -d --build
down:
	docker compose down
logs:
	docker compose logs -f
sh:
	docker compose exec php sh
test:
	docker compose exec php php bin/phpunit
migrate:
	docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
seed:
	docker compose exec php php bin/console app:seed --purge

serve:
	php -S 127.0.0.1:8000 -t public
worker:
	php bin/console messenger:consume async -vv
db-create:
	php bin/console doctrine:database:create --if-not-exists
	php bin/console doctrine:database:create --if-not-exists --env=test
local-test:
	php bin/phpunit
local-seed:
	php bin/console app:seed --purge
