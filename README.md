# Udumbara Internal Hub

UIH is a Laravel 13 backend foundation running PHP 8.3 and MySQL 8.0.40. It includes independent Docker development and test environments, PHPUnit, Pint, `GET /api/v1/health`, and one local/testing-only engineering reference endpoint. It contains no Hub business features or domain tables.

## Reference endpoint

`POST /api/v1/examples/normalize` demonstrates strict request validation, an Action, and a versioned API Resource. It is enabled only when `APP_ENV` is `local` or `testing`.

```json
{
  "value": "  Udumbara    Internal Hub  "
}
```

```json
{
  "data": {
    "original": "  Udumbara    Internal Hub  ",
    "normalized": "Udumbara Internal Hub"
  }
}
```

## Engineering workflow

- Follow [AGENTS.md](AGENTS.md) and [the agent workflow](docs/engineering/AGENT_WORKFLOW.md).
- Apply [backend conventions](docs/engineering/BACKEND_CONVENTIONS.md) for routing, validation, Actions, Resources, and JSON errors.
- Use project-local skills in `.agents/skills/`; the agent workflow explains when each skill applies.
- Set up and run the app using [Docker development](docs/engineering/DOCKER_DEVELOPMENT.md). Run the disposable test suite with the commands in [testing](docs/engineering/TESTING.md).
