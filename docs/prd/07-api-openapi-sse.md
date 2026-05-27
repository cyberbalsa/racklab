# Public API, OpenAPI, And Real-Time Push

> **Note:** Implementation detail for the API and real-time stack choices in this section (OpenAPI generator, WebSocket transport, replay-log schema) lives in `docs/superpowers/specs/2026-05-26-laravel-redesign.md` §2 and §7. This document captures the wire-protocol contracts and functional requirements; the spec is the source of truth for the libraries that implement them.

RackLab exposes a public REST API from the start. Anything a user can do in the UI should be automatable through the API, subject to the same RBAC, quota, approval, and audit checks.

## REST API

Requirements:

- Versioned namespace such as `/api/v1/`.
- OpenAPI 3 schema generated from implementation.
- Swagger UI and ReDoc for interactive docs.
- API endpoints for projects, catalog, stacks, deployments, networking, scripts, approvals, quotas, consoles, audit views, tokens, and permitted admin/provider operations.
- Same service layer as the web/AJAX views so UI and API behavior do not diverge.
- Stable error model with machine-readable codes.
- Idempotency keys for deployment and mutating infrastructure operations.
- Pagination, filtering, ordering, and search on list endpoints.

Preferred implementation:

- Laravel (routes defined via `routes/api.php`; business logic in `app/Domain/` services).
- `knuckleswtf/scribe` (v5.10) for OpenAPI 3.1 generation, Swagger UI, and ReDoc. Scribe introspects routes, FormRequest validation rules, and Eloquent models automatically; explicit `@response` annotations fill gaps. A **schema-drift CI gate** runs `php artisan scribe:generate --no-extraction` and then `git diff --exit-code docs/api/openapi.yaml` — PRs that change the route surface must update the committed OpenAPI artifact.

## Token System

RackLab issues tokens on two tracks (see also PRD §6 for the auth-side framing).

### Track A — Signed JWTs (short-lived)

For browser session, console grant, share link, and short-lived deployment tokens.

JWT requirements:

- RS256 signing with key rotation support (asymmetric so verifying services can hold only the public key).
- Standard claims: `iss`, `aud`, `sub`, `exp`, `iat`, `nbf`, `jti`.
- RackLab claims: grant id, scope, tenant id, project/course constraints, permission set.
- Tokens cannot exceed the effective permissions of their owner unless created by an admin/service-account policy.
- Revocation by `jti` via a server-side blacklist; fully stateless JWTs are insufficient.
- Token creation, use, denial, and revocation are audit logged.
- Short expirations (minutes to hours); refresh tokens follow the same model with longer expiry.

### Track B — Opaque PATs (long-lived)

For named agent/CLI/plugin tokens.

Requirements:

- Server-side token grant rows with hashed bearer storage (bcrypt-style). Raw bearer shown once at issuance, never re-displayed.
- Same grant metadata as Track A (name, owner, type, created time, expiration, last-used time, revoked time, allowed IPs/CIDRs, project/course/global scope, delegated permissions and roles, tenant scope, `jti`-equivalent identifier, audit metadata).
- Revocation is "delete the row"; no blacklist propagation latency.
- Token creation, use, denial, and revocation are audit logged.
- Long expirations (days to months); rotation policies configurable per token type.

### Dispatch

The auth backend picks the track from the `Authorization` header prefix:

- `Authorization: Bearer <jwt>` → Track A verification.
- `Authorization: Token <opaque>` → Track B lookup-and-verify.

### Token types

- **Track A**: short-lived deployment token, short-lived console token, guest link token, browser session token (when WebSocket auth via cookie is impractical).
- **Track B**: personal access token, project service token, course service token, plugin webhook token.

## Real-Time Push

Real-time push is a first-class feature of RackLab.

