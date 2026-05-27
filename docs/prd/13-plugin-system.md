# Plugin System

> **Note:** Implementation detail for the plugin system stack choices in this section (PluginRegistry mechanics, HookDispatcher semantics, typed event class catalog locations, Composer auto-discovery overrides) lives in `docs/superpowers/specs/2026-05-26-laravel-redesign.md` §6. This document captures the plugin contract — what plugins can do, what hooks exist, what the lifecycle states are; the spec is the source of truth for how the framework dispatches them.

RackLab is plugin-first. The core defines stable contracts and lifecycle hooks; plugins add capability without replacing the control plane.

## Plugin Families

Plugin families:

- Provider backends.
- Networking backends.
- Console backends.
- **Storage backends** (artifact storage — filesystem, S3-compatible, GCS, Azure Blob, etc.; see "Storage backend contract" below and PRD §14).
- Script runners.
- Auth integrations.
- Catalog import/export.
- Notification channels.
- Quota policies.
- Placement strategies.
- Audit sinks.
- Secret backends.
- **Theme plugins** (versioned, installable branding/theme packages per PRD §15).
- **LMS integrations** (LTI 1.3 + Advantage per the library survey §13; ships as plugin, not core).

Initial plugins:

- `racklab/plugin-provider-proxmox`
- `racklab/plugin-console-proxmox` (supports both Proxmox noVNC for KVM graphical consoles and xterm.js for LXC and serial consoles)
- `racklab/plugin-console-ssh` (browser-based SSH terminal for any reachable VM; see [SSH Plugin](23-ssh-plugin.md))
- `racklab/plugin-script-cloudinit`
- `racklab/plugin-script-console-openqa`
- `racklab/plugin-script-ansible`
- `racklab/plugin-auth-sanctum-ext` (optional Sanctum auth extensions, e.g., SSO provider bridging)
- `racklab/plugin-notify-email`
- `racklab/plugin-audit-jsonlog`
- `racklab/plugin-quota-default`
- `racklab/docs-plugin` (see [Docs Plugin](22-docs-plugin.md) — exercises the full plugin contract and defines its own extension point for other plugins to register cross-link resolvers)
- `racklab/storage-proxmox-shared` — artifact backend that tunnels storage onto the Proxmox cluster's shared storage (Ceph-backed PVE pools are the headline case, but any Proxmox-managed storage type is supported via the `pvesm` abstraction: CephFS, NFS, GlusterFS, ZFS-over-iSCSI, even per-node local storage configured as shared). See "Proxmox shared storage backend" below.

## Discovery And Contracts

Requirements:

- Plugins are **Composer packages** installed through `composer install` / `composer require`. Each plugin's `composer.json` declares `"extra.racklab.plugin": true`; this is the signal the custom `App\Plugins\PluginRegistry` uses to recognise RackLab plugins. Laravel's standard package auto-discovery is **disabled** for RackLab plugins (each plugin sets `"extra.laravel.dont-discover": ["*"]` in its `composer.json`) so that an installed-but-not-enabled plugin does not register routes, migrations, or listeners on app boot.
- Discovery is managed by the **custom `App\Plugins\PluginRegistry`** (booted from `PluginServiceProvider`), which reads `PluginInstallation` rows from the DB to determine which plugins are in state `enabled`. Only `enabled` plugins have their declared ServiceProviders instantiated and booted.
- Hook contracts use the **typed `App\Plugins\HookDispatcher`** — not raw `Event::dispatch()` or `Event::until()`. Plugins subscribe by tagging listener classes with `#[ListensTo(Hookspec\Domain\VerbEvent::class)]`. Direct dispatcher calls from plugin code outside `app/Plugins/` are caught by a Larastan rule that fails CI.
- Plugin APIs are versioned.
- Plugins declare supported RackLab API versions.
- Plugins declare capabilities.
- Plugins declare required settings and secrets.
- Plugins declare health checks.
- Plugins declare permissions they add.
- Plugins declare migration needs if they contribute Eloquent models, plus their migration dependency on other plugins and on RackLab core models.

## Plugin Lifecycle

Plugins go through an explicit, named lifecycle. Laravel's stock model (whatever is auto-discovered runs on `artisan migrate`) is too loose for runtime-enableable plugins that contribute models; RackLab manages a higher-level state machine on top.

States:

1. **installed** — the Composer package is on disk (`composer install` completed), `"extra.racklab.plugin": true` is declared, and the `PluginRegistry` has recorded it in the `PluginInstallation` table, but RackLab has not loaded it.
2. **migrated** — the plugin's migrations have been applied. Models exist in Postgres; the plugin's ServiceProvider is not yet booted for the running RackLab processes.
3. **enabled** — the plugin's ServiceProvider is booted, its hookspec listeners are registered via `HookDispatcher`, its Filament resources and routes are mounted, its workers are eligible for scheduling, and its health check is reported.
4. **disabled** — the plugin's listeners are unregistered and its admin/routes are unmounted, but its models remain in Postgres and its migrations are not reversed. A disabled plugin can be re-enabled instantly.
5. **pending_uninstall** — the plugin is marked for migration rollback. Rollback runs in a separate step; until it completes, the plugin remains in this state.

