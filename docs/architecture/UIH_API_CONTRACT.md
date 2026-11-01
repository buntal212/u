# UIH API contract conventions

> **PROPOSED — ILLUSTRATIVE ONLY.** This document proposes conventions for future contracts. The paths, fields, status values, headers, and examples below are not approved provider or business APIs. No domain-specific endpoint is finalized here.

## Contract goals

- **CONFIRMED** — Temuka communicates with UIH through backend-to-backend APIs.
- **CONFIRMED** — Udumbara-owned source applications remain authoritative for their operational records and business rules.
- **PROPOSED** — Version contracts explicitly, scope every caller, make retries safe, and return errors that callers can act on without exposing internals.
- **OPEN** — Select authentication technology, payload vocabulary, retention rules, and compatibility lifecycle before implementing provider APIs.

## Terminology and provider identity

- **Application** identifies a software system, such as Larissama, Laundry, Aset/Properti, UIH, or Temuka.
- **Provider** is **PROPOSED** as an authenticated integration identity registered with UIH on behalf of one source application or an explicitly approved source scope.
- **Tenant** and **store** remain source-defined scopes. Their identifiers are not globally interchangeable across applications.
- **Source record identity** is the identifier assigned by the system that owns that record; it should be paired with application/provider context.

**PROPOSED** — UIH should bind an authenticated credential to a stable provider registration. A caller-supplied `provider_id` is a selector, not authentication. UIH derives the authenticated provider identity from the verified credential and checks that any requested provider/scope matches it.

**OPEN** — Decide whether one application has one provider identity or multiple identities by environment, tenant, data category, or credential boundary; define lifecycle, rotation, suspension, and ownership.

## Versioning

**PROPOSED** — Keep the existing API prefix convention `/api/v1` for the first supported contract. Make incompatible changes a new major path version; prefer additive optional fields for compatible changes. Do not use a version number to imply a particular synchronization or business model.

**OPEN** — Define deprecation windows, supported-version overlap, contract publication format, and whether payloads also carry a schema version independently of the URL.

## Request and response envelopes

**PROPOSED — ILLUSTRATIVE ONLY:**

```http
POST /api/v1/providers/{provider_id}/records
Authorization: <approved backend credential>
Idempotency-Key: <stable-delivery-key>
X-Correlation-ID: <trace-id>
Content-Type: application/json
```

```json
{
  "source": {
    "application": "<source-application-id>",
    "record_id": "<source-record-id>",
    "tenant_id": "<source-scoped-tenant-id>",
    "store_id": "<source-scoped-store-id>"
  },
  "source_version": "<source-version-or-sequence>",
  "occurred_at": "<RFC-3339 timestamp>",
  "data": {
    "<approved-contract-field>": "<value>"
  }
}
```

This generic placeholder is not a canonical data model. The fields, whether tenant/store values apply, whether provider belongs in the path, and whether records are submitted individually or in batches are **OPEN**.

**PROPOSED — ILLUSTRATIVE ONLY:** successful durable acceptance can use a `data` envelope and distinguish queued/accepted work from completed indexing:

```json
{
  "data": {
    "receipt_id": "<uih-receipt-id>",
    "status": "accepted",
    "correlation_id": "<trace-id>"
  }
}
```

**PROPOSED** — Use an explicit `data` envelope for successful single-resource responses, consistent with UIH M2 conventions. Batch response shape and partial acceptance behavior require separate decisions.

## Authentication and authorization

- **PROPOSED requirement** — Authenticate service callers; do not accept provider identity or authorization from request payload fields alone.
- **PROPOSED requirement** — Authorize the authenticated principal for the operation, provider, source application, and any applicable source-scoped tenant/store boundary.
- **PROPOSED requirement** — Keep UIH consumer permissions separate from source-provider publication permissions.
- **PROPOSED requirement** — For future commands, UIH authorization is not a replacement for the source application's execution-time authorization and business validation.
- **OPEN mechanism** — Compare mTLS, OAuth client credentials, or another managed workload identity against deployment and rotation needs.
- **OPEN policy** — Define whether Temuka may see all approved discovery fields or only a narrower field/scope set.

## Idempotency and correlation

**PROPOSED** — Require an idempotency key for retryable data publication and mutating commands. Scope keys to authenticated provider (and operation/command where needed), bind each key to a request fingerprint, and define a retention window. A duplicate with the same key and same request should return the stable prior receipt/outcome; reusing a key with different content should return a conflict.

**OPEN** — Select key format, retention, maximum payload, key storage, and whether source record versioning supplements or replaces delivery idempotency for any particular contract.

**PROPOSED** — Accept or generate a correlation ID and propagate it across UIH ingestion/indexing or command forwarding. Return it in response metadata and include it in structured logs. Do not use correlation IDs as credentials or deduplication keys.

## Pagination and errors

**PROPOSED** — For future list/search APIs, use bounded page sizes and opaque cursors when stable ordering can be defined. Return pagination metadata with the data envelope. Offset pagination may be suitable for small administrative collections but should not be assumed for large or changing discovery results.

**OPEN** — Define maximum page size, cursor lifetime, stable sort keys, filter syntax, and whether totals are returned.

**PROPOSED — ILLUSTRATIVE ONLY:** errors use a predictable envelope with a stable machine-readable code, safe message, field errors when applicable, and a correlation identifier:

```json
{
  "message": "The request could not be accepted.",
  "code": "VALIDATION_FAILED",
  "errors": {
    "data.<field>": ["The field is invalid."]
  },
  "correlation_id": "<trace-id>"
}
```

**PROPOSED** — Use conventional HTTP statuses: `401` unauthenticated, `403` authenticated but not authorized, `404` unknown or intentionally undisclosed resource, `409` idempotency/version conflict, `422` invalid contract input, `429` rate limited, and `5xx` for service failures. Do not leak stack traces, credentials, or internal SQL details. Retry guidance and `Retry-After` behavior need an explicit policy.

## Contract compatibility

**PROPOSED** — Publish schemas and examples with contract tests. Reject unsupported major versions clearly. Treat a field removal, type/meaning change, requiredness change, or changed authorization semantics as incompatible. Additive fields are compatible only if consumers are required to ignore unknown response fields and provider behavior is explicitly defined.

**OPEN** — Decide strictness for unknown request/response fields, semantic-version metadata, rollout overlap, deprecation notice, and ownership of shared contract approval. External Hub payloads must be reviewed and accepted by UIH; External Hub does not define UIH contracts unilaterally.

## Source ownership and future command outcomes

**CONFIRMED** — The source application owns the business decision and operation. UIH can later route a command, but cannot declare source business state changed until the source's contract confirms it.

**PROPOSED — ILLUSTRATIVE ONLY:** a future command result could distinguish `accepted`, `completed`, `rejected`, and `outcome_unknown`. These are candidate labels only. Acceptance by UIH means queued/forwarded responsibility, not business success. The source should provide a stable command identity or idempotency contract so UIH can safely retry or reconcile uncertain outcomes.

**OPEN** — Decide which command outcomes are needed, whether asynchronous completion is supported, how long status is retained, how a caller queries it, and what source evidence resolves an unknown outcome. No checkout, payment, booking, inquiry, or order command is approved by this proposal.
