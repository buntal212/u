# Backend conventions

UIH keeps its M1 foundation small and makes HTTP contracts explicit. Use these patterns when they clarify a real request flow; do not create classes for every trivial endpoint.

## Folder structure

- `routes/api.php` defines versioned API routes under `/api/v1`.
- `app/Http/Controllers/Api/V1` handles HTTP transport and delegates meaningful operations.
- `app/Http/Requests/Api/V1` validates accepted input and owns request-level authorization.
- `app/Actions` contains focused application operations that benefit from independent testing or reuse.
- `app/Http/Resources/Api/V1` defines the public shape of successful responses.
- `app/Http/Middleware` holds route boundaries that apply before controller execution.
- `tests/Feature` verifies public HTTP behavior; `tests/Unit` verifies deterministic operations without HTTP or database setup.

## Request to response flow

For a non-trivial endpoint, the request lifecycle is:

**HTTP request → Form Request validation → Controller → Action → API Resource**

Laravel validates the dedicated Form Request before invoking the controller. The controller obtains validated input and delegates the operation. The Action accepts ordinary PHP values rather than an HTTP request. The Resource exposes only fields in the response contract and Laravel wraps it in `data`.

The reference normalizer demonstrates the complete path. A health check or similarly trivial response does not need an Action and Resource solely to satisfy a pattern.

## Input and validation

- Give each endpoint a dedicated Form Request when it has request validation or request authorization.
- Use explicit rule arrays and custom Indonesian messages for user-facing validation where appropriate.
- Apply `FailOnUnknownFields` when the request body is a strict contract. Unknown fields fail with `422` under the field's error key.
- Preserve raw JSON only when it is part of the response contract. The normalization example validates the raw body so Laravel's trimming middleware cannot change the returned `original` value.
- `POST /api/v1/examples/normalize` accepts a JSON object containing only a required string `value`, up to 1,000 characters. It rejects missing, blank, wrong-type, over-limit, malformed, and unexpected input with HTTP `422`.

## JSON response conventions

Successful API Resources use a `data` envelope. The normalization example returns `data.original` and `data.normalized`; it does not serialize a model or expose internal fields.

`bootstrap/app.php` renders `/api/*` exceptions as JSON. Validation uses Laravel's predictable response shape with a top-level `message` and field-keyed `errors`. Unknown API routes return JSON `404`. Keep production `APP_DEBUG=false` so exception responses do not reveal stack traces or sensitive details.

The normalization endpoint is an engineering example only. Route middleware permits it in `local` and `testing`, and returns JSON `404` in other environments. It has no authentication or database dependency and is not a product capability.

## Database boundaries

No business tables are part of this foundation. When database work is authorized, preserve the `uih-test` MySQL 8.0.40 isolation guard, use reviewed migrations, and add indexes only for known query patterns. Do not use SQLite to claim MySQL behavior.
