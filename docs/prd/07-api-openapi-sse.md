# Public API, OpenAPI, And SSE

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

- Django REST Framework.
- `drf-spectacular` for OpenAPI 3, Swagger UI, and ReDoc.

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

- **Track A**: short-lived deployment token, short-lived console token, guest link token, browser session token (when SSE/WebSocket auth via cookie is impractical).
- **Track B**: personal access token, project service token, course service token, plugin webhook token.

## SSE

SSE is a first-class live update channel.

Streams:

- Deployment timeline.
- Script execution.
- Worker/job status.
- Provider health.
- Quota usage.
- Approval state.
- Audit events for authorized admin views.
- Job logs where permitted.

SSE requirements:

- Streams are permission-filtered per user or token.
- Streams can be scoped to deployment, project, course, or admin views.
- Important events are persisted before broadcast.
- Browser disconnects do not lose the authoritative event timeline.
- **`Last-Event-ID` replay**: every emitted event carries a monotonic id (per stream scope) in the `id:` SSE field. On reconnect, the browser's `EventSource` automatically resends `Last-Event-ID` as a request header; the server replays persisted events with `id > Last-Event-ID` before resuming live emission. Replay is bounded by a per-stream retention window (default 24h); events older than that produce a "stream advanced past your last id, refresh for current state" sentinel rather than a silent gap.
- NATS can be used for fanout, but PostgreSQL remains the source for durable state.
- TanStack Query `refetchInterval` (with Page Visibility API backoff) can be used for simple screens; SSE is preferred for live status. SSE consumers in React islands wrap the browser-native `EventSource` API in a TanStack Query subscription per PRD §15 Live Updates.
