# Audit Query Service Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close M0 acceptance criterion line 53 — "A `Job` row can be created, transition through `dispatching → pending → running → succeeded` via the universal API, and each transition emits an audit event observable via the audit query API." The job state machine + audit emission already exist (slice 4–7) and are tested in `tests/integration/test_job_services.py`. What is missing is the **audit query API** surface: a typed service-layer function that the future DRF endpoints (M1) will wrap.

**Architecture:**

- **`src/racklab/core/audit_query.py`** (NEW) — service-layer query API.
  - `AuditEventFilter` frozen dataclass — all fields optional; tenant scope comes from the contextvar (NOT from the filter — codex P1).
  - `AuditEventPage` frozen dataclass — `events: tuple[AuditEvent, ...]` + `next_cursor: str | None`.
  - `query_audit_events(filter, *, limit=100, cursor=None) -> AuditEventPage` — cursor-paged query.
  - `count_audit_events(filter) -> int` — for audit-dashboard counts.
- **Cursor format** — opaque base64-encoded JSON `{"created_at": ISO8601, "id": uuid}`. Cursors are NOT tamper-proof (a caller can construct any pair); tenant filtering is enforced server-side via the contextvar, not via the cursor, so forged cursors only let a caller resume from a particular `(created_at, id)` boundary within their own tenant visibility (codex P2).
- **Tenant scoping** — always reads `get_current_tenant_id()` and refuses with `MissingTenantContextError` if no contextvar is set. The filter does not expose a `tenant_id` override (codex P1 — explicit overrides are a tenant-leak footgun pre-RBAC).
- **Bidirectional surfacing is the default, per PRD §14** (codex P1): events where the current tenant is the `actor_tenant`, `resource_tenant`, or appears in `target_tenant_set`. No opt-in flag.
- **Ordering** — events returned in `(created_at, id)` ascending so the chronological/hash-chain ordering is preserved.

**Tech Stack:** Django 5.2 LTS, Python 3.12+, pytest + pytest-django, the existing `AuditEvent` model (slice 5) + `AppendOnlyManager`.

---

## Scope boundary

**In scope:**

- `AuditEventFilter` frozen dataclass — fields below.
- `AuditEventPage` frozen dataclass.
- `query_audit_events(filter, *, limit, cursor) -> AuditEventPage`.
- `count_audit_events(filter) -> int`.
- Opaque cursor encoding/decoding.
- Tenant scoping via contextvar default + explicit `tenant_id` override.
- Bidirectional surfacing (events where the tenant is in `target_tenant_set` OR is the `resource_tenant`).
- Tiny tests for filter shape + cursor round-trip.
- Integration tests covering the M0 line 53 narrative end-to-end, plus bidirectional surfacing, pagination, count, missing-context rejection.

**Out of scope (deferred to later milestones unless noted):**

- **DRF endpoint** — M1 alongside auth (`AuditEventViewSet`).
- **Signed response envelopes / access provenance** — M1 (PRD §18).
- **SSE stream of new events** — M2 alongside Channels routing.
- **Hash-chain verification on read** — `manage.py verify_audit_chain` already covers this offline (slice 5); on-read verification is a M13b observability concern.
- **Per-row RBAC filtering** — M1 alongside the RBAC ↔ HTTP boundary.
- **Full-text search on payload** — M13a (Postgres GIN index on JSONB).
- **Rate limiting / token budgets** — M1.

## File Structure

- **Create:** `src/racklab/core/audit_query.py` — the service surface.
- **Create:** `tests/tiny/test_audit_query_filter.py` — filter dataclass shape, cursor encode/decode.
- **Create:** `tests/integration/test_audit_query_service.py` — end-to-end queries + the M0 line 53 narrative + bidirectional surfacing + pagination.

## Implementation tasks

### Task 1: Define the filter + page dataclasses

`src/racklab/core/audit_query.py`:

- `AuditEventFilter` — `frozen=True, slots=True, kw_only=True`. Fields (all optional, default `None`):
  - `correlation_id: str | None`.
  - `event_name: str | None` — exact match.
  - `event_name_prefix: str | None` — `startswith` match (so `job.` returns all job events).
  - `actor_identifier: str | None`.
  - `after: datetime | None` — inclusive lower bound on `created_at`. **Required to be timezone-aware** — `__post_init__` raises `ValueError` on a naive datetime (codex P3).
  - `before: datetime | None` — exclusive upper bound on `created_at`. **Required to be timezone-aware** for the same reason.
