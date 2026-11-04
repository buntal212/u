# Docker development

UIH development uses the independent Compose project `uih-dev`. The `app` service runs PHP 8.3 as a non-root user and the `db` service runs MySQL 8.0.40. Compose creates project-scoped network and volume names. MySQL has no published host port; the API binds to `127.0.0.1:8011` by default.

## First-time setup

Check the selected API port is free before starting the project:

```bash
ss -ltn
```

Ensure the shared Docker network for Temuka and UIH exists:

```bash
docker network create temuka-uih-network 2>/dev/null || true
```

Create the ignored environment file and set separate random development and MySQL root passwords. For example, generate each value with `openssl rand -hex 32`. Change `UIH_API_PORT` if 8011 is in use. Never reuse credentials from another project.

```bash
cp .env.example .env
```

Build the app image and install dependencies explicitly inside Docker:

```bash
docker compose --env-file .env -p uih-dev build app
docker compose --env-file .env -p uih-dev run --rm --no-deps app composer install --no-interaction
docker compose --env-file .env -p uih-dev run --rm --no-deps app php artisan key:generate --force
```

## Daily development

```bash
docker compose --env-file .env -p uih-dev up -d --wait
docker compose --env-file .env -p uih-dev exec -T app php artisan about
docker compose --env-file .env -p uih-dev exec -T app php artisan route:list --path=api/v1
docker compose --env-file .env -p uih-dev logs --tail=100 app db
```

The health endpoint is `http://127.0.0.1:${UIH_API_PORT:-8011}/api/v1/health`. The development MySQL data uses a persistent project-scoped named volume. Stop services without removing the volume:

```bash
docker compose --env-file .env -p uih-dev stop
```

## Shared Docker network with Temuka

UIH is connected to the external bridge network `temuka-uih-network` with DNS aliases `uih-dev-app` and `uih-api`. Other services on this network (such as Temuka Backend) reach UIH internally at `http://uih-dev-app:8000`. The development database remains isolated on the private `uih-dev_default` network.

Do not run development migrations during M1. PHP, Composer, and Artisan commands run only inside the app container. Dependency installation is explicit and does not run on container startup.