Commands:

- `php artisan racklab:plugin install <slug>` — sanity-checks the Composer package + capability declarations + version constraints. State becomes `installed`. No DB changes.
- `php artisan racklab:plugin migrate <slug>` — runs the plugin's forward migrations against Postgres. Verifies declared migration dependencies on RackLab core and on other enabled plugins; refuses if a dependency isn't satisfied. State becomes `migrated`.
- `php artisan racklab:plugin enable <slug>` — writes the plugin into RackLab's persisted `PluginInstallation` row (NOT `config/*.php`); the next process startup boots its ServiceProvider and registers its listeners. Triggers a controlled restart of `web` and the worker pools the plugin contributes to. State becomes `enabled`.
- `php artisan racklab:plugin disable <slug>` — marks the plugin disabled in the `PluginInstallation` row; next startup skips its ServiceProvider. Triggers the same controlled restart. State returns to `migrated`.
- `php artisan racklab:plugin rollback <slug> --to-version=<ver>` — runs reverse migrations from the current applied version down to the target version. Refuses if other enabled plugins depend on schema introduced after the target. Plugin must be `disabled` first. State becomes `migrated` at the new target version.
- `php artisan racklab:plugin uninstall <slug>` — runs reverse migrations all the way to zero, then removes plugin metadata. Plugin must be `disabled` first. After uninstall, removing the package from the Composer vendor tree is the operator's separate step. State becomes `installed`-with-no-migrations (effectively gone).

Discipline:

- **`PluginRegistry` is evaluated at process startup** from `PluginInstallation` rows plus the core service providers. Enable/disable both require a controlled restart; this is the right operational tradeoff because hot-loading service providers with models is a known footgun (signal connections, provider boot-ordering, model registry consistency). For already-running Octane workers, `PluginRegistry::bootPlugin($slug)` triggers a graceful Octane reload.
- **Controlled restart** for the Baseline profile uses systemd (`systemctl restart racklab-web racklab-worker@*` with graceful drain). For the Scale profile, Nomad's `update` stanza handles the rolling restart of web + worker jobs; the admin GUI surfaces the rollout state.
- **Migration dependency declarations** are checked at `migrate` time. A plugin may declare `requires_core_version >= "1.2"` (RackLab core model version) and `requires_plugin "racklab/plugin-foo" >= "0.4"`. Unsatisfied dependencies abort with a clear error before any schema changes.
- **Migration runtime budget**: plugin migrations should complete in under 30 seconds against a typical deployment. Longer migrations declare themselves as such and run in maintenance mode (web tier returns 503 for non-admin endpoints; admin can monitor migration progress).
- **Rollback semantics**: every forward migration must have a working reverse. CI in the plugin's own repo verifies the migration round-trips on a representative database.
- **State machine persistence**: `PluginInstallation` and `PluginMigrationRecord` Eloquent models in PRD §19 are the source of truth. The admin Plugin Management page (PRD §15) renders the state and exposes the lifecycle commands.

Audit events fire at every state transition: `plugin.install`, `plugin.migrate.start/success/failure`, `plugin.enable.start/success/failure`, `plugin.disable`, `plugin.rollback.start/success/failure`, `plugin.uninstall`.

## Controlled Extension Points

Plugins contribute through controlled extension points — they do not patch the core. The contributable surfaces are:

- **ServiceProvider** (Eloquent models, route files mounted under a plugin-specific prefix, Filament resources/pages, event listeners, Artisan commands). Validated at `enable` time against route-shadowing rules (plugins cannot register routes under `/admin`, `/api`, `/static`, `/media`, `/.well-known`).
- **Hookspec listeners** for the families declared in §1. Listeners are tagged with `#[ListensTo(…)]` and registered via `HookDispatcher` at `enable` time. Direct use of `Event::dispatch()` or `Event::until()` against hookspec event classes is caught by Larastan CI.
- **API routes** under the plugin's prefix, contributed via standard Laravel routing in the plugin's `routes/api.php`.
- **Worker pool requirements** declared as `WorkerPoolSpec` objects passed through the plugin's `Manifest::declareWorkerPools()` per the Podman orchestration spec §4.
- **Permission strings** added at `enable` time. Permission removal on `disable` strips the permission from role bindings.
- **Audit event schemas** declared at `enable` time and validated at emission.
- **Translation catalogs** registered at `enable` time and merged with the core catalogs (PRD §15 i18n).
- **Capability flags** that other code (especially the catalog validator) consumes.

### Failure isolation rules

The PRD goal is that one plugin's failure doesn't break the control plane. Failures map to states:

