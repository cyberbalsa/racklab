# Data Model Outline

This is a high-level model outline, not a final migration design. Three cross-cutting abstractions are load-bearing and called out first: **multi-tenancy** (every tenant-scoped row carries a tenant FK, with denormalized `tenant_id` columns on hot tables), the **universal `Job` ledger** that every queued or worker-executed unit of work lives in, and the **generic `Artifact` store** that every binary or large-blob output lives in. Provider-specific, script-specific, console-specific, and docs-specific details are subtypes of these abstractions, not parallel tables.

## Multi-tenancy

`Tenant` is the top-level scoping object — RIT is the default tenant; partner schools or RIT departments running their own catalogs are separate tenants. All tenant-scoped models carry a `tenant` FK; the universal `Job` ledger, `Artifact`, `Deployment`, `Reservation`, and `AuditEvent` additionally carry an **immutable denormalized `tenant_id` column** populated on insert. Denormalisation lets audit queries, retention sweeps, quarantine flows, and cross-tenant accounting partition cleanly without joining through the scoping row. Updating `tenant_id` post-insert raises a model-level validation error.

Soft isolation is enforced by tenant-aware managers + RBAC, not by schema separation. One Postgres schema, one migration graph, one backup.

Cross-tenant access has two dimensions:

- **Resource visibility** — each tenant-scoped resource declares a `sharing_scope`: `tenant_local` (default, visible only to the owning tenant), `shared_with_tenants` (explicit allow-list), or `global` (visible to all tenants). Sharing grants `use`, not `modify`; modifications stay with the owning tenant. Quota for cross-tenant uses counts against the **consumer** tenant.
- **Actor scope** — `RoleBinding.scope_type` is `tenant_local` (default), `multi_tenant`, or `global`. A `multi_tenant` binding carries `RoleBinding.tenant_set` enumerating the tenants it covers. Issuance is contained: a granter cannot create a binding with scope broader than their own. Permission evaluation composes three predicates: binding scope ⊇ resource tenant AND resource visibility ⊇ actor tenant AND role ⊇ requested action. All three must pass.

Every cross-tenant access emits a `tenant.cross_access` audit event, regardless of which dimension authorised it:

- **Binding-driven** — actor uses a `multi_tenant` or `global` `RoleBinding` on a resource in a tenant they are not a member of. Payload: `actor_tenant`, `resource_tenant`, `binding_scope`, `binding_id`, `sharing_scope=null`, outcome.
- **Sharing-driven** — actor in tenant A accesses a resource with `sharing_scope = shared_with_tenants ⊇ A` or `sharing_scope = global`. Payload: `actor_tenant`, `resource_tenant`, `binding_scope=null`, `sharing_scope`, `shared_resource_owner_tenant`, outcome.
- **Both** — when the access path requires both a cross-tenant binding *and* a shared resource (rare but possible), both sets of fields are populated.

See PRD §14 audit catalog for the full event schema and the bidirectional surfacing rule (both the actor's tenant and the owner's tenant see the event).

Tenant context propagates via Python `contextvars` (not thread-locals) so it carries correctly through ASGI views, Channels consumers, and async hooks. Background NATS workers and scheduled commands do **not** inherit request context — every NATS message envelope and `Job` row must carry an explicit `tenant_id` field, and worker handlers re-establish the tenant context at the start of each message.

## Universal Job ledger

`Job` is the canonical queue + state-machine ledger for every unit of work dispatched onto NATS. The same row is referenced by audit events, by the scheduler-reconciler for stuck-job recovery, by the autoscaler for per-pool pending-count signal, and by the worker that actually executes the work.

Fields:

- `id`, `tenant_id` (denormalized, immutable, set at insert from the actor's tenant context), `kind` (`provider` | `script` | `console` | `notify` | `reconciler` | `docs` | `plugin`), `pool` (which worker pool consumed it), `idempotency_key`, `correlation_id`.
- `state` (`dispatching` → `pending` → `running` → `succeeded` | `failed` | `cancelled` | `expired`), `state_history` (ordered transitions).
- `dispatched_at`, `started_at`, `finished_at`, `deadline_at`, `attempts`, `worker_id`, `lease_owner`, `lease_expires_at`.
- `actor`, `project`, `course`, `deployment` references where applicable.
- `payload_ref` (pointer to immutable input payload; not the message bus payload — that's transient).
- `result_summary`, `error_kind`, `error_detail` (sanitized for UI; verbatim in audit row).
- `last_progress_at` for liveness detection.

Subtypes (Django multi-table inheritance: each subtype has its own table linked back to `Job` via a one-to-one parent link, so a reconciler query against `Job` hits one table but typed access to provider-specific UPID or script-specific runner-profile traverses to the subtype):

- `ProviderTask` — Proxmox UPID, target node, decoded `(node, pid, starttime, type, id, user)` UPID parts, provider-task-id for non-Proxmox providers.
- `ScriptRun` — script id + version, runner-profile reference, exit status, asciinema-cast artifact ref, log artifact ref, screenshot artifact refs.
- `ConsoleSession` — kind (`vnc`/`terminal`/`ssh`), target instance, recording artifact ref (nullable), idle/duration policy used.
- `Notification` — channel (email/plugin), template id, recipient set, delivery state per recipient.
- `ReconcilerTask` — reconciliation kind (drift-detect, expired-cleanup, stuck-job recovery), scope.
- `DocsImportJob` — docs-plugin background work (large image batch processing, full-text reindex).

Reconciliation and audit query `Job`; nothing above the queue layer knows which subtype it is unless it needs to.

## Universal Artifact store

`Artifact` is the generic store for any large blob: script logs, console recordings, screenshots, docs-plugin images, audit exports, backup snapshots metadata, etc.

Fields:

- `id`, `tenant_id` (denormalized, immutable, set at insert from the actor's tenant context), `kind` (`script_log` | `script_screenshot` | `script_serial` | `console_recording` | `docs_image` | `audit_export` | `backup_metadata` | `catalog_iso` | `catalog_template` | `catalog_disk_image` | `catalog_snippet` | `catalog_backup` | `other`), `content_type` (RFC 6838), `size_bytes`, `sha256`, `quarantined` (bool, defaults true; scanner pipeline clears). The `catalog_*` kinds map to Proxmox storage content types (`iso` / `vztmpl` / `images` / `snippets` / `backup`) and route to the `proxmox_shared` backend by default (configurable per kind via the `Artifact.kind → backend` routing table; PRD §13 storage backend contract).
- `storage_backend` (`filesystem` | `s3` | `s3_compatible` | `proxmox_shared` | plugin-contributed value), `storage_key`, `storage_uri` (canonical reference; not always a direct URL).
- `created_at`, `created_by` (actor or system reason), `retention_until` (computed from policy at create time, nullable for indefinite retention).
- `owner_scope` (`project_id` | `course_id` | `global` | `user_id` for guest artifacts), `rbac_visibility` (`scope_members` | `scope_admins` | `actor_only` | `public_with_token`).
- `legal_flags` — bitset: `contains_pii`, `secret_redacted`, `student_authored`, `recording_with_consent`, etc.
- `redaction_status` (`none` | `applied` | `failed_aborted` — see SSH plugin recording policy).

`ArtifactReference` ties an artifact to one or more domain objects (M:N). A single artifact can be referenced by a `Job` (the work that produced it), a `Deployment` (the deployment it documents), a `ConsoleSession` (the session it recorded), or a `Doc` (the document it embeds). References carry their own RBAC purpose (display, audit, export).

Retention sweep is a `ReconcilerTask` that runs on a schedule, marks expired artifacts as `pending_delete`, then deletes from the storage backend after a configurable grace period. Audit rows for `artifact.deleted` capture sha256 + last reference for forensic reconstruction.

The previously-named tables `ScriptArtifact` and `LogArtifact` collapse into `Artifact` with appropriate `kind` values; their use sites become `ArtifactReference` rows.

## Identity And Scope

- `Tenant` (top-level — RIT, partner institutions, RIT departments)
- `TenantMembership` (user ↔ tenant ↔ primary-tenant flag; a user can belong to multiple tenants)
- `Organization`
- `Course`
- `Enrollment`
- `UserProfile`
- `GuestLink`
- `Role`
- `Permission`
- `PermissionPack`
- `RolePreset`
- `RoleBinding` (extended with tenant-scope dimension — see below)
- `Group`

`Permission` stores a stable codename plus structured metadata: namespace, resource, action, and description. Every platform resource gets `read`, `create`, `update`, and `delete` permissions; operation-specific permissions are added alongside CRUD when the domain needs them.

`PermissionPack` is a reusable permission tree: it can contain direct permissions and child packs. `RolePreset` is a named starting point composed from packs and direct permissions. `Role` can bind direct permissions, packs, and an optional preset; effective permissions are expanded from the tree and written to audit/debug surfaces so admins can explain why access was granted.

`RoleBinding` assigns one role to one principal (`user`, `group`, API credential, service identity, or guest grant) within one resource scope (`global`, `organization`, `course`, `project`, `catalog_item`, `catalog_version`, `deployment`, `network`, `script`, or `token_grant`) **and one tenant-scope dimension** (`tenant_local` — applies in a single tenant; `multi_tenant` — applies across the enumerated `RoleBinding.tenant_set`; `global` — applies across every tenant). `RoleBinding` carries `granted_by` (the issuing user) and `granted_reason` (text justification) for audit. Exact-scope bindings combine with global bindings during access checks; the tenant-scope dimension is checked independently — see the "Multi-tenancy" section at the top of this PRD for the three-predicate evaluation and issuance-containment rules.

## Projects And Sharing

- `Project`
- `ProjectMembership`
- `ShareGrant`
- `Invitation`

## Catalog

- `CatalogItem`
- `CatalogVersion`
- `StackDefinition`
- `StackComponent`
- `ConsoleInstructionPanel`
- `CatalogApproval`

## Deployments

- `Deployment` — carries denormalized `tenant_id` (immutable, set at insert).
- `DeploymentResource`
- `DeploymentStateTransition`
- `DeploymentEvent` (carries `id` for SSE `Last-Event-ID` replay)
- `Lease`
- `Snapshot`

## Providers

- `Provider`
- `ProviderEndpoint`
- `ProviderCluster`
- `ProviderNode`
- `ProviderStorage`
- `ProviderNetworkBinding`
- `ProviderCapacitySnapshot`
- `ProviderTask` — **subtype of `Job`** (see Universal Job ledger above)

## Networking

- `ProviderNetwork`
- `NetworkOffering` — carries the **`reachability` capability** (see Networking PRD §09 and SSH plugin §23): `routable_from_management`, `nat_from_management`, `isolated_no_ingress`. Determines whether SSH-into-VM is offered for deployments using this network.
- `Network`
- `Subnet`
- `SubnetPool`
- `Port`
- `Router`
- `FloatingIP`
- `SecurityGroup`
- `SecurityGroupRule`

## Quotas

- `QuotaPolicy`
- `QuotaLimit`
- `QuotaReservation` — carries denormalized `tenant_id` (the **consumer** tenant for cross-tenant resource uses).
- `QuotaUsage`
- `QuotaEvent`

## Jobs And Workers

- `Job` — see Universal Job ledger above. Subtypes: `ProviderTask`, `ScriptRun`, `ConsoleSession`, `Notification`, `ReconcilerTask`, `DocsImportJob`.
- `JobStep` — fine-grained progress for long-running Jobs (clone phases, multi-step scripts).
- `Worker` — registered worker process instance.
- `WorkerHeartbeat`
- `WorkerQueue`

## Scripting

- `Script`
- `ScriptVersion`
- `ScriptApproval`
- `ScriptRun` — **subtype of `Job`**.
- `RunnerProfile`
- Script outputs land in `Artifact` rows with `kind` ∈ `{script_log, script_screenshot, script_serial}`.

## Console

- `ConsoleSession` — **subtype of `Job`**. Console recordings land in `Artifact` with `kind = console_recording`.
- `SSHCredentialBinding` (SSH plugin only — see PRD §23).
- `UserSSHKey` (SSH plugin only — see PRD §23).

## Docs (plugin)

- `Doc`
- `DocVersion`
- `DocImage` — references an `Artifact` with `kind = docs_image`.

## API Tokens

- `TokenGrant` — carries `tenant_id` (denormalized, immutable, set at issuance from the issuer's tenant context), `track` (`jwt` for Track A / `pat` for Track B per PRD §6/§7), `bearer_hash` (bcrypt-style hash for Track B; nullable for Track A which is verified by signature), `scope_type` (`tenant_local` / `multi_tenant` / `global` — mirrors `RoleBinding.scope_type`), `tenant_set` (allow-list for `multi_tenant` tokens; empty for the others), plus the standard grant metadata (name, owner, type, created/expiration/last-used/revoked timestamps, allowed_ips_cidrs, delegated permissions and roles, project/course scope, `jti`-equivalent identifier, audit metadata).
- `TokenUse`
- `TokenRevocation`
- `SigningKey` — for Track A JWT signing.

## Plugins

- `Plugin`
- `PluginSetting`
- `PluginSecretRef`
- `PluginHealth`
- `PluginCapability`
- `PluginLifecycleState` (see PRD §13: `installed` → `migrated` → `enabled` → `disabled` → `pending_uninstall`)
- `PluginMigrationRecord` — every plugin migration applied or rolled back, with version range and outcome.

## TLS / ACME

- `TLSConfig` (current state of the §System Settings → TLS panel; versioned for rollback)
- `TLSConfigVersion`
- `CertEvent` (renewal success/failure events parsed from Traefik logs or `lego` exit, with verbatim upstream error in audit and sanitized text for UI)

## Audit

- `AuditEvent` — carries denormalized `actor_tenant` and `resource_tenant` (both immutable, set at insert; `resource_tenant` is nullable for issuance-variant events per PRD §14 `tenant.cross_access`), plus `target_tenant_set` (JSONB list of tenant IDs the event is relevant to — populated for issuance-variant events so the audit query interface can surface them to tenants in the set), plus `prev_hash` + `hash` columns for tamper-evident hash chaining (per PRD §14). Indexes on `actor_tenant`, `resource_tenant`, and a GIN index on `target_tenant_set` keep the bidirectional-surfacing query (`actor_tenant = :viewing_tenant OR resource_tenant = :viewing_tenant OR :viewing_tenant IN target_tenant_set`) fast. The single legacy `tenant_id` field is replaced by `actor_tenant` (the existing denormalized tenant on hot tables stays on `Job` / `Artifact` / `Deployment` / `Reservation` — `AuditEvent` is the one model that needs both because of bidirectional surfacing).
- `AuditExport` — references an `Artifact` with `kind = audit_export`.

## Uploads

- `UploadSession` — chunked-upload session state per active multi-chunk upload. Fields: `id` (server-generated random transfer ID, UUID4), `tenant_id`, `actor`, `artifact_kind`, `declared_filename`, `declared_mime`, `declared_size`, `current_offset`, `expected_total`, `chunk_count`, `state` (`active` | `complete` | `aborted` | `expired`), `created_at`, `last_chunk_at`, `expires_at`, `backend_handle` (`{kind: "filesystem", temp_path: ...}` or `{kind: "s3", multipart_upload_id: ..., parts: [...]}`), `client_declared_sha256` (preflight hint), `computed_sha256` (post-upload). Postgres advisory lock per `(id)` serialises chunk writes. TTL cleanup reaper aborts abandoned sessions (filesystem: delete temp files; S3: `AbortMultipartUpload`). See PRD §15 "File uploads" and PRD §18 "Upload security" for the protocol + invariants.

## i18n

- `TranslationCoverage` (per-locale stats for the admin coverage page)
- `PluginTranslationCatalog` (registered catalogs from plugins)
