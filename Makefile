.PHONY: help build run stop logs shell clean deploy

PORT ?= 8080
export PORT

help:
	@echo "Lomagundi & Forestal Pharmacies website - Docker & Railway commands"
	@echo ""
	@echo "  make run       Build and start the site container"
	@echo "  make build     Build the container image"
	@echo "  make stop      Stop and remove the site container"
	@echo "  make logs      Follow container logs"
	@echo "  make shell     Open a shell in the site container"
	@echo "  make clean     Stop the site and remove its image"
	@echo "  make deploy    Deploy to Railway (requires Railway CLI + 'railway login')"

build:
	docker compose build

run:
	docker compose up --build --detach
	@echo "Website available at http://localhost:$(PORT)"

stop:
	docker compose down

logs:
	docker compose logs --follow

shell:
	docker compose exec web bash

clean:
	docker compose down --rmi local

deploy:
	railway up
