# Plugin System

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

- `racklab-provider-proxmox`
- `racklab-console-proxmox` (supports both Proxmox noVNC for KVM graphical consoles and xterm.js for LXC and serial consoles)
- `racklab-console-ssh` (browser-based SSH terminal for any reachable VM; see [SSH Plugin](23-ssh-plugin.md))
- `racklab-script-cloudinit`
- `racklab-script-console-openqa`
- `racklab-script-ansible`
- `racklab-auth-allauth`
- `racklab-notify-email`
- `racklab-audit-jsonlog`
- `racklab-quota-default`
- `racklab-docs` (see [Docs Plugin](22-docs-plugin.md) — exercises the full plugin contract and defines its own extension point for other plugins to register cross-link resolvers)
- `racklab-storage-proxmox-shared` — artifact backend that tunnels storage onto the Proxmox cluster's shared storage (Ceph-backed PVE pools are the headline case, but any Proxmox-managed storage type is supported via the `pvesm` abstraction: CephFS, NFS, GlusterFS, ZFS-over-iSCSI, even per-node local storage configured as shared). See "Proxmox shared storage backend" below.

## Discovery And Contracts

Requirements:

- Plugins are Python packages installed through `uv` and normal Python packaging.
- Discovery uses Python entry points.
- Hook contracts use `pluggy` or a similarly small explicit hook system.
- Plugin APIs are versioned.
- Plugins declare supported RackLab API versions.
- Plugins declare capabilities.
- Plugins declare required settings and secrets.
- Plugins declare health checks.
- Plugins declare permissions they add.
- Plugins declare migration needs if they contribute Django models, plus their migration dependency on other plugins and on RackLab core models.

## Plugin Lifecycle

Plugins go through an explicit, named lifecycle. Django's stock model (whatever is in `INSTALLED_APPS` runs migrations on `migrate`) is too loose for runtime-enableable plugins that contribute models; RackLab manages a higher-level state machine on top.

States:

1. **installed** — the package is in the Python environment (e.g., `pip install` / `uv add` completed) and the entry point is discoverable, but RackLab has not loaded it.
2. **migrated** — the plugin's migrations have been applied. Models exist in Postgres; the plugin's Django app is not yet in `INSTALLED_APPS` for the running RackLab processes.
3. **enabled** — the plugin's Django app is in `INSTALLED_APPS`, its hooks are registered, its admin pages and routes are mounted, its workers are eligible for scheduling, and its health check is reported.
4. **disabled** — the plugin's hooks are unregistered and its admin/routes are unmounted, but its models remain in Postgres and its migrations are not reversed. A disabled plugin can be re-enabled instantly.
5. **pending_uninstall** — the plugin is marked for migration rollback. Rollback runs in a separate step; until it completes, the plugin remains in this state.

Commands:

- `racklab plugin install <name>` — sanity-checks the entry point + capability declarations + version constraints. State becomes `installed`. No DB changes.
- `racklab plugin migrate <name>` — runs the plugin's forward migrations against Postgres. Verifies declared migration dependencies on RackLab core and on other enabled plugins; refuses if a dependency isn't satisfied. State becomes `migrated`.
- `racklab plugin enable <name>` — writes the plugin into RackLab's persisted plugin-config file (NOT `settings.py`); the next process startup includes it in `INSTALLED_APPS` and registers its hooks. Triggers a controlled restart of `web` and the worker pools the plugin contributes to. State becomes `enabled`.
- `racklab plugin disable <name>` — removes the plugin from the persisted config; next startup excludes it. Triggers the same controlled restart. State returns to `migrated`.
- `racklab plugin rollback <name> --to-version=<ver>` — runs reverse migrations from the current applied version down to the target version. Refuses if other enabled plugins depend on schema introduced after the target. Plugin must be `disabled` first. State becomes `migrated` at the new target version.
- `racklab plugin uninstall <name>` — runs reverse migrations all the way to zero, then removes plugin metadata. Plugin must be `disabled` first. After uninstall, removing the package from the Python environment is the operator's separate step. State becomes `installed`-with-no-migrations (effectively gone).

Discipline:

- **`INSTALLED_APPS` is computed at process startup** from the persisted plugin-config file plus the core apps. Enable/disable both require a controlled restart; this is the right operational tradeoff because hot-loading Django apps with models is a known footgun (signal connections, app-ready hooks, model registry consistency).
- **Controlled restart** for the Baseline profile uses systemd (`systemctl restart racklab-web racklab-worker@*` with graceful drain). For the Scale profile, Nomad's `update` stanza handles the rolling restart of web + worker jobs; the admin GUI surfaces the rollout state.
- **Migration dependency declarations** are checked at `migrate` time. A plugin may declare `requires_core_version >= "1.2"` (RackLab core model version) and `requires_plugin "racklab-foo" >= "0.4"`. Unsatisfied dependencies abort with a clear error before any schema changes.
- **Migration runtime budget**: plugin migrations should complete in under 30 seconds against a typical deployment. Longer migrations declare themselves as such and run in maintenance mode (web tier returns 503 for non-admin endpoints; admin can monitor migration progress).
- **Rollback semantics**: every forward migration must have a working reverse. CI in the plugin's own repo verifies the migration round-trips on a representative database.
- **State machine persistence**: `PluginLifecycleState` and `PluginMigrationRecord` rows in PRD §19 are the source of truth. The admin Plugin Management page (PRD §15) renders the state and exposes the lifecycle commands.

Audit events fire at every state transition: `plugin.install`, `plugin.migrate.start/success/failure`, `plugin.enable.start/success/failure`, `plugin.disable`, `plugin.rollback.start/success/failure`, `plugin.uninstall`.

## Controlled Extension Points

Plugins contribute through controlled extension points — they do not patch the core. The contributable surfaces are:

- **Django app** (models, URL routes mounted under a plugin-specific prefix, admin pages, signals, management commands). Validated at `enable` time against route-shadowing rules (plugins cannot register routes under `/admin/`, `/api/`, `/static/`, `/media/`, `/.well-known/`).
- **Hook implementations** for the families declared in §1. Implementations are registered via the plugin's pyproject entry point at `enable` time.
- **API routes** under the plugin's prefix, contributed via standard DRF routing.
- **Worker pool requirements** declared as `WorkerPoolSpec` objects passed through `PluginWorkerRuntime.declare_pool` per the Podman orchestration spec §4.
- **Permission strings** added at `enable` time. Permission removal on `disable` strips the permission from RoleBindings.
- **Audit event schemas** declared at `enable` time and validated at emission.
- **Translation catalogs** registered at `enable` time and merged with the core catalogs (PRD §15 i18n).
- **Capability flags** that other code (especially the catalog validator) consumes.

### Failure isolation rules

The PRD goal is that one plugin's failure doesn't break the control plane. Failures map to states:

- **Import-time failure** at process startup (`ImportError`, syntax error, missing dependency): the plugin is flagged unhealthy; its hooks are not registered; its admin pages are not mounted. The web tier and worker pools start; an admin notification fires.
- **Migration failure** during `racklab plugin migrate`: the migration command aborts with a non-zero exit. The Django transaction guarantees no partial schema. The plugin stays in `installed`.
- **Hook implementation raising at runtime**: the offending implementation is recorded as unhealthy; subsequent calls to that hook skip the failing implementation. The host code path proceeds with remaining implementations. Repeated failures within a window auto-disable the plugin (configurable threshold) and emit an admin alert.
- **Worker pool registration failure**: the WorkerRuntime declares the pool failed; replicas already running continue, but no new ones start. Admin sees the failure in the Plugin Management page.
- **Admin page exception**: the plugin's admin URL returns the standard RackLab 500 page with a "report this plugin" link. Other admin pages are unaffected.
- **Static asset failure** (missing file referenced by template): logged, page renders without the asset. Doesn't break the page.
- **Settings-schema validation failure** at startup: the plugin is loaded but flagged unhealthy; admin must fix configuration before its hooks are called.

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

Artifact storage is a plugin family per the data model in PRD §19 (`Artifact.storage_backend`). The core ships a filesystem backend; everything else (S3, S3-compatible, GCS, Azure Blob, MinIO, IPFS, etc.) is a plugin implementing the `ArtifactBackend` Protocol.

Storage backends must:

- Implement the typed `ArtifactBackend` Protocol. Every method takes a `tenant_id` parameter as its first argument so the backend can partition storage layout per tenant if it chooses (the upload coordinator always passes the actor's tenant; backends that don't partition just ignore the argument). The methods are: `put(tenant_id, key, stream, content_type, metadata) → URI`, `get(tenant_id, key) → stream`, `stat(tenant_id, key) → ArtifactStat`, `delete(tenant_id, key) → None`, `presigned_url(tenant_id, key, ttl) → URL | None`, `multipart_initiate(tenant_id, key, content_type) → MultipartHandle`, `multipart_upload_part(handle, part_number, stream) → ETag` (the handle already carries the tenant context from `multipart_initiate`), `multipart_complete(handle, parts) → URI`, `multipart_abort(handle) → None`, `health() → BackendHealth`.
- **Storage-key derivation**: the upload coordinator owns key derivation, not the backend. A finalized artifact's storage key is `<tenant_id>/<artifact.kind>/<sha256>` (after the upload completes, sha256 is known). During the in-flight upload, the chunks are addressed by the `UploadSession.id` (a UUID4 transfer ID) — that transfer ID is *not* the final storage key. The upload coordinator atomically renames `transfer_id` → `sha256`-keyed path on session completion (filesystem backend) or completes the S3 multipart with the sha256-based key (S3 backend). PRD §15 + §18's "storage key derived from `transfer_id`" wording refers to the *temporary in-flight key*; the final stored artifact is sha256-keyed.
- Honour the **upload-session invariants** from PRD §15: backends are called by the upload coordinator (Django), not directly by the FilePond client. Backends implementing S3-style multipart expose `multipart_*` methods; backends without that capability fall back to streaming put + post-upload-hash verification (per PRD §18 upload security).
- Emit the **pipeline hooks** below at every state change so other plugins can extend the pipeline.
- Declare a **capability flag set**: `supports_presigned_urls`, `supports_multipart`, `supports_versioning`, `supports_object_lock`, `supports_byte_range`, `supports_tenant_partitioning`. The artifact subsystem checks these before issuing operations the backend doesn't support.
- Report **health** via the standard plugin health check.

The library survey at `docs/architecture/2026-05-25-django-library-survey.md` §6 recommends `django-storages` as the foundation for S3 / GCS / Azure plugins — the plugin presents a thin `ArtifactBackend` → `django-storages.Storage` adapter rather than wiring `django-storages` directly into core.

### Proxmox shared storage backend

`racklab-storage-proxmox-shared` is a first-party storage backend plugin that tunnels artifact bytes onto whatever shared-storage pool the Proxmox cluster is already running. The headline use case is Ceph (CephFS or RBD-via-`pvesm`) but the implementation is path-agnostic — it speaks the Proxmox storage abstraction (`pvesm`), which already unifies CephFS, NFS, GlusterFS, ZFS-over-iSCSI, and any other Proxmox-recognised pool. Operators configure the plugin with a list of Proxmox storage IDs and a content-type mapping from RackLab artifact kinds to Proxmox storage content types (`iso` / `vztmpl` / `backup` / `images` / `snippets`).

Why this matters: Proxmox VM clone operations source their template image from a storage pool reachable by the target node. If RackLab's catalog template image lives in `s3://racklab-artifacts/`, every VM clone has to round-trip the bytes through the management plane first. If it lives on the cluster's shared storage pool, the clone is a local pool-to-pool operation that runs at storage-fabric speed and doesn't load RackLab's network. Same logic for backup snapshots, cloud-init ISO snippets, and any other artifact that a Proxmox node needs to read while spinning up a VM.

The plugin uses the Proxmox API exclusively (via `proxmoxer` per the [Proxmox client discipline spec](../superpowers/specs/2026-05-24-proxmox-client-discipline.md)) — `POST /api2/json/nodes/{node}/storage/{storage}/upload` for writes, `GET /api2/json/nodes/{node}/storage/{storage}/content/{volume}` for reads, `DELETE` for retention. The plugin never bypasses the Proxmox API; that's the design point — Proxmox's authorisation, audit, and quota visibility stay intact, and operators don't need to grant the plugin filesystem-level access to the Proxmox hosts. Content types Proxmox doesn't accept via the upload endpoint (e.g., RackLab-internal artifact kinds like `console_recording` or `script_log`) simply route to a different storage backend — that's what the `Artifact.kind` → backend routing table is for. The Proxmox shared backend is the right home for VM-relevant artifacts (`iso`, `vztmpl`, `backup`, `images`, `snippets`); the filesystem or S3 backend is the right home for everything else.