- `AuditEventPage` — `frozen=True, slots=True, kw_only=True`. Fields:
  - `events: tuple[AuditEvent, ...]`.
  - `next_cursor: str | None` — `None` when no more pages.

### Task 2: Cursor encode/decode

- `_encode_cursor(created_at: datetime, event_id: uuid.UUID) -> str` — base64url JSON `{"created_at": ISO8601, "id": str(event_id)}`.
- `_decode_cursor(cursor: str) -> tuple[datetime, uuid.UUID]` — raises `AuditQueryCursorError` on malformed input.
- `AuditQueryCursorError(ValueError)` — narrow exception.

### Task 3: Resolve tenant from contextvar

- `_resolve_tenant_id() -> str` — returns `get_current_tenant_id()`, else raises `MissingTenantContextError` (reuse existing import from `tenancy_managers`). No filter override — see codex P1 in the corrections section.

### Task 4: Build the queryset

- `_build_queryset(filter, tenant_id)`:
  - Start from `AuditEvent.objects.all()` (`AuditEvent.objects` is the `AppendOnlyManager` which is already unscoped — `.all_tenants()` does not exist; codex P1 #3).
  - Apply the **bidirectional tenant visibility predicate** (codex P1 #2 — surfacing is the default): `Q(actor_tenant_id=tenant_id) | Q(resource_tenant_id=tenant_id) | <target_tenant_set membership>`. The third term is backend-aware:
    - On Postgres (`connection.features.supports_json_field_contains`): `Q(target_tenant_set__contains=[tenant_id])`.
    - On SQLite (the fallback — codex P1 #4): annotate via `Cast("target_tenant_set", TextField())` and substring-match the JSON-encoded UUID (the surrounding double-quotes make false-positives impossible since RFC 4122 UUID strings can't appear as substrings of other UUID strings inside quoted JSON).
  - Apply each filter field via `.filter(...)` if set.
  - Order by `("created_at", "id")` ascending.

### Task 5: Paging

- `query_audit_events(filter, *, limit=100, cursor=None)`:
  - **Validate `limit` FIRST** (codex P3 — before tenant resolution so `limit=0` raises `ValueError` not `MissingTenantContextError`). Clamp `1 <= limit <= MAX_QUERY_LIMIT (=1000)`.
  - Resolve tenant_id.
  - Build queryset.
  - If `cursor` is provided: decode it, then `.filter(Q(created_at__gt=ts) | Q(created_at=ts, id__gt=eid))`.
  - Fetch `limit + 1` rows; if `len == limit + 1`, drop the last and emit a `next_cursor` from the kept last row; else `next_cursor=None`.
  - Return `AuditEventPage(events=tuple(rows), next_cursor=...)`.

### Task 6: count_audit_events

- `count_audit_events(filter) -> int`:
  - Resolve tenant_id, build queryset, return `.count()`.
  - Does not page — caller can call freely; for very large counts the M1 DRF endpoint may rate-limit.

### Task 7: Tiny tests

`tests/tiny/test_audit_query_filter.py`:

- Filter is frozen + immutable.
- Default filter has all `None` fields.
- **Naive datetime in `after` raises `ValueError`** (codex P3).
- **Naive datetime in `before` raises `ValueError`**.
- Aware datetime accepted.
- Cursor round-trip (`encode → decode → equality`).
- `_decode_cursor("not-base64")` raises `AuditQueryCursorError`.
- `_decode_cursor` on tampered base64 (e.g. missing required keys) raises.
- `query_audit_events(..., limit=0)` raises `ValueError` (BEFORE tenant resolution — codex P3).
- `query_audit_events(..., limit=1001)` raises `ValueError`.

### Task 8: Integration tests

`tests/integration/test_audit_query_service.py`:

- **M0 line 53 narrative**: `create_job` + 3 transitions → query by `correlation_id` returns the 4-event timeline in order with the expected `event_name`s.
- Filter by `event_name="job.transition"` returns only the 3 transition events.
- Filter by `event_name_prefix="job."` returns all 4.
- Filter by `actor_identifier="worker:provider-1"` returns the events with that actor.
- Filter by `after` excludes earlier events; **exact-boundary case** — an event at `t0` is included when `after=t0` (inclusive lower bound) (codex P3).
- Filter by `before` excludes later events; **exact-boundary case** — an event at `t0` is excluded when `before=t0` (exclusive upper bound).
- Missing tenant context raises `MissingTenantContextError`.
- **Bidirectional surfacing (default)**: cross-tenant binding issuance emitted under tenant A → queried by tenant B returns the event when B is in `target_tenant_set` (codex P1 #2 — surfacing is on by default per PRD §14).
- **Tenant isolation**: events for tenant A under tenant A's context don't show up in tenant C's queries (where C is neither actor, resource, nor in target_tenant_set).
- **Pagination**: emit 5 events, query `limit=2`, fetch page 1 (2 events + `next_cursor`), use cursor for page 2 (2 events + `next_cursor`), page 3 (1 event + `next_cursor=None`).
- `count_audit_events` returns 5 for the same filter.

## Acceptance

- M0 line 53 closed end-to-end: the integration test `test_query_returns_full_job_lifecycle_by_correlation_id` performs the dispatching → pending → running → succeeded sequence and then queries via `query_audit_events(AuditEventFilter(correlation_id=...))`, asserting the 4-event timeline. PROGRESS.md "Acceptance criteria status" gains an explicit line-53 paragraph mirroring the line-54/56/59 paragraphs.
- All existing tests still pass.
- mypy + basedpyright + ruff + bandit + semgrep + pip-audit clean.
- Frontend gates unaffected (no frontend changes).

## Codex plan-review folded (2026-05-26)

- **P1 #1 (tenant_id override is a leak footgun)** — Dropped `tenant_id` from `AuditEventFilter`. Tenant scope always comes from the contextvar via `get_current_tenant_id()`. Cross-tenant queries by privileged callers should switch the context explicitly via `current_tenant(other_id)` — same convention used by the retention reaper.
- **P1 #2 (bidirectional surfacing should be the default)** — Removed `include_target_tenant_set` flag. PRD §14 line 39 mandates `actor_tenant OR resource_tenant OR tenant IN target_tenant_set` as the default query. Test expects the cross-tenant binding-issuance event surfaces to tenant B by default.
- **P1 #3 (`AuditEvent.objects.all_tenants()` doesn't exist)** — Use `AuditEvent.objects.filter(...)` directly; `AppendOnlyManager` is already unscoped.
- **P1 #4 (SQLite `JSONField.__contains` unsupported)** — Backend-aware predicate via `connection.features.supports_json_field_contains`. SQLite fallback annotates `Cast("target_tenant_set", TextField())` and substring-matches the JSON-encoded UUID. Both backends covered.
- **P2 (cursor not tamper-proof)** — Documented that cursors are opaque, not signed; tenant filtering is enforced via contextvar regardless. No security claim made.
- **P3 #1 (validate limit before tenant resolution)** — `limit` validated at entry, before `get_current_tenant_id()`.
- **P3 #2 (datetime aware-vs-naive)** — `AuditEventFilter.__post_init__` requires aware datetimes for `after` / `before`; tiny tests pin the boundary semantics; integration tests cover exact-boundary cases.

## Risk + open questions

- **Cursor stability under hash-chain rewrites**: the audit chain is append-only; a cursor decoded after the chain compacts (which M0 does not do) could skip rows. Out of scope for M0.

## Codex review pattern

Per CLAUDE.md §7:

- **Plan review**: `codex exec --dangerously-bypass-approvals-and-sandbox --skip-git-repo-check "Review docs/superpowers/plans/2026-05-26-audit-query-service.md. Goal: ship a typed service-layer audit-query API closing M0 acceptance line 53. Constraints: must respect tenant context, no DRF (M1 owns HTTP), opaque cursor pagination, PRD §14 bidirectional surfacing. Findings I want: correctness bugs, missing edge cases, security issues, missing tests. Be terse, prioritize by severity." > /tmp/codex-plan.md 2>&1 &` and fold P0/P1 before commit.
- **Diff review**: `codex review --uncommitted --dangerously-bypass-approvals-and-sandbox > /tmp/codex-diff.md 2>&1 &` after staging but before commit.