- **Boot-time failure** at process startup (`ServiceProvider` exception, missing Composer dependency): the plugin is flagged unhealthy; its listeners are not registered; its admin pages are not mounted. The web tier and worker pools start; an admin notification fires.
- **Migration failure** during `php artisan racklab:plugin migrate`: the command aborts with a non-zero exit. The database transaction guarantees no partial schema. The plugin stays in `installed`.
- **Hookspec listener raising at runtime**: the offending listener is recorded as unhealthy; subsequent dispatches to that listener are skipped. The host code path proceeds with remaining listeners. Repeated failures within a window auto-disable the plugin (configurable threshold) and emit an admin alert.
- **Worker pool registration failure**: the WorkerRuntime declares the pool failed; replicas already running continue, but no new ones start. Admin sees the failure in the Plugin Management page.
- **Admin page exception**: the plugin's admin URL returns the standard RackLab 500 page with a "report this plugin" link. Other admin pages are unaffected.
- **Static asset failure** (missing file referenced by a template): logged, page renders without the asset. Doesn't break the page.
- **Settings-schema validation failure** at startup: the plugin is loaded but flagged unhealthy; admin must fix configuration before its listeners are called.

Plugin failures never crash the whole control plane. Failed plugin health affects feature availability and placement eligibility — a failed provider plugin is excluded from deployment placement; a failed notification plugin causes notifications via that channel to error gracefully rather than block.

## Provider Plugin Requirements

Provider plugins must:

- Be idempotent and reconciliation-friendly.
- Expose inventory facts.
- Expose capability flags.
- Report health.
- Emit structured events.
- Preserve provider task identifiers.
- Avoid direct user-visible credential exposure.

## Storage backend contract

Artifact storage is a plugin family per the data model in PRD §19 (`Artifact.storage_backend`). The core ships a filesystem backend (`local-fs`); everything else (S3, S3-compatible, GCS, Azure Blob, MinIO, IPFS, etc.) is a Composer package implementing the `RackLab\Storage\Contracts\ArtifactBackend` PHP interface, which wraps Flysystem 3.34 and adds RackLab-specific concerns: tenant-prefixed paths, chunk-upload coordination, server-side checksum verification, and sharded artifact IDs.

Storage backends must:

- Implement the typed `RackLab\Storage\Contracts\ArtifactBackend` interface. Every method takes a `string $tenantId` parameter as its first argument so the backend can partition storage layout per tenant if it chooses (the upload coordinator always passes the actor's tenant; backends that don't partition just ignore the argument). The methods are: `put(string $tenantId, string $key, mixed $stream, string $contentType, array $metadata): string` (returns URI), `get(string $tenantId, string $key): mixed` (returns stream), `stat(string $tenantId, string $key): ArtifactStat`, `delete(string $tenantId, string $key): void`, `presignedUrl(string $tenantId, string $key, int $ttl): ?string`, `multipartInitiate(string $tenantId, string $key, string $contentType): MultipartHandle`, `multipartUploadPart(MultipartHandle $handle, int $partNumber, mixed $stream): string` (returns ETag; the handle already carries the tenant context from `multipartInitiate`), `multipartComplete(MultipartHandle $handle, array $parts): string` (returns URI), `multipartAbort(MultipartHandle $handle): void`, `health(): BackendHealth`.
- **Storage-key derivation**: the upload coordinator owns key derivation, not the backend. A finalized artifact's storage key is `<tenant_id>/<artifact.kind>/<sha256>` (after the upload completes, sha256 is known). During the in-flight upload, the chunks are addressed by the `UploadSession.id` (a UUID4 transfer ID) — that transfer ID is *not* the final storage key. The upload coordinator atomically renames `transfer_id` → `sha256`-keyed path on session completion (filesystem backend) or completes the S3 multipart with the sha256-based key (S3 backend). PRD §15 + §18's "storage key derived from `transfer_id`" wording refers to the *temporary in-flight key*; the final stored artifact is sha256-keyed.
- Honour the **upload-session invariants** from PRD §15: backends are called by the upload coordinator (Laravel controller), not directly by the FilePond client. Backends implementing S3-style multipart expose `multipart*` methods; backends without that capability fall back to streaming put + post-upload-hash verification (per PRD §18 upload security).
- Emit the **pipeline hooks** below at every state change so other plugins can extend the pipeline.
- Declare a **capability flag set**: `supports_presigned_urls`, `supports_multipart`, `supports_versioning`, `supports_object_lock`, `supports_byte_range`, `supports_tenant_partitioning`. The artifact subsystem checks these before issuing operations the backend doesn't support.
- Report **health** via the standard plugin health check.

First-party storage plugins ship as `racklab/storage-*` Composer packages. The laravel-redesign spec at `docs/superpowers/specs/2026-05-26-laravel-redesign.md` §6 standardises on Flysystem 3.34 adapters as the foundation for S3 / GCS / Azure plugins — each plugin presents a thin `ArtifactBackend` → Flysystem adapter rather than wiring Flysystem directly into core.

### Proxmox shared storage backend

`racklab/storage-proxmox-shared` is a first-party storage backend plugin that tunnels artifact bytes onto whatever shared-storage pool the Proxmox cluster is already running. The headline use case is Ceph (CephFS or RBD-via-`pvesm`) but the implementation is path-agnostic — it speaks the Proxmox storage abstraction (`pvesm`), which already unifies CephFS, NFS, GlusterFS, ZFS-over-iSCSI, and any other Proxmox-recognised pool. Operators configure the plugin with a list of Proxmox storage IDs and a content-type mapping from RackLab artifact kinds to Proxmox storage content types (`iso` / `vztmpl` / `backup` / `images` / `snippets`).