Capabilities the plugin declares:

- `supports_presigned_urls = False` (Proxmox doesn't issue presigned URLs; the plugin proxies downloads through itself with short-lived signed share tokens per PRD §6).
- `supports_multipart = False` for the upload-endpoint path (Proxmox accepts a single multipart-encoded HTTP POST; large ISOs are chunked by the upload coordinator at the Django layer, then assembled before the Proxmox upload). The plugin exposes the chunked-receive API per PRD §15 §18 so FilePond's `Upload-Offset` flow still works; the final assembled file is then POSTed to Proxmox.
- `supports_versioning = False` (Proxmox storage is name-keyed; the plugin keys artifacts by sha256-prefixed filenames so collisions are content-identity, not name).
- `supports_object_lock = False`.
- `supports_byte_range = True` (the plugin proxies range reads).

Tenant-awareness: the plugin supports two layouts. Either (1) one shared Proxmox storage pool with tenant-prefixed keys (`<storage>/racklab-artifacts/<tenant>/<kind>/<sha256>`), or (2) per-tenant Proxmox storage pools mapped 1:1 (configurable per deployment). Layout (1) is the default for single-institution RackLab installs; layout (2) suits multi-institution deployments where storage isolation is desired (RIT's pool stays distinct from partner-school pools at the Ceph level). The `Artifact.tenant_id` denormalized column drives the layout decision per write.

`Artifact.storage_backend` gains the value `proxmox_shared` (see PRD §19 data model). The retention sweep `ReconcilerTask` calls the plugin's `delete` per usual; the plugin issues `DELETE /api2/json/nodes/{node}/storage/{storage}/content/{volume}` against Proxmox.

Operationally: the plugin requires a service account on the Proxmox cluster with the `Datastore.Audit` + `Datastore.AllocateSpace` + `Datastore.AllocateTemplate` + `Datastore.Allocate` privileges, scoped to the storage IDs configured for the plugin. Credentials live in the Secret Backend (PRD §13 family); rotation runs through the standard provider-credential admin GUI.

## Hookspec Catalog

Plugins extend RackLab through a broad set of `pluggy` hookspecs across the codebase. The catalog below is the v1 hookspec surface; new hookspecs are added by amending this catalog and emitting an `extension.hookspec.added` audit event (so plugin authors can detect new extension points). Every hookspec is versioned with the RackLab API version it was added in; breaking changes increment the major version.

The hookspec surface is filled in incrementally across the roadmap (storage / auth / RBAC / audit / plugin lifecycle hooks land in M0; deployment / job / quota hooks in M2; provider hooks in M3; etc.) — the catalog is the *target* shape, not the M0 shape.

**Conventions:**

- `racklab_<domain>_pre_<verb>` / `racklab_<domain>_post_<verb>` — fire before / after an operation. Pre-hooks can raise to abort; post-hooks are notification-only.
- `racklab_<domain>_<verb>_resolver` — first-non-None-wins resolver hooks (used where one of many plugins might handle a given input).
- `racklab_<domain>_<verb>_validator` — return None to accept, raise to reject.
- `racklab_<domain>_<verb>_contributor` — contribute items to a list (UI nav, dashboard cards, audit-event schemas, etc.).
- `racklab_<domain>_<verb>_sink` — fan-out hooks (audit sinks, notification sinks).

**Storage pipeline:**

- `racklab_storage_backend_register` (contributor) — backends register themselves.
- `racklab_artifact_pre_store` / `racklab_artifact_post_store` — fires around every `Artifact` row creation; pre-hooks can mutate metadata, post-hooks see the final URI.
- `racklab_artifact_pre_retrieve` / `racklab_artifact_post_retrieve` — fires around every read; pre-hooks can rewrite the storage key (e.g., for transparent decryption / CDN routing).
- `racklab_artifact_pre_delete` / `racklab_artifact_post_delete` — fires around retention sweep + manual deletes.
- `racklab_artifact_mime_sniff_resolver` — alternative MIME sniffers beyond `python-magic`.
- `racklab_artifact_scan_handler` (contributor) — scanners (ClamAV, `qemu-img info`, custom validators) contribute themselves; each runs against the quarantined artifact.
- `racklab_artifact_quarantine_clear` (validator) — fires when scanners return OK; plugins can hold the artifact quarantined for additional checks.
- `racklab_artifact_retention_policy_resolver` — plugins resolve per-artifact-kind retention windows (PRD §14).
- `racklab_upload_session_pre_create` (validator) — additional gates (tenant policy, file-type allowlist, peer-quota check) before an `UploadSession` row is written.
- `racklab_upload_chunk_received` — every PATCH chunk fires this; plugins can run progressive validation (incremental checksums, streaming AV scan).
- `racklab_upload_session_complete` / `racklab_upload_session_abort` — fan-out for plugins that mirror to a secondary store, kick off post-processing pipelines, etc.

**Auth + tokens:**

- `racklab_auth_pre_login` / `racklab_auth_post_login` / `racklab_auth_pre_logout` / `racklab_auth_post_logout`.
- `racklab_token_pre_issue` / `racklab_token_post_issue` (both JWT and PAT tracks; carries token type, scope, tenant, requested permissions).
- `racklab_token_pre_revoke` / `racklab_token_post_revoke`.
- `racklab_token_claims_contributor` — JWT plugins contribute extra claims (e.g., LMS launch context, partner-school identifier).

**RBAC:**

- `racklab_rbac_permission_check` (resolver) — first-non-None-wins; plugins can short-circuit allow/deny for specific permissions (e.g., a `compliance` plugin enforcing additional rules).
- `racklab_rbac_binding_pre_issue` / `racklab_rbac_binding_post_issue` — auditing + custom escalation gates.
- `racklab_rbac_role_resolved` — plugins observe role resolutions for their own audit/analytics.

**Tenant:**

- `racklab_tenant_pre_create` / `racklab_tenant_post_create`.
- `racklab_tenant_membership_changed` — fan-out when a user joins/leaves a tenant.
- `racklab_tenant_cross_access` — audited cross-tenant access events; plugins can attach extra context (e.g., compliance reason codes).

**Deployment + Job:**

- `racklab_deployment_pre_create` (validator) / `racklab_deployment_post_create`.
- `racklab_deployment_state_transition_pre` / `racklab_deployment_state_transition_post`.
- `racklab_deployment_pre_delete` / `racklab_deployment_post_delete`.
- `racklab_job_pre_dispatch` / `racklab_job_post_dispatch`.
- `racklab_job_state_transition_pre` / `racklab_job_state_transition_post`.
- `racklab_job_complete` / `racklab_job_fail` / `racklab_job_retry`.
- `racklab_job_progress` — workers fire as they advance through `JobStep`s.

**Quota:**

- `racklab_quota_pre_check` / `racklab_quota_post_check`.
- `racklab_quota_pre_reserve` / `racklab_quota_post_reserve`.
- `racklab_quota_pre_release` / `racklab_quota_post_release`.
- `racklab_quota_policy_resolver` — alternative quota policies (e.g., a `lab-fair-share` plugin overriding the default).

**Provider:**

- `racklab_provider_pre_call` / `racklab_provider_post_call` — fires around every Proxmox API call (or equivalent in other providers); useful for telemetry, retry policies, circuit breakers.
- `racklab_provider_drift_detected` (sink) — reconciler fires when unmanaged state is found.
- `racklab_provider_capacity_snapshot` (contributor) — additional capacity sources beyond the built-in provider polling.

**Networking:**

- `racklab_network_pre_attach` / `racklab_network_post_attach`.
- `racklab_network_offering_resolve_reachability` (resolver) — given a `NetworkOffering`, return its reachability capability (drives SSH plugin behaviour per PRD §23).
- `racklab_network_pre_realize` / `racklab_network_post_realize` — fires when self-service networks are pushed to the provider.

**Console + SSH:**

- `racklab_console_pre_grant` (validator) / `racklab_console_post_grant`.
- `racklab_console_pre_disconnect` / `racklab_console_post_disconnect`.
- `racklab_ssh_session_recording_chunk` — redaction-pipeline plugins fire on every byte chunk before it lands in the recording artifact.
- `racklab_ssh_host_key_resolver` — plugins can supply host keys from sources beyond the cloud-init phone-home (e.g., DNSSEC SSHFP records in v1.1).

**Scheduler + reconciler:**

- `racklab_reconciler_pre_run` / `racklab_reconciler_post_run`.
- `racklab_reconciler_drift_handler` (contributor) — plugins contribute drift-fix strategies.
- `racklab_scheduler_placement_resolver` (resolver) — alternative placement strategies (see family above).

**Catalog:**

- `racklab_catalog_pre_publish` (validator) / `racklab_catalog_post_publish`.
- `racklab_catalog_pre_unpublish` / `racklab_catalog_post_unpublish`.
- `racklab_catalog_clone_pre` / `racklab_catalog_clone_post`.
- `racklab_catalog_approval_required_resolver` — plugins decide which catalog changes need approval.

**Audit:**

- `racklab_audit_pre_emit` (**enrichment-only**, NOT a validator) / `racklab_audit_post_emit` (sink). Pre-emit plugins may mutate the payload's enrichment fields (adding compliance reason codes, correlation tags, redaction markers) but cannot abort emission — PRD §17 makes "missing audit emission is a P0 bug" load-bearing, so a plugin must never block a documented audit event from being written. Pre-emit raising is logged and counted against the plugin's failure-window threshold; the audit row is written regardless. `post_emit` is the outbox-relay entry point (per PRD §14 audit reliability).
- `racklab_audit_redaction_request` — plugins fire to request fields be redacted in audit exports.
- `racklab_audit_event_schema_contributor` — plugins register their audit event schemas (validated at emission, see PRD §14).

**Notification:**

- `racklab_notification_pre_send` / `racklab_notification_post_send`.
- `racklab_notification_template_resolver` — plugins can override default email/Slack/etc. templates.
- `racklab_notification_channel_register` (contributor) — channels (email, Slack, Discord, plugin-shipped webhook) register themselves.

**i18n + UI:**

- `racklab_translation_catalog_register` (contributor) — plugins ship their catalogs (PRD §15).
- `racklab_locale_resolver` — alternative locale-resolution chains.
- `racklab_ui_navigation_item` (contributor) — plugins contribute nav entries.
- `racklab_ui_dashboard_card` (contributor) — plugins contribute dashboard widgets.
- `racklab_ui_theme_resolver` — theme plugins.

**Docs:**

- `racklab_docs_ref_resolver` (resolver — already documented in PRD §22).
- `racklab_docs_pre_render` / `racklab_docs_post_render`.
- `racklab_docs_image_pipeline` — plugins can intercept image uploads for resize / EXIF stripping / CDN sync.

**Health:**

- `racklab_health_check` (contributor) — every plugin contributes its check.

**TLS:**

- `racklab_tls_pre_renew` / `racklab_tls_post_renew`.
- `racklab_tls_validation_handler` — plugins extend cert-validation rules (e.g., chain pinning, signed-by-RIT-CA enforcement).

**Webhooks (outbound + inbound):**

- `racklab_webhook_outbound_pre_send` / `racklab_webhook_outbound_post_send`.
- `racklab_webhook_inbound_pre_dispatch` / `racklab_webhook_inbound_post_dispatch`.
- `racklab_webhook_signature_resolver` — plugins resolve which signing scheme applies to a given outbound webhook.

**Plugin lifecycle (recursive — plugins can hook other plugins' lifecycle):**

- `racklab_plugin_pre_install` / `racklab_plugin_post_install` / `racklab_plugin_pre_migrate` / `racklab_plugin_post_migrate` / `racklab_plugin_pre_enable` / `racklab_plugin_post_enable` / `racklab_plugin_pre_disable` / `racklab_plugin_post_disable` / `racklab_plugin_pre_uninstall` / `racklab_plugin_post_uninstall`.

**Conventions enforced:**

- Every hookspec is fully typed (pydantic models for payloads where the shape is non-trivial; primitive types otherwise).
- Pre-hooks have a 100 ms soft budget; pre-hooks exceeding 1 s are logged and counted against the plugin's failure-window threshold (see "Failure isolation rules" above).
- Post-hooks must not raise to abort — the operation has already committed. Raising is logged and counted as a plugin error.
- Hooks that produce side effects (write to the DB, publish to NATS) must declare it in the hookspec docstring and use the standard tenant-aware managers (PRD §19).
- The audit-emission test (PRD §17) refuses to merge a hookspec without a documented audit event for "hook called" if the hook is in a privileged path (deployment lifecycle, RBAC, token issuance, audit emission itself).

## Audit

Plugin installation, enablement, disablement, configuration changes, health changes, and execution failures are audit logged.
