# Security

> **Note:** Implementation detail for the security stack choices in this section (AccessResolver/CrossTenantFetch wiring, Hash driver, provenance HMAC verifier, container network policy, ProviderConsoleProxy) lives in `docs/superpowers/specs/2026-05-26-laravel-redesign.md` §5 and §7. This document captures the security contract — server-owned authorisation, per-row access provenance, no-provider-creds-in-script-workers, signed cross-tenant events; the spec is the source of truth for the libraries that implement them.

Security is built into auth, RBAC, provider access, scripting, networking, tokens, audit, and operations.

## Secrets

Requirements:

- Provider credentials encrypted at rest.
- Script secrets encrypted at rest.
- Secret access scoped by project/course/deployment.
- Secret reads audit logged.
- Secrets never exposed in user-visible logs.
- Secrets redacted from artifacts.
- Mounted secrets supported in container deployments.
- Secret backend plugin support.

## Tokens

Requirements:

- Strong signed JWTs.
- Short expirations where practical.
- Server-side grant metadata.
- Revocation by `jti`.
- Key rotation.
- Scoped permissions.
- Allowed IP/CIDR policy where configured.
- Audit on creation, use, denial, and revocation.

## Scripts

Requirements:

- Untrusted code runs only in script worker pools.
- Hardened per-job Podman container manifests (network-default-deny, `--read-only`, non-root user, resource caps, cosign-signed images) — see redesign spec §7.
- No provider credentials in script workers.
- Network disabled by default.
- Resource limits.
- Timeouts.
- Artifact redaction.
- Approval and RBAC controls.

## Console

Requirements:

- Short-lived console tokens.
- Scoped to target VM and allowed action.
- Console sharing audit logged.
- Guest console links are revocable and time-limited.
- Console proxy must not grant provider-console access beyond RackLab authorization.

## Multi-tenancy

Tenancy is enforced in soft (RBAC) mode (PRD §19). Security requirements that follow:

- **No silent cross-tenant data leaks.** Authorization is **server-owned**: `App\Domain\Tenancy\AccessResolver` (called from controllers / Livewire components / Filament resources) computes the access decision for every row in the response and the envelope carries an explicit per-row `access_provenance` field — one of `tenant_local`, `binding:<binding_id>:<scope_type>`, or `sharing:<sharing_scope>:<owner_tenant>` (spec §5). Each row's provenance is signed (HMAC over `(tenant_id, row_id, actor, provenance, timestamp)` with a server-rotating secret); Livewire 4 components rendering server-side already trust server-computed provenance — no client-side verification needed there. Client-side verification only matters for the vanilla JS islands (xterm/noVNC/Chart/TipTap), which use a tiny vanilla-JS helper to verify the HMAC before rendering and discard rows whose provenance fails verification, but do **not** decide authorization themselves. If the server emits a row without provenance, or with provenance that doesn't pass server-side re-verification on the next round-trip, the island emits a sentinel audit call and the server logs `tenant.cross_access` (`result=denied, reason=missing_or_invalid_provenance`). Legitimate cross-tenant rows (shared catalog templates, multi-tenant binding access) carry the matching provenance and render normally; their access is audited via the standard `tenant.cross_access` path at server-side issuance time, not at client-side render time.
- **Tenant context on every Horizon job and broadcast event.** Every Horizon job payload and every Reverb-broadcast event carries an explicit `tenant_id`. Worker handlers re-establish the tenant context from the job payload via `BindTenantContext` middleware, not from any inherited request state.
- **Cross-tenant access audited.** Every cross-tenant read emits a `tenant.cross_access` access variant with actor tenant, resource tenant, binding scope, binding id, action, and outcome. Every cross-tenant binding, token, or share-link issuance emits a `tenant.cross_access` issuance variant. Both variants are bidirectionally surfaced to actor tenant and resource-owner tenant.
- **Issuance containment.** A `multi_tenant` or `global` role binding can only be issued by an actor holding a binding of equal or broader scope. Attempts to escalate emit `tenant.cross_access` with `result=denied, reason=insufficient_scope`.
- **Quota isolation.** Cross-tenant resource uses count against the **consumer** tenant's quota, never the owner's. Quota tables carry the consumer-tenant FK.
- **No cross-tenant cache spillover.** Cached query state (Livewire component state, Laravel cache backend) is keyed by tenant; cache invalidation respects tenant boundaries.
- **Backups partition by tenant.** Per-tenant backup/restore is supported even though the underlying DB is shared (one schema). Backup tooling filters by `tenant_id` for most tables; for `AuditEvent` (which uses the three-tenant `actor_tenant` / `resource_tenant` / `target_tenant_set` schema per PRD §19 Audit), the backup query uses `actor_tenant = :tenant OR resource_tenant = :tenant OR :tenant IN target_tenant_set`.

## Upload security

Large-file uploads (ISOs, OVAs, stack tarballs) go through the FilePond chunked-upload protocol with the upload-session invariants in PRD §15. Concrete security requirements:

- **Server-generated random transfer IDs.** Never trust client-supplied identifiers.
- **`UploadSession` row carries the actor tenant and quota check at session creation.** No quota → no session → no upload. (M0 enforces only the tenant-identity portion of this gate; the full per-(scope, dimension) quota check lands with the quota framework in M6.)
- **Offset locking via Postgres advisory locks** prevents racing chunk writes from interleaving.
- **TTL cleanup** aborts abandoned sessions (filesystem temp file deletion; S3 `AbortMultipartUpload`).
- **Quarantine flag (`Artifact.quarantined`) is true on insert**; scanner pipeline (ClamAV for unknown blobs; `qemu-img info` + format validator for ISO/OVA) clears the flag before the artifact becomes referenceable.
- **MIME magic sniffing** via PHP's `finfo` (or an equivalent libmagic-backed library) rejects declared/actual mismatches before scan.
- **Archive / zip-bomb limits** enforce extracted-size caps before processing tarballs and zips.
- **Filename + path sanitisation**: in-flight chunks are keyed by `transfer_id`; finalized artifacts are keyed by `<tenant_id>/<artifact.kind>/<sha256>`; original filename is kept as metadata.
- **sha256 verification**: computed during streaming for filesystem backend; post-upload via `GetObject` for S3. Optional client-declared sha256 is a preflight hint; session refuses to complete if hashes don't match.
- **No direct-to-storage uploads bypassing Laravel.** Even when the backend is S3-compatible, the Laravel app is the upload coordinator — quota and tenant context are enforced before any storage write.

## Markdown And UI

Requirements:

- User-supplied markdown sanitized.
- HTML injection blocked unless explicitly trusted by admin policy.
- CSRF protection for browser flows.
- Secure cookie settings.
- Permission checks on every AJAX fragment endpoint, Reverb channel auth callback, and replay endpoint.

## Provider Safety

Requirements:

- Provider credentials never returned to clients.
- Provider API calls are audit logged.
- Provider actions are scoped to configured pools, nodes, storages, and networks.
- Placement never selects disabled or unhealthy providers.
- Reconciliation detects unmanaged drift.