Why this matters: Proxmox VM clone operations source their template image from a storage pool reachable by the target node. If RackLab's catalog template image lives in `s3://racklab-artifacts/`, every VM clone has to round-trip the bytes through the management plane first. If it lives on the cluster's shared storage pool, the clone is a local pool-to-pool operation that runs at storage-fabric speed and doesn't load RackLab's network. Same logic for backup snapshots, cloud-init ISO snippets, and any other artifact that a Proxmox node needs to read while spinning up a VM.

The plugin uses the Proxmox API exclusively (via the Guzzle-based Proxmox client per the [Proxmox client discipline spec](../superpowers/specs/2026-05-24-proxmox-client-discipline.md) — the discipline applies; the underlying HTTP library is now PHP/Guzzle) — `POST /api2/json/nodes/{node}/storage/{storage}/upload` for writes, `GET /api2/json/nodes/{node}/storage/{storage}/content/{volume}` for reads, `DELETE` for retention. The plugin never bypasses the Proxmox API; that's the design point — Proxmox's authorisation, audit, and quota visibility stay intact, and operators don't need to grant the plugin filesystem-level access to the Proxmox hosts. Content types Proxmox doesn't accept via the upload endpoint (e.g., RackLab-internal artifact kinds like `console_recording` or `script_log`) simply route to a different storage backend — that's what the `Artifact.kind` → backend routing table is for. The Proxmox shared backend is the right home for VM-relevant artifacts (`iso`, `vztmpl`, `backup`, `images`, `snippets`); the filesystem or S3 backend is the right home for everything else.

Capabilities the plugin declares:

- `supports_presigned_urls = false` (Proxmox doesn't issue presigned URLs; the plugin proxies downloads through itself with short-lived signed share tokens per PRD §6).
- `supports_multipart = false` for the upload-endpoint path (Proxmox accepts a single multipart-encoded HTTP POST; large ISOs are chunked by the upload coordinator at the Laravel layer, then assembled before the Proxmox upload). The plugin exposes the chunked-receive API per PRD §15 §18 so FilePond's `Upload-Offset` flow still works; the final assembled file is then POSTed to Proxmox.
- `supports_versioning = false` (Proxmox storage is name-keyed; the plugin keys artifacts by sha256-prefixed filenames so collisions are content-identity, not name).
- `supports_object_lock = false`.
- `supports_byte_range = true` (the plugin proxies range reads).

Tenant-awareness: the plugin supports two layouts. Either (1) one shared Proxmox storage pool with tenant-prefixed keys (`<storage>/racklab-artifacts/<tenant>/<kind>/<sha256>`), or (2) per-tenant Proxmox storage pools mapped 1:1 (configurable per deployment). Layout (1) is the default for single-institution RackLab installs; layout (2) suits multi-institution deployments where storage isolation is desired (RIT's pool stays distinct from partner-school pools at the Ceph level). The `Artifact.tenant_id` denormalized column drives the layout decision per write.

`Artifact.storage_backend` gains the value `proxmox_shared` (see PRD §19 data model). The retention sweep `ReconcilerTask` calls the plugin's `delete` per usual; the plugin issues `DELETE /api2/json/nodes/{node}/storage/{storage}/content/{volume}` against Proxmox.

Operationally: the plugin requires a service account on the Proxmox cluster with the `Datastore.Audit` + `Datastore.AllocateSpace` + `Datastore.AllocateTemplate` + `Datastore.Allocate` privileges, scoped to the storage IDs configured for the plugin. Credentials live in the Secret Backend (PRD §13 family); rotation runs through the standard provider-credential admin GUI.

## Hookspec Catalog

Plugins extend RackLab through a broad set of typed hookspec events dispatched via `App\Plugins\HookDispatcher`. The catalog below is the v1 hookspec surface; new hookspecs are added by amending this catalog and emitting an `extension.hookspec.added` audit event (so plugin authors can detect new extension points). Every hookspec is versioned with the RackLab API version it was added in; breaking changes increment the major version.

The hookspec surface is filled in incrementally across the roadmap (storage / auth / RBAC / audit / plugin lifecycle hooks land in M0; deployment / job / quota hooks in M2; provider hooks in M3; etc.) — the catalog is the *target* shape, not the M0 shape.

Each hookspec is a **PHP 8 readonly class** under `app/Events/Hookspecs/<Domain>/<Verb>Event.php`. Plugins subscribe by tagging a listener class with `#[ListensTo(Hookspec\Domain\VerbEvent::class)]`. The `HookDispatcher` enforces dispatch semantics; plugins must not call `Event::dispatch()` or `Event::until()` against hookspec event classes directly.

**Conventions:**

- `racklab_<domain>_pre_<verb>` / `racklab_<domain>_post_<verb>` — fire before / after an operation. Pre-hooks are **Filter** style (sync, may abort); post-hooks are **Notification** style (async, fire-and-forget).
- `racklab_<domain>_<verb>_resolver` — **Resolver** style: first-non-null-wins (used where one of many plugins might handle a given input).
- `racklab_<domain>_<verb>_validator` — **Filter** style: listener may return a modified payload or throw `AbortException` to reject.
- `racklab_<domain>_<verb>_contributor` — **Contributor** style: each listener contributes 0..N entries to a result set (UI nav, dashboard cards, audit-event schemas, etc.).
- `racklab_<domain>_<verb>_sink` — **Notification** style: fan-out hooks (audit sinks, notification sinks).

**Four listener styles with explicit dispatch semantics:**

| Style | Examples | Sync/async | Mutation | Ordering | Failure | Timeout |
| --- | --- | --- | --- | --- | --- | --- |
| **Notification** (`post_*`, `_sink`) | `racklab_audit_post_emit`, `racklab_job_complete` | Async (Horizon) | None | Unordered | Isolated per listener; logged | Job-level (Horizon retry config) |
| **Filter** (`pre_*`, `_validator`) | `racklab_quota_pre_check`, `racklab_deployment_pre_create` | Sync | Listener may return modified payload or throw `AbortException` | Deterministic — listeners ordered by manifest-declared priority (default 1000), tie-break by plugin slug | Single failure aborts the operation (rolled back) | Per-listener wall-clock cap (default 500 ms; configurable per hookspec) |
| **Contributor** (`_contributor`) | `racklab_storage_backend_register`, `racklab_health_check` | Sync | Each listener contributes 0..N entries to a result set | Deterministic — manifest priority, tie-break by slug | One listener's failure does not prevent other contributions; failures surfaced in aggregated result | Per-listener cap (default 200 ms) |
| **Resolver** (`_resolver`) | `racklab_storage_backend_resolver`, `racklab_scheduler_placement_resolver` | Sync | First listener to return non-null wins; subsequent listeners short-circuited | Deterministic — manifest priority, tie-break by slug | Failure of the highest-priority resolver falls through to next | Per-listener cap (default 200 ms) |

**Critical guarantee — audit pre-emit must not block:** The `racklab_audit_pre_emit` hookspec is **enrichment-only**, not a Filter/validator. The `Audit\Appending` notification hook fires *after* the hash chain head has been computed and the row is queued for write. Plugins cannot delay or prevent audit emission. Pre-emit raising is logged and counted against the plugin's failure-window threshold; the audit row is written regardless. The notification fires only if the row's transaction commits.

**Storage pipeline:**

- `racklab_storage_backend_register` (**Contributor**) — `app/Events/Hookspecs/Storage/BackendRegisterEvent.php`; backends register themselves. Properties: `array $registeredBackends` (accumulated result set, keyed by backend slug).
- `racklab_artifact_pre_store` (**Filter**) / `racklab_artifact_post_store` (**Notification**) — fires around every `Artifact` row creation; pre can mutate `array $metadata` and `string $storageKey`; post sees `string $finalUri`. Properties: `string $tenantId`, `string $artifactKind`, `string $storageKey`, `array $metadata`.
- `racklab_artifact_pre_retrieve` (**Filter**) / `racklab_artifact_post_retrieve` (**Notification**) — fires around every read; pre can rewrite `string $storageKey` (e.g., for transparent decryption / CDN routing). Properties: `string $tenantId`, `string $artifactId`, `string $storageKey`.
- `racklab_artifact_pre_delete` (**Filter**) / `racklab_artifact_post_delete` (**Notification**) — fires around retention sweep + manual deletes. Properties: `string $tenantId`, `string $artifactId`, `string $storageKey`.
- `racklab_artifact_mime_sniff_resolver` (**Resolver**) — alternative MIME sniffers beyond the built-in `finfo` detection. Properties: `string $filename`, `mixed $stream`; returns `?string` (MIME type or null to fall through).
- `racklab_artifact_scan_handler` (**Contributor**) — scanners (ClamAV, `qemu-img info`, custom validators) contribute themselves; each runs against the quarantined artifact. Properties: `string $tenantId`, `string $artifactId`, `string $quarantinePath`; each listener contributes a `ScanResult`.
- `racklab_artifact_quarantine_clear` (**Filter** / validator) — fires when scanners return OK; plugins can hold the artifact quarantined for additional checks. Properties: `string $tenantId`, `string $artifactId`, `array $scanResults`.
- `racklab_artifact_retention_policy_resolver` (**Resolver**) — plugins resolve per-artifact-kind retention windows (PRD §14). Properties: `string $tenantId`, `string $artifactKind`; returns `?int` (retention days or null to fall through).
- `racklab_upload_session_pre_create` (**Filter** / validator) — additional gates (tenant policy, file-type allowlist, peer-quota check) before an `UploadSession` row is written. Properties: `string $tenantId`, `string $filename`, `int $fileSize`, `string $contentType`.
- `racklab_upload_chunk_received` (**Notification**) — every `PATCH` chunk fires this; plugins can run progressive validation (incremental checksums, streaming AV scan). Properties: `string $tenantId`, `string $uploadSessionId`, `int $chunkOffset`, `int $chunkSize`.
- `racklab_upload_session_complete` (**Notification**) / `racklab_upload_session_abort` (**Notification**) — fan-out for plugins that mirror to a secondary store, kick off post-processing pipelines, etc. Properties: `string $tenantId`, `string $uploadSessionId`, `?string $finalArtifactId`.

**Auth + tokens:**

- `racklab_auth_pre_login` (**Filter**) / `racklab_auth_post_login` (**Notification**) / `racklab_auth_pre_logout` (**Filter**) / `racklab_auth_post_logout` (**Notification**). Properties: `string $tenantId`, `string $userId`, `string $authMethod`.
- `racklab_token_pre_issue` (**Filter**) / `racklab_token_post_issue` (**Notification**) — both JWT and PAT tracks. Properties: `string $tenantId`, `string $userId`, `string $tokenType` (`jwt`|`pat`), `string $scope`, `array $requestedPermissions`.
- `racklab_token_pre_revoke` (**Filter**) / `racklab_token_post_revoke` (**Notification**). Properties: `string $tenantId`, `string $tokenId`, `string $tokenType`.
- `racklab_token_claims_contributor` (**Contributor**) — JWT plugins contribute extra claims (e.g., LMS launch context, partner-school identifier). Properties: `string $tenantId`, `string $userId`; each listener contributes `array<string, mixed>` extra claims.

**RBAC:**

- `racklab_rbac_permission_check` (**Resolver**) — first-non-null-wins; plugins can short-circuit allow/deny for specific permissions (e.g., a `compliance` plugin enforcing additional rules). Properties: `string $tenantId`, `string $userId`, `string $permission`, `?string $resourceId`; returns `?bool` (true=allow, false=deny, null=fall through).
- `racklab_rbac_binding_pre_issue` (**Filter**) / `racklab_rbac_binding_post_issue` (**Notification**) — auditing + custom escalation gates. Properties: `string $tenantId`, `string $grantorId`, `string $granteeId`, `string $roleSlug`, `string $scopeType`.
- `racklab_rbac_role_resolved` (**Notification**) — plugins observe role resolutions for their own audit/analytics. Properties: `string $tenantId`, `string $userId`, `string $roleSlug`, `bool $resolved`.

**Tenant:**

- `racklab_tenant_pre_create` (**Filter**) / `racklab_tenant_post_create` (**Notification**). Properties: `string $tenantSlug`, `string $displayName`, `string $actorId`.
- `racklab_tenant_membership_changed` (**Notification**) — fan-out when a user joins/leaves a tenant. Properties: `string $tenantId`, `string $userId`, `string $changeType` (`joined`|`left`|`role_changed`).
- `racklab_tenant_cross_access` (**Notification**) — audited cross-tenant access events; plugins can attach extra context (e.g., compliance reason codes). Properties: `string $actorTenantId`, `string $resourceTenantId`, `string $actorId`, `string $resourceId`, `string $accessVariant`.

**Deployment + Job:**

- `racklab_deployment_pre_create` (**Filter** / validator) / `racklab_deployment_post_create` (**Notification**). Properties: `string $tenantId`, `string $catalogItemId`, `string $actorId`, `array $parameters`.
- `racklab_deployment_state_transition_pre` (**Filter**) / `racklab_deployment_state_transition_post` (**Notification**). Properties: `string $tenantId`, `string $deploymentId`, `string $fromState`, `string $toState`.
- `racklab_deployment_pre_delete` (**Filter**) / `racklab_deployment_post_delete` (**Notification**). Properties: `string $tenantId`, `string $deploymentId`, `string $actorId`.
- `racklab_job_pre_dispatch` (**Filter**) / `racklab_job_post_dispatch` (**Notification**). Properties: `string $tenantId`, `string $jobId`, `string $jobKind`, `array $payload`.
- `racklab_job_state_transition_pre` (**Filter**) / `racklab_job_state_transition_post` (**Notification**). Properties: `string $tenantId`, `string $jobId`, `string $fromState`, `string $toState`.
- `racklab_job_complete` (**Notification**) / `racklab_job_fail` (**Notification**) / `racklab_job_retry` (**Notification**). Properties: `string $tenantId`, `string $jobId`, `?string $errorMessage`.
- `racklab_job_progress` (**Notification**) — workers fire as they advance through `JobStep`s. Properties: `string $tenantId`, `string $jobId`, `string $stepName`, `int $stepIndex`, `int $totalSteps`.

**Quota:**

- `racklab_quota_pre_check` (**Filter**) / `racklab_quota_post_check` (**Notification**). Properties: `string $tenantId`, `string $resourceKind`, `int $requestedAmount`, `string $actorId`.
- `racklab_quota_pre_reserve` (**Filter**) / `racklab_quota_post_reserve` (**Notification**). Properties: `string $tenantId`, `string $resourceKind`, `int $reservedAmount`, `string $reservationId`.
- `racklab_quota_pre_release` (**Filter**) / `racklab_quota_post_release` (**Notification**). Properties: `string $tenantId`, `string $resourceKind`, `int $releasedAmount`, `string $reservationId`.
- `racklab_quota_policy_resolver` (**Resolver**) — alternative quota policies (e.g., a `lab-fair-share` plugin overriding the default). Properties: `string $tenantId`, `string $resourceKind`; returns `?QuotaPolicy`.

**Provider:**

- `racklab_provider_pre_call` (**Filter**) / `racklab_provider_post_call` (**Notification**) — fires around every Proxmox API call (or equivalent in other providers); useful for telemetry, retry policies, circuit breakers. Properties: `string $tenantId`, `string $providerSlug`, `string $method`, `string $endpoint`, `array $params`.
- `racklab_provider_drift_detected` (**Notification** / sink) — reconciler fires when unmanaged state is found. Properties: `string $tenantId`, `string $providerSlug`, `string $resourceId`, `array $observedState`.
- `racklab_provider_capacity_snapshot` (**Contributor**) — additional capacity sources beyond the built-in provider polling. Properties: `string $providerSlug`; each listener contributes a `CapacitySnapshot`.

**Networking:**

- `racklab_network_pre_attach` (**Filter**) / `racklab_network_post_attach` (**Notification**). Properties: `string $tenantId`, `string $deploymentId`, `string $networkOfferingId`.
- `racklab_network_offering_resolve_reachability` (**Resolver**) — given a `NetworkOffering`, return its reachability capability (drives SSH plugin behaviour per PRD §23). Properties: `string $tenantId`, `string $networkOfferingId`; returns `?string` (reachability enum value or null to fall through).
- `racklab_network_pre_realize` (**Filter**) / `racklab_network_post_realize` (**Notification**) — fires when self-service networks are pushed to the provider. Properties: `string $tenantId`, `string $networkId`, `string $providerSlug`.

**Console + SSH:**

- `racklab_console_pre_grant` (**Filter** / validator) / `racklab_console_post_grant` (**Notification**). Properties: `string $tenantId`, `string $deploymentId`, `string $actorId`, `string $consoleType`.
- `racklab_console_pre_disconnect` (**Filter**) / `racklab_console_post_disconnect` (**Notification**). Properties: `string $tenantId`, `string $sessionId`, `string $actorId`.
- `racklab_ssh_session_recording_chunk` (**Filter**) — redaction-pipeline plugins fire on every byte chunk before it lands in the recording artifact. Properties: `string $tenantId`, `string $sessionId`, `string $chunk`; listener may return a redacted `string` replacement or null to pass through unchanged.
- `racklab_ssh_host_key_resolver` (**Resolver**) — plugins can supply host keys from sources beyond the cloud-init phone-home (e.g., DNSSEC SSHFP records in v1.1). Properties: `string $tenantId`, `string $deploymentId`, `string $hostname`; returns `?string` (host public key fingerprint or null to fall through).

**Scheduler + reconciler:**

- `racklab_reconciler_pre_run` (**Filter**) / `racklab_reconciler_post_run` (**Notification**). Properties: `string $tenantId`, `string $reconcilerKind`, `\DateTimeImmutable $scheduledAt`.
- `racklab_reconciler_drift_handler` (**Contributor**) — plugins contribute drift-fix strategies. Properties: `string $tenantId`, `string $resourceId`, `array $drift`; each listener contributes a `DriftFixStrategy`.
- `racklab_scheduler_placement_resolver` (**Resolver**) — alternative placement strategies (see family above). Properties: `string $tenantId`, `string $deploymentId`, `array $constraints`; returns `?PlacementDecision`.

**Catalog:**

- `racklab_catalog_pre_publish` (**Filter** / validator) / `racklab_catalog_post_publish` (**Notification**). Properties: `string $tenantId`, `string $catalogItemId`, `string $actorId`.
- `racklab_catalog_pre_unpublish` (**Filter**) / `racklab_catalog_post_unpublish` (**Notification**). Properties: `string $tenantId`, `string $catalogItemId`, `string $actorId`.
- `racklab_catalog_clone_pre` (**Filter**) / `racklab_catalog_clone_post` (**Notification**). Properties: `string $sourceTenantId`, `string $targetTenantId`, `string $catalogItemId`.
- `racklab_catalog_approval_required_resolver` (**Resolver**) — plugins decide which catalog changes need approval. Properties: `string $tenantId`, `string $catalogItemId`, `string $changeKind`; returns `?bool`.

**Audit:**

- `racklab_audit_pre_emit` (**enrichment-only**, NOT a Filter/validator) — `app/Events/Hookspecs/Audit/PreEmitEvent.php`. Listeners may mutate enrichment fields (compliance reason codes, correlation tags, redaction markers) but cannot abort emission. Pre-emit raising is logged and counted against the plugin's failure-window threshold; the audit row is written regardless. Properties: `string $tenantId`, `string $eventType`, `string $actorId`, `array &$enrichment`.
- `racklab_audit_post_emit` (**Notification** / sink) — the outbox-relay entry point (per PRD §14 audit reliability). Fires after the row's transaction commits. Properties: `string $tenantId`, `string $eventId`, `string $eventType`, `array $payload`.
- `racklab_audit_redaction_request` (**Notification**) — plugins fire to request fields be redacted in audit exports. Properties: `string $tenantId`, `string $eventId`, `array $fieldPaths`.
- `racklab_audit_event_schema_contributor` (**Contributor**) — plugins register their audit event schemas (validated at emission, see PRD §14). Each listener contributes an `AuditEventSchema`.

**Notification:**

- `racklab_notification_pre_send` (**Filter**) / `racklab_notification_post_send` (**Notification**). Properties: `string $tenantId`, `string $channelSlug`, `string $recipientId`, `string $templateKey`, `array $context`.
- `racklab_notification_template_resolver` (**Resolver**) — plugins can override default email/Slack/etc. templates. Properties: `string $tenantId`, `string $channelSlug`, `string $templateKey`; returns `?string` (template path or null to fall through).
- `racklab_notification_channel_register` (**Contributor**) — channels (email, Slack, Discord, plugin-shipped webhook) register themselves. Each listener contributes a `NotificationChannel`.

**i18n + UI:**

- `racklab_translation_catalog_register` (**Contributor**) — plugins ship their catalogs (PRD §15). Each listener contributes a `TranslationCatalog` (locale → file path).
- `racklab_locale_resolver` (**Resolver**) — alternative locale-resolution chains. Properties: `string $tenantId`, `string $userId`, `string $requestLocale`; returns `?string` (resolved locale or null to fall through).
- `racklab_ui_navigation_item` (**Contributor**) — plugins contribute nav entries. Each listener contributes a `NavItem`.
- `racklab_ui_dashboard_card` (**Contributor**) — plugins contribute dashboard widgets. Each listener contributes a `DashboardCard`.
- `racklab_ui_theme_resolver` (**Resolver**) — theme plugins. Properties: `string $tenantId`, `?string $userId`; returns `?ThemeConfig`.

**Docs:**

- `racklab_docs_ref_resolver` (**Resolver** — already documented in PRD §22). Properties: `string $tenantId`, `string $refToken`; returns `?string` (resolved URL or null to fall through).
- `racklab_docs_pre_render` (**Filter**) / `racklab_docs_post_render` (**Notification**). Properties: `string $tenantId`, `string $docId`, `string $rawMarkdown`.
- `racklab_docs_image_pipeline` (**Filter**) — plugins can intercept image uploads for resize / EXIF stripping / CDN sync. Properties: `string $tenantId`, `string $uploadSessionId`, `string $filename`, `mixed $stream`; listener may return a transformed `stream` or null to pass through.

**Health:**

- `racklab_health_check` (**Contributor**) — every plugin contributes its check. Each listener contributes a `HealthCheckResult`.

**TLS:**

- `racklab_tls_pre_renew` (**Filter**) / `racklab_tls_post_renew` (**Notification**). Properties: `string $domain`, `string $issuerProfile`, `\DateTimeImmutable $expiresAt`.
- `racklab_tls_validation_handler` (**Contributor**) — plugins extend cert-validation rules (e.g., chain pinning, signed-by-RIT-CA enforcement). Each listener contributes a `CertValidationResult`.

**Webhooks (outbound + inbound):**

- `racklab_webhook_outbound_pre_send` (**Filter**) / `racklab_webhook_outbound_post_send` (**Notification**). Properties: `string $tenantId`, `string $endpointUrl`, `string $eventType`, `array $payload`.
- `racklab_webhook_inbound_pre_dispatch` (**Filter**) / `racklab_webhook_inbound_post_dispatch` (**Notification**). Properties: `string $tenantId`, `string $source`, `string $eventType`, `array $payload`.
- `racklab_webhook_signature_resolver` (**Resolver**) — plugins resolve which signing scheme applies to a given outbound webhook. Properties: `string $tenantId`, `string $endpointUrl`, `string $endpointSlug`; returns `?SigningScheme`.

**Plugin lifecycle (recursive — plugins can hook other plugins' lifecycle):**

- `racklab_plugin_pre_install` (**Filter**) / `racklab_plugin_post_install` (**Notification**) / `racklab_plugin_pre_migrate` (**Filter**) / `racklab_plugin_post_migrate` (**Notification**) / `racklab_plugin_pre_enable` (**Filter**) / `racklab_plugin_post_enable` (**Notification**) / `racklab_plugin_pre_disable` (**Filter**) / `racklab_plugin_post_disable` (**Notification**) / `racklab_plugin_pre_uninstall` (**Filter**) / `racklab_plugin_post_uninstall` (**Notification**). Properties: `string $pluginSlug`, `string $fromState`, `string $toState`.

**Conventions enforced:**

- Every hookspec is a **typed PHP 8 readonly class** with explicit typed properties. Use `@var string[]` PHPDoc where PHP's native type system can't express the shape (e.g., `array` of a specific shape).
- Filter hooks have a 100 ms soft budget; Filter hooks exceeding 500 ms are logged and counted against the plugin's failure-window threshold (see "Failure isolation rules" above).
- Notification hooks must not throw to abort — the operation has already committed. Throwing is logged and counted as a plugin error.
- Hooks that produce side effects (write to the DB, dispatch Horizon jobs) must declare it in the event class docblock and use the standard tenant-aware repositories (PRD §19).
- The audit-emission test (PRD §17) refuses to merge a hookspec without a documented audit event for "hook called" if the hook is in a privileged path (deployment lifecycle, RBAC, token issuance, audit emission itself).

## Audit

Plugin installation, enablement, disablement, configuration changes, health changes, and execution failures are audit logged.
