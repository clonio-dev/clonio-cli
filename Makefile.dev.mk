up:
	docker compose up -d

down:
	docker compose down

restart: down up

bash:
	docker compose exec php bash

test:
	docker compose exec php bash -c 'XDEBUG_MODE=coverage composer test'

check:
	docker compose exec php bash -c 'composer lint && composer test:types'

