# UIH delivery plan

This plan sequences the engineering foundation and candidate product capabilities. Only completed M1/M2 and this documentation-planning milestone are established. Later milestones are proposals, not implementation authorization.

## Status labels

- **CONFIRMED** — Completed baseline or explicit architecture direction.
- **PROPOSED** — Candidate milestone or acceptance approach, pending review and approval.
- **OPEN** — Decision required before the affected milestone can be implemented.

## Milestones

### M1 — Engineering foundation (completed)

**CONFIRMED** — Laravel 13 on PHP 8.3 runs in Docker; development and testing Compose projects are isolated; MySQL 8.0.40/InnoDB is used; test database safety checks, PHPUnit, Pint, Composer validation, and local AI skills are present. The health endpoint and engineering documentation form the minimal foundation.

**Excluded:** business/domain tables, provider integration, synchronization, search, command routing, and third-party integration.

### M2 — Engineering standards (completed)

**CONFIRMED** — Backend conventions, agent workflow, and a local/testing-only normalization reference endpoint demonstrate the Controller/Form Request/Action/Resource flow and JSON error behavior.

**Excluded:** all Hub product capabilities and domain-specific business APIs.

### M3 — Architecture planning (this documentation milestone)

**PROPOSED scope** — Establish reviewed architecture boundaries, candidate data flows, API contract conventions, and a gated delivery sequence in the four documents linked from README. No application runtime or test infrastructure changes are included.

**Acceptance:** documents distinguish confirmed decisions, proposals, and open questions; source ownership and search-index roles are clear; no business API or schema is presented as approved; documentation links and Git diff are reviewed.

**Excluded:** provider endpoints, schemas/migrations, source integrations, Meilisearch services, discovery implementation, command routing, and External Hub.

### M4 — Candidate first provider integration

**PROPOSED** — After decisions are made, integrate one selected Udumbara source application and a narrow approved data scope. Define provider identity, authentication, contract, source identifiers, validation, durable acceptance, and operational ownership before coding.

**Dependencies:** selected source/application owner; approved minimal contract and data ownership; provider identity and authentication; source publication capability; data classification/authorization; delivery and error semantics; test fixtures and isolated integration test strategy.

**Candidate acceptance:** authenticated provider scope is enforced; accepted records are validated and traceable to their source identity; retries do not create unintended duplicates; negative authorization and malformed input cases pass; the source application's operational behavior remains authoritative; operations and recovery are documented.

**Excluded:** second-source generalization, universal domain model, Meilisearch, Temuka discovery, source database sharing, command execution, and External Hub.

### M5 — Candidate synchronization and normalized projection

**PROPOSED** — Establish reliable updates, corrections, deletions, replay, and reconciliation for the approved M4 data scope. Persist the agreed normalized integration projection in UIH MySQL.

**Dependencies:** M4 provider contract; approved projection fields and source-version semantics; freshness and retention targets; duplicate/out-of-order/deletion policy; reconciliation ownership and source snapshot/watermark capability.

**Candidate acceptance:** changes and deletes propagate according to contract; replay is safe; out-of-order behavior is deterministic; reconciliation reports divergence and applies only authorized repairs; lag and failures are observable; tests prove no source operational database is modified by UIH sync.

**Excluded:** generalized synchronization for every source, search index, consumer discovery endpoint, command gateway, and business workflow ownership.

### M6 — Candidate unified discovery

**PROPOSED** — Add Meilisearch as a derived index of the approved UIH projection and expose an authenticated discovery contract for an approved consumer, potentially Temuka.

**Dependencies:** M5's durable projection; approved searchable fields, access rules, freshness indicators, ranking and filtering behavior; index rebuild/update/deletion design; consumer authentication and contract review; resource and recovery expectations.

**Candidate acceptance:** index can be rebuilt from UIH-owned projection data; indexing failure does not corrupt MySQL projection state; query scope is authorized; pagination and errors are bounded and documented; results identify approved source/freshness metadata; tests cover stale, deleted, unauthorized, and index-unavailable cases.

**Excluded:** checkout, availability reservation, booking, payments, orders, source fan-out on every query, and command execution.

### Later — Candidate command gateway

**PROPOSED** — Consider controlled command routing only after a concrete command use case, source API, authorization model, idempotency contract, audit needs, and uncertain-outcome reconciliation are approved.

**Dependencies:** named commands and owners; consumer/provider authorization; source-side execution contracts and idempotency; source revalidation expectations; timeout/retry behavior; audit and retention; reconciliation and operational support.

**Candidate acceptance:** UIH never claims a source-side operation succeeded without source confirmation; retries cannot unintentionally execute twice; source business validation remains decisive; unauthorized and uncertain outcomes have explicit behavior and observable audit trails.

**Excluded until separately approved:** every checkout, payment, booking, inquiry, order, or transaction workflow and any attempt to centralize source business rules in UIH.

### Future boundary — External Hub

**CONFIRMED direction** — External Hub is a separate future integration boundary for third-party systems. It must communicate using contracts accepted by UIH.

**OPEN** — Its ownership, deployment, security model, data processing, contract governance, and schedule are not decided here.

**Excluded from all milestones in this plan:** implementing External Hub, exposing UIH directly to arbitrary third-party credentials, or allowing external parties to set UIH contracts unilaterally.

## Architecture decisions required before implementation

1. Select the first source application and data scope, with an accountable source owner.
2. Define provider identity, credential model, authorization scopes, and lifecycle.
3. Agree on the minimum normalized discovery concepts and source identity rules; explicitly avoid a universal model without evidence.
4. Choose publication and delivery mechanics from the source's capabilities.
5. Decide source versioning, duplicate handling, correction, deletion, and reconciliation semantics.
6. Define data classification, field-level visibility, retention, and audit policy.
7. Set freshness, availability, recovery, volume, and latency targets appropriate to the first use case.
8. Approve API versioning, error, pagination, correlation, idempotency, and compatibility conventions.
9. For M6, decide search fields, filters, ranking, consumer permissions, index rebuild, and outage behavior.
10. Before command routing, approve each command's source owner, authority, idempotency, status, and uncertain-outcome contract.

## Delivery controls

**PROPOSED** — Treat each candidate milestone as independently reviewable. Before implementation begins, agree on its decision record, narrow scope, affected owners, data/security boundaries, acceptance tests, and rollback/recovery story. A phase's candidate acceptance criteria do not become approved requirements until reviewed.

**CONFIRMED** — No phase in this document authorizes work outside the currently requested milestone. This M3 deliverable stops at planning; M4 or later requires a separate explicit implementation request.