**Transport:** [Laravel Reverb](https://reverb.laravel.com/) (WebSocket server, Pusher wire protocol). The client-side counterpart is [Laravel Echo](https://laravel.com/docs/broadcasting#client-side-installation) with the Pusher.js driver. This replaces the prior SSE/`EventSource` transport; the on-the-wire format is WebSocket frames using the Pusher protocol rather than `text/event-stream` — but the **durable-replay contract** (see below) is preserved by a Postgres event log, so API consumers get equivalent Last-Event-ID semantics regardless of transport.

### Live channels

Streams available via Reverb:

- Deployment timeline.
- Script execution.
- Worker/job status.
- Provider health.
- Quota usage.
- Approval state.
- Audit events for authorized admin views.
- Job logs where permitted.

Requirements:

- Channels are permission-filtered per user or token.
- Channels can be scoped to deployment, project, course, or admin views.
- Important events are **persisted before broadcast** (`ShouldBroadcastAfterCommit` discipline — the `broadcast_event_log` INSERT and the business-state mutation share the same DB transaction; Reverb dispatch fires only after the transaction commits, preventing "client saw event for state that doesn't exist").
- Browser disconnects do not lose the authoritative event timeline (see replay endpoint below).

### Channel taxonomy

All channels are private (presence channels are avoided for fan-out cost reasons). Channel names carry `{tid}` (tenant id) so channel auth can enforce tenant scope before the resource-level check.

| Channel | Audience | Events |
| --- | --- | --- |
| `private-tenant.{tid}.deployment.{did}` | actors with `deployment.view` on `did` | `DeploymentStateChanged`, `DeploymentResourceAttached` |
| `private-tenant.{tid}.job.{jid}` | actors with `job.view` on `jid` | `JobStateChanged`, `JobOutputChunk` |
| `private-tenant.{tid}.console.{cid}` | actors with `console.attach` on `cid` | `ConsolePreAttach`, `ConsoleAttached`, `ConsoleDetached` |
| `private-tenant.{tid}.audit.tail` | actors with `audit.tail` (typically admins) | `AuditAppended` |

Event names (e.g. `JobOutputChunk`, `DeploymentStateChanged`, `AuditAppended`) are stable functional-contract names regardless of transport.

**Channel auth** delegates to the same `AccessResolver`-based three-predicate composition used throughout the system (binding scope ⊇ resource tenant AND resource visibility ⊇ actor tenant AND role ⊇ requested action).

### Wire format (Pusher protocol)

An incoming event on the Echo client looks like:

```json
{
  "event": "JobOutputChunk",
  "channel": "private-tenant.42.job.5001",
  "data": {
    "id": "01HXAB…",
    "chunk": "…",
    "seq": 47
  }
}
```

The Echo client abstracts the Pusher framing; application code subscribes with `Echo.private('private-tenant.42.job.5001').listen('JobOutputChunk', handler)`. The `id` field in `data` is a ULID — monotonic and sortable — used as the cursor for replay.

### Last-Event-ID replay endpoint

Reverb provides reconnection but not durable replay. RackLab adds a dedicated replay endpoint that preserves the Last-Event-ID semantics from the prior SSE transport, now backed by a Postgres `broadcast_event_log` table instead of the SSE `id:` field.

```http
GET /api/v1/replay?channel=private-tenant.42.job.5001&since=ev_01HXAB…
```

The endpoint reads:

```sql
SELECT * FROM broadcast_event_log
WHERE channel = :ch AND id > :since
ORDER BY id ASC LIMIT 1000
```

The client merges replay results with live Reverb messages (dedup by ULID, monotonic order). On reconnect:

1. Client records last-seen ULID from the live channel.
2. Client reconnects via Echo.
3. Client fires `GET /api/v1/replay?since=<last_ULID>`, drains the response, then accepts live messages.
4. Dedup by ULID prevents double-delivery.

**Replay gap sentinel:** events are retained for 24 hours. If `since` is older than the sweep window, the endpoint returns a `gap` sentinel (`HTTP 200` with `{"gap": true}`) rather than silently missing events — the client should refresh from the authoritative REST state. The nightly sweep deletes rows where `created_at < now() - interval '24 hours'`.

**Scope check:** the replay endpoint uses the same `AccessResolver` for visibility checks as the WebSocket channel auth. Requesting a replay for a channel the caller cannot authorize returns `403`.

**Postgres schema** — canonical in spec §7; update both this section and the spec together if the schema changes:

```sql
CREATE TABLE broadcast_event_log (
  id            ULID  PRIMARY KEY,           -- monotonic, sortable, client-facing
  tenant_id     UUID  NOT NULL,
  channel       TEXT  NOT NULL,              -- e.g. private-tenant.42.job.5001
  event_class   TEXT  NOT NULL,              -- e.g. App\Events\JobOutputChunk
  payload       JSONB NOT NULL,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
  -- BRIN index on created_at (sweep efficiency), btree on (channel, id) for replay
  -- GIN on tenant_id for cross-tenant audit query joins
);
```

### Simple polling fallback

Livewire's `wire:poll` (with the built-in Page Visibility-aware throttle) can be used for simple screens where a Reverb channel subscription is not warranted. Reverb channels are preferred for live status, console output, and deployment timelines.
