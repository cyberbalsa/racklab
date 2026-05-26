# Audit, Logging, And Observability

Strong audit and logging are top-level product requirements.

## Audit Requirements

RackLab must keep a structured, searchable audit trail for user actions, system decisions, provider changes, and worker execution.

Audit records should include:

- Actor.
- Actor type.
- **Actor tenant** (the tenant context the actor was operating in).
- **Resource tenant** (the tenant that owns the target object; differs from actor tenant for cross-tenant access).
- Effective permissions.
- Request id.
- Correlation id.
- Target object.
- Scope.
- Action.
- Result.
- Timestamp.
- Source IP/user agent where applicable.
- Token grant id where applicable.
- Provider task id where applicable.
- Script digest where applicable.
- Before/after summary where applicable.
- **`prev_hash` + `hash`** — tamper-evident hash chain. Each new audit row's `hash = sha256(prev_hash || canonical_json(this_event))`. The `manage.py verify_audit_chain` command walks the chain and refuses on any mismatch. Forensic-grade integrity; not a substitute for transport security but catches in-place row tampering.
- **`binding_scope`** (`tenant_local` | `multi_tenant` | `global`) and **`binding_id`** when the action was authorised by a `RoleBinding` (per PRD §19 cross-tenant RBAC).
- **`sharing_scope`** (`tenant_local` | `shared_with_tenants` | `global`) and **`shared_resource_owner_tenant`** when the action accessed a resource via cross-tenant sharing (per PRD §19 cross-tenant resource visibility).

### tenant.cross_access

A first-class audit event. Two variants:

- **Access variant** — actor in tenant A acts on a resource owned by tenant B (regardless of whether authorisation came from a `multi_tenant` / `global` `RoleBinding` or from the resource's `shared_with_tenants` / `global` `sharing_scope`). Payload: `actor_tenant`, `resource_tenant`, `binding_scope` (nullable if sharing-driven), `binding_id` (same), `sharing_scope` (nullable if binding-driven), `shared_resource_owner_tenant` (same), action, result (`allowed` | `denied`), `reason` (`insufficient_scope` | `no_sharing` | `sharing_revoked` | `allowed` | `missing_or_invalid_provenance` | etc.).
- **Issuance variant** — actor in tenant A attempts to *issue* something cross-tenant (a `multi_tenant` / `global` `RoleBinding`, a cross-tenant API token, a cross-tenant share-link). There's no resource_tenant in the access-variant sense here — the act of issuance is the violation. Payload: `actor_tenant`, `issuance_target` (the entity being issued — `role_binding` / `token_grant` / `share_link`), `target_scope_type` (the scope the actor tried to grant — `multi_tenant` / `global`), `target_tenant_set` (the tenants the actor tried to grant access to), `actor_held_scope` (what the actor actually held — typically `tenant_local`), action (`issue` / `revoke`), result (`allowed` | `denied`), `reason` (`insufficient_scope` for the canonical containment failure, or `allowed` for legitimate cross-tenant issuance by an actor with sufficient authority).

Bidirectional surfacing is achieved at query time: the audit query interface filters `AuditEvent` rows where `actor_tenant = :viewing_tenant OR resource_tenant = :viewing_tenant OR :viewing_tenant IN target_tenant_set` (per the variant). The `AuditEvent` table is indexed on each of these columns to keep the query fast; PRD §19 specifies the index set. Both the actor's tenant view and the resource owner's tenant view see access-variant events; tenants in the `target_tenant_set` see issuance-variant events (so a partner school can audit "RIT just granted itself global access to our resources").

## Required Audit Events

Auth:

- Login.
- Logout.
- Failed login.
- Guest-link access.
- OAuth/OIDC account linking.
- SAML account linking.

RBAC:

- Role grant.
- Role removal.
- Project sharing.
- Permission denial.
- Catalog publishing approval.

Quota:

- Reservation.
- Usage change.
- Denial.
- Override.
- Expiration.

Deployment:

- Request.
- Scheduling decision.
- Provider selection.
- Lifecycle transition.
- Failure.
- Retry.
- Rollback.
- Cleanup.

Provider:

- Proxmox API action.
- Target node.
- VMID.
- Task ID/UPID.
- Result.
- Elapsed time.

Network:

- Network, subnet, port, router, floating IP, and security group create/update/delete.
- Provider mapping.
- Attachment.
- Detachment.

Script:

- Creation.
- Edit.
- Approval.
- Approval invalidation.
- Execution start/end.
- Runner profile.
- Digest.
- Exit status.
- Logs.
- Artifacts.
- Screenshots.
- Serial output.

Console:

- Session creation.
- User.
- Target VM.
- Scope.
- Start/end time.
- Shared-console access.

Admin/system:

- Settings changes.
- Plugin install/enable/disable.
- Worker registration.
- Health changes.
- Token creation/use/denial/revocation.

Tenancy (per PRD §19):

- `tenant.cross_access` (both `allowed` and `denied` outcomes; see "tenant.cross_access" subsection above).
- `tenant.created` / `tenant.updated` / `tenant.deleted`.
- `tenant.membership.added` / `tenant.membership.removed`.
- `tenant.binding.issued` (when a `multi_tenant` or `global` `RoleBinding` is created; carries granter, `binding_scope`, `tenant_set`, `granted_reason`).
- `tenant.binding.revoked` (same).
- `tenant.sharing_scope.changed` (when a resource's `sharing_scope` moves between `tenant_local` / `shared_with_tenants` / `global`).

## Logging

Logging requirements:

- Structured JSON logs.
- Correlation IDs across HTTP request, DB job, NATS message, worker execution, provider API task, and UI-visible event.
- High-value audit events stored in PostgreSQL.
- Verbose job logs/artifacts stored in object/file storage with metadata in PostgreSQL. The storage backend is a plugin contract (PRD §13 "Storage backend contract"); the core ships a filesystem backend, S3 / GCS / Azure backends are plugins. Backends emit pipeline hooks at every state change (PRD §13 "Hookspec Catalog" → Storage pipeline).
- Secret redaction for tokens, passwords, cloud-init sensitive fields, provider credentials, and script secrets.
- Per-deployment event timelines visible according to RBAC.
- Admin audit search, filter, and export.

## Observability

Metrics and traces should support Prometheus/OpenTelemetry-compatible systems.

Required signals:

- Request latency.
- Error rates.
- Queue depth.
- Worker health.
- Worker concurrency.
- Provider health.
- Deployment latency.
- Deployment failure rates.
- Script failure rates.
- Quota pressure.
- NATS health.
- PostgreSQL health.
- Artifact storage health.
