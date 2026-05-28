# RackLab Progress

Tracks what has shipped vs what is next.

## Stack direction

RackLab is being built on **PHP 8.3+ / Laravel 13 + FrankenPHP + Octane + Livewire 4 + Filament 5 + Tailwind v4 + daisyUI 5 + Reverb + Horizon + Podman job containers**. The architectural source of truth is `docs/superpowers/specs/2026-05-26-laravel-redesign.md`. The PRD (`docs/prd/`) remains the source of truth for *what* RackLab does.

## Shipped

### prd-rewrite sub-plan (2026-05-26 → 2026-05-27)

The first of seven sub-plans from the redesign-spec §10 portfolio is complete. Every PRD section, every roadmap milestone, the architecture diagrams, `CLAUDE.md`/`AGENTS.md`, and the still-applies Podman + Proxmox-client-discipline specs have been rewritten or updated to reflect the Laravel stack.

Highlights:

- **`docs/superpowers/specs/2026-05-26-laravel-redesign.md`** — architectural spec authored after two rounds of codex review (research review for ecosystem-state, spec review for internal consistency). Captures stack table, process topology, repo layout, multi-tenancy + RBAC composition, plugin model, script execution + real-time, quality + CI.
- **All 8 heavy PRD-section rewrites** committed (§05 architecture, §06 auth/RBAC/tokens, §07 API/OpenAPI/real-time push, §13 plugin system, §15 UI/UX, §17 engineering/quality/CI, §22 docs plugin, §23 SSH plugin).
- **5 light PRD-section sweeps** (§10 scripting/sandboxing, §14 audit/observability, §18 security, §19 data model, plus a catch-all sweep across the remaining 12 less-affected sections).
- **All roadmap milestones rewritten** (M00 → M13d + README; now 23 slices after adding M5c VPNaaS), preserving each milestone's functional Goal / Acceptance criteria and rewriting Deliverables / Test layers / Risks for the Laravel stack.
- **Architecture Mermaid diagrams** updated across 8 diagram blocks.
- **Two systemic remediation sweeps** caught problems earlier per-task implementers missed:
  - NATS-removal sweep (commit `c074571`) — replaced NATS / NATS JetStream references across ~25 files with Redis + Horizon + Reverb + Postgres `broadcast_event_log` + outbox-table equivalents.
  - Codex full-review remediation (commit `b2f59db`) — applied 50+ P0 + 30+ P1 findings across the entire docs tree, including a full PHP-stack rewrite of the body of `docs/superpowers/specs/2026-05-24-proxmox-client-discipline.md` (the header carry-forward note alone was not sufficient).

### laravel-scaffold foundation slice (2026-05-27)

The Laravel scaffold is now through the initial quality-gate slice:

- Pest 4 is installed and `composer test` runs the PRD layer split (`Tiny`, `Contract`, `Integration`, `Browser`) instead of Laravel's default `Unit` / `Feature` split.
- Larastan is installed at PHPStan max level with the six custom RackLab rule stubs registered and passing under `composer larastan`.
- Rector is installed with a Laravel-oriented baseline, and `composer rector:dry` passes.
- `racklab/plugin-hello` exists as an in-monorepo Composer path package with RackLab plugin metadata and no Laravel package auto-discovery provider/alias entries; it is required by the root project but explicitly excluded from Laravel discovery.
- The stock Laravel welcome screen was replaced by a small RackLab scaffold page using the configured Vite entries, and the contract smoke test covers it without requiring built assets.
- A Livewire 4 `/hello` smoke component exists and is covered by a Tiny test. Dusk 8 + axe-core browser tests cover the same page and are wired for CI; local Chromium is installed and the browser suite runs locally.
- Lefthook is installed through npm and configured for pre-commit (`pint`, `larastan`, `rector`, Tiny Pest) and pre-push (`composer test`, `npm run build`, dependency audits) gates.
- Pa11y CI is installed as a second accessibility sweep over `/` and `/hello` (`npm run a11y`) using the system Chrome/Chromium binary when present.
- The scaffold UI text now uses Laravel language files under `resources/lang/en` and `resources/lang/es`. `diglabby/laravel-find-missing-translations` is installed and `composer i18n:missing` checks that Spanish carries every English base key.
- `.github/workflows/code-ci.yml` runs PHP 8.3/8.4 quality gates, translation coverage, asset build/audit, Dusk browser tests, and Pa11y on GitHub-hosted Ubuntu runners.
- Generated Laravel / Filament / Vite artifacts are ignored so normal Composer and frontend commands do not leave runtime outputs in git status.

### tenancy-auth foundation slice (2026-05-27)

The first tenancy-auth domain contracts are in place:

- `App\Domain\Tenancy\AccessResolver` implements the PRD §06 / spec §5 three-predicate access composition: binding scope covers resource tenant, resource visibility includes the actor's active tenant, and role grants the requested permission.
- Pure `app/Domain` DTOs/interfaces now define `TenantContext`, `ActorIdentity`, `TenantScopedResource`, `RoleBindingRecord`, `RoleBindingRepository`, `SharingScope`, `RoleBindingScopeType`, `AccessDecision`, and deny reasons. This keeps the security policy independent of Eloquent, HTTP, or Spatie internals.
- `App\Domain\Rbac\Permission` and `RolePermissionLookup` define the role-to-permission boundary that the later Spatie adapter will implement.
- Tiny tests cover tenant-local allow, cross-tenant deny when resource visibility is missing, cross-tenant deny when binding scope is missing, and cross-tenant allow only when all three predicates match.
- `DefaultRoleCatalog` provides the first built-in Student / Instructor / Admin / Guest permission map. `tests/Snapshots/RolePermissionsTest.php` and `tests/Snapshots/roles.json` now gate default-role permission changes, and the snapshot suite is included in `composer test` plus GitHub Actions.
- `TenantContextStore`, `IdentifyTenant`, and `SetTenantContextForOctane` are registered in the HTTP kernel. Contract tests assert `X-RackLab-Tenant` binding and mandatory stale-context reset before and after a request lifecycle.

The first tenancy-auth persistence and context propagation increment is also in place:

- `Tenant`, `TenantMembership`, `RoleBinding`, and `AuditEvent` Eloquent models now have core migrations. Tenants and role bindings use ULID identifiers; `AuditEvent` uses the PRD §14/§19 three-tenant schema (`actor_tenant`, `resource_tenant`, `target_tenant_set`) instead of a legacy single `tenant_id`.
- `IdentifyTenant` now resolves the `X-RackLab-Tenant` header or `{tenant}` route value through persisted tenant id/slug records and rejects unknown or inactive tenants with a 404 before binding request context.
- `BindTenantContext` job middleware plus the `TenantAwareJob` contract establish the Horizon payload pattern for restoring explicit tenant context during job handling and clearing it afterward.
- `EloquentRoleBindingRepository` adapts persisted `role_bindings` rows into the pure `RoleBindingRecord` shape consumed by `AccessResolver`.
- `AuditEventWriter`, `AuditChainVerifier`, and `php artisan racklab:verify-audit-chain` implement the first append-only audit hash-chain path, with tests covering bidirectional surfacing and tamper detection.

The audited cross-tenant fetch and tenant-scope enforcement increment is in place:

- `BelongsToTenant` and `TenantScope` provide the reusable Eloquent tenant global-scope path. Tenant-scoped models can auto-fill `tenant_id` from `TenantContextStore` on create, expose the `TenantScopedResource` methods required by `AccessResolver`, and default to tenant-local sharing when no sharing columns exist.
- `TenantMembership` now uses that tenant-scoped model path, and a contract fixture verifies active-tenant filtering and tenant-id fill behavior without waiting for Project/Deployment tables.
- `CrossTenantFetch` is now the explicit, audited cross-tenant read entry point. It bypasses `TenantScope` only inside `app/Domain/Tenancy/CrossTenantFetch.php`, applies `AccessResolver` per row, returns only allowed resources with provenance, and emits `tenant.cross_access` audit rows for allowed and denied cross-tenant outcomes.
- `NoBareScopeBypassRule`, `NoSpatieBypassRule`, and `UntenantedRule` are no longer no-op stubs for their covered surfaces. Tiny tests assert they catch bare global-scope bypasses, direct `$user->hasRole()` / `$user->can()` checks outside `AccessResolver`, and models that neither declare `tenant_id` nor opt out with `#[Untenanted]`.

The package-backed RBAC adapter increment is in place:

- `spatie/laravel-permission` v7.4.1 and `spatie/laravel-multitenancy` v4.1.3 are installed. RackLab disables Spatie's global Gate permission hook (`permission.register_permission_check_method=false`) so `AccessResolver` remains the policy gate; the package is used for persisted role-to-permission data.
- The default groups are now `admin`, `support`, `instructor`, `ta`, and `student`. `support` is the student-worker support role requested during implementation; guest access remains a separate guest-link/token grant path, not a default group.
- `racklab:sync-rbac-defaults` syncs `DefaultRoleCatalog` into Spatie `roles`, `permissions`, and `role_has_permissions` rows idempotently. Snapshot tests gate role changes, and contract tests cover persisted sync plus lookup.
- `EloquentRolePermissionLookup` now backs the `RolePermissionLookup` interface in the Laravel container, so `AccessResolver` can evaluate persisted role permission grants while retaining RackLab's custom tenant-policy predicates.
- `App\Models\Tenant` implements Spatie's `IsTenant`; `IdentifyTenant` makes the persisted tenant current in Spatie multitenancy, and `SetTenantContextForOctane` clears both RackLab and Spatie tenant context before/after request handling.

The core identity/personal-project increment is in place:

- `UserProfile`, `Project`, and `ProjectMembership` models plus migrations establish the first M1 identity/project tables. `Project` and `ProjectMembership` use the tenant-scoped model path and expose `TenantScopedResource` for later `AccessResolver` checks.
- `PersonalProjectProvisioner` creates or resolves a user's profile, tenant membership, personal default Project, Project owner membership, and a tenant-local project-scoped `RoleBinding`. It is idempotent per `(tenant_id, user_id)` and creates separate personal Projects when the same user enters a second tenant.
- Contract tests cover the first-login provisioning invariant and the separate-projects-per-tenant rule.

The local auth increment is in place:

- Laravel Fortify v1.37.2 and Sanctum v4.3.2 are installed. Fortify local registration/login routes are wired with minimal RackLab Blade views; disabled package-wide permission Gate checks preserve `AccessResolver` as the authorization source of truth.
- Local registration creates a `User`, resolves the configured default tenant (`RACKLAB_DEFAULT_TENANT_SLUG`, default `default`), provisions the personal Project path, authenticates the user, and redirects to `/dashboard`.
- Login for pre-existing local users also runs the personal Project provisioner, so older users are repaired on first login.
- Sanctum's `personal_access_tokens` table and `HasApiTokens` user trait are present for the upcoming Track-B PAT surface.

The first authenticated dashboard/API surface is in place:

- `BindAuthenticatedTenant` resolves a signed-in user's primary tenant, or the configured default tenant, when no explicit tenant header/route binding was supplied.
- `/dashboard` now renders the active tenant and the user's readable Projects instead of a static placeholder.
- `/api/v1/me` and `/api/v1/projects` are registered under the Laravel API route stack and protected with `auth:sanctum`.
- `VisibleProjectList` filters Projects through `AccessResolver` with `project.read`, preserving RackLab's tenant-policy gate for both dashboard-backed and API-backed project listing.
- Contract tests cover dashboard rendering, authenticated user/tenant API shape, and denial of unreadable Projects.

The Track-B opaque PAT increment is in place:

- `TokenGrant` and `TokenRevocation` persist RackLab token metadata alongside Sanctum's hashed `personal_access_tokens` row. Revocation retains the grant/audit history while deleting the Sanctum hash row so the raw bearer stops working immediately.
- `/api/v1/tokens` supports list and create; `DELETE /api/v1/tokens/{tokenGrant}` revokes an owned grant.
- Track-B requests use `Authorization: Token <opaque>`. Middleware normalizes that prefix for Sanctum, gives explicit authorization headers precedence over an existing web session, and rejects Sanctum PATs sent with the wrong `Bearer` prefix.
- Token creation enforces `token.create` plus every requested ability through `AccessResolver` against the scoped Project, so PAT abilities cannot exceed the issuer's Project-local permissions.
- Protected project listing checks the active PAT abilities before returning data, and token create/use/revoke paths emit hash-chained audit rows.

The Track-A JWT/JWKS increment is in place:

- `firebase/php-jwt` v7.0.5 is installed for RS256 signing and verification.
- `SigningKey` stores the current platform signing key, and `/.well-known/jwks.json` publishes the public key set for sidecars such as console proxies.
- `TrackAIssuer` creates short-lived JWT grants with standard claims (`iss`, `aud`, `sub`, `exp`, `iat`, `nbf`, `jti`) plus RackLab grant, tenant, resource, permission, and token-type claims. Track-A grants reuse `TokenGrant` metadata rows with `track=jwt`.
- `TrackAJwtVerifier` validates JWKS-backed signatures, issuer/audience, expiration, and `jti` revocation. `TrackAJwtRevoker` blacklists a `jti` and soft-revokes the retained grant row.
- Bearer requests now dispatch through Track-A verification before Sanctum auth; `Token` still dispatches to Track-B PATs. The API enforces delegated Track-A permissions through the same current-token ability guard used by PATs.
- Contract tests cover claim construction, JWKS verification via `firebase/php-jwt`, Bearer API authentication, delegated-permission denial, and immediate `jti` revocation.

The auth-event audit increment is in place:

- `AuditAuthEvent` listens to Fortify/Laravel signup, login, failed-login, logout, and password-update events and writes hash-chained `audit_events` rows with actor, tenant, IP, user agent, outcome, and redacted metadata.
- Failed-login audit stores a deterministic email hash and never persists submitted passwords.
- `UpdateUserPassword` is registered with Fortify, so `/user/password` updates local passwords and emits the `auth.password_change` audit event.
- Contract tests cover signup/login, failed login redaction, logout, and password-change audit paths.

The minimal account/session UI increment is in place:

- The dashboard now exposes a POST-backed logout control and a locale preference form.
- `SetUserLocale` applies the authenticated user's `UserProfile.locale` before rendering the dashboard, and `/account/locale` persists supported locales (`en`, `es`) back to the profile.
- Contract tests cover the dashboard controls plus an English → Spanish locale switch rendering the dashboard as `Panel`.

The Course identity-scope increment is in place:

- `Course` and `CourseMembership` models plus migrations establish the M1 course container and membership table.
- `Course` is tenant-scoped and exposes `TenantScopedResource`, so course visibility goes through the same `AccessResolver` three-predicate path as Projects.
- `/api/v1/courses` lists only courses readable through `course.read`; dashboard rendering now has separate Courses and Projects sections.
- Contract tests cover empty course lists for unbound students, readable instructor course bindings, and denial for another unbound user in the same tenant.

The minimal Filament admin increment is in place:

- `User` implements Filament's tenant contracts (`FilamentUser`, `HasTenants`, `HasDefaultTenant`) from RackLab `TenantMembership` rows.
- The admin panel is configured with `Tenant::class`, slug tenancy, and persistent `BindFilamentTenantContext` middleware so Livewire requests restore RackLab/Spatie tenant context.
- Minimal Filament resources now exist for Users, Courses, and Projects. Course/Project resources use the tenant ownership relationship, and UserResource is explicitly unscoped because identity spans tenants.
- Contract tests cover tenant exposure/default tenant behavior, panel tenancy config, and resource model/page registration.

The M2 deployment-lifecycle MVP increment is in place:

- Personal Projects now get the reserved project-local Default `StackDefinition` plus `ProjectDefaultStack` pointer promised by M1/M2. The pointer tracks the active Default Stack deployment for the browser "New VM" path.
- `Deployment`, `DeploymentResource`, `DeploymentOperation`, and `DeploymentStateTransition` models/migrations persist the fake-provider deployment loop, including idempotency keys, per-resource state, lifecycle transitions, and tenant-local requester bindings for `deployment.read` / `deployment.update` checks.
- `/api/v1/deployments` creates, lists, and retrieves deployments. Duplicate `idempotency_key` submissions return the original deployment and operation. `/api/v1/deployments/{deployment}/operations` runs `add_vm`, `remove_vm`, `rebuild_vm`, `rebuild_stack`, and `release` against the fake provider.
- Fake-provider failure injection (`simulate_failure=true`) records failed resources, failed operations, failed deployment state, lifecycle audit context, and replay events.
- Project-local Stack authoring is available through `/api/v1/projects/{project}/stacks` (GET/POST), and created Stacks can be deployed through the same lifecycle API.
- Tenant-local catalog read APIs are available through `/api/v1/catalog/items` and `/api/v1/catalog/items/{item}/versions/{version}`. Published catalog versions wrap catalog-scoped StackDefinitions and can be deployed only when the actor can read the catalog item through `AccessResolver`.
- `ProviderTask` rows now ledger every fake-provider operation with provider task id, action, state, attempts, error message, and operation/deployment links. Request handling creates pending provider tasks and dispatches `RunFakeProviderTask` on the `provider-worker` queue; the sync queue keeps local tests immediate while async queues return pending state until the worker runs.
- `FakeProviderTaskRunner` owns provider completion, resource mutation, deployment state transitions, lifecycle audit, and replay-log emission. `racklab:reconcile-provider-tasks` resumes stale pending/running provider tasks without creating duplicate operations.
- The dashboard now shows Projects, Deployments, a New VM action, and a Release action so the fake-provider MVP is usable from the browser as well as the API.
- `broadcast_event_log` plus `GET /api/v1/replay?channel=...&since=...` implements the durable replay contract for deployment channels, including AccessResolver-backed scope checks and the 24-hour replay-gap sentinel.
- `racklab:expire-deployments` expires leased deployments, releases fake resources, records state transitions, emits audit events, and appends replay-log events.
- Contract tests cover default stack provisioning, API create/list/show, idempotency, queued provider-task dispatch, stale-task reconciliation, fake failures, multi-VM add, rebuild/remove/release operations, dashboard New VM/release, project-local Stacks, catalog read/deploy denial, provider-task ledger rows, replay success/denial/gap, and lease expiration.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, and `composer test` pass with 81 tests / 402 assertions.

The plugin lifecycle gate increment is in place:

- `PluginInstallation` and `PluginMigrationRecord` persist global plugin state and migration ledger rows.
- `PluginRegistry` discovers Composer-installed RackLab plugins through `vendor/composer/installed.json` entries declaring `extra.racklab.plugin=true`, derives the manifest/service-provider classes, and boots only plugins with persisted `enabled` state.
- `PluginServiceProvider` registers the registry at app boot while safely skipping boot when plugin tables are not migrated yet.
- `racklab plugin install|migrate|enable|disable|rollback|uninstall <slug>` is available through a PRD-style grouped Artisan entrypoint, with colon aliases retained for direct command lookup.
- Lifecycle transitions enforce installed -> migrated -> enabled -> disabled, require disabled state for rollback/uninstall, and keep the `racklab/plugin-hello` reference plugin disabled until explicitly enabled.
- Contract tests cover the full reference-plugin lifecycle, invalid transition refusal, rollback behavior, and enabled-plugin registry visibility.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, and `composer test` pass with 84 tests / 433 assertions.

The hookspec dispatcher increment is in place:

- `HookDispatcher` supports the four PRD listener styles: Notification, Filter, Contributor, and Resolver. Listener execution is deterministic by priority then plugin slug.
- `HookListenerStyle` and `HookListenerRegistration` define the typed registration surface plugins use instead of raw Laravel Events.
- The first typed hookspec event scaffold exists at `App\Events\Hookspecs\Deployment\CreatingEvent`, with immutable typed properties and a safe `withMetadata()` helper for filter-style mutation.
- `HookspecEventTypedRule` now fails hookspec event classes that are not readonly or that expose untyped properties/promoted properties.
- `NoBareEventDispatchOnHookspecsRule` now fails raw `Event::dispatch()` / `Event::until()` calls against hookspec event classes outside `app/Plugins/HookDispatcher.php`.
- Contract/Tiny tests cover all four listener styles plus both static-analysis rules.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, and `composer test` pass with 89 tests / 442 assertions.

The script-container job scaffold increment is in place:

- `ScriptRun` persists tenant-scoped script execution records with runner kind, command/source, stdout/stderr, exit code, lifecycle timestamps, and runtime metadata.
- `ContainerRuntime`, `ContainerManifest`, `ContainerRunRequest`, and `ContainerRunResult` define the runtime seam that real Podman/Nomad execution will implement. The default binding is `UnavailableContainerRuntime` so tests and future providers must opt into an execution backend explicitly.
- `RunUserScript`, `RunAnsiblePlaybook`, and `RunConsoleScript` are tenant-aware queued jobs on the `script-worker` queue. They declare hardened manifests matching the PRD defaults: read-only root, `/tmp` tmpfs, uid/gid `10001:10001`, CPU/memory/pids caps, and explicit network modes.
- The user-script runner defaults to `networkMode=none`, Ansible to `egress-via-proxy`, and console automation to `via-console-proxy` with the console-proxy unix socket mounted read-only.
- `ScriptContainerRunner` updates the script run ledger around the runtime call and marks runs `succeeded` or `failed` based on container exit code, preserving stdout/stderr and metadata.
- `PodmanCommandBuilder` and `PodmanContainerRuntime` translate manifests into hardened `podman run` commands and execute them through an injectable `ContainerProcessRunner`; `RACKLAB_CONTAINER_RUNTIME=podman` enables that binding.
- Real Podman execution is not verified in this environment because `podman` is not installed. The current evidence covers command construction and process-runner integration; sandbox escape tests remain an integration requirement on a Podman-capable host.
- Contract tests cover manifest hardening, fake-runtime success, non-zero exit failure persistence, Podman command construction, and process-runner integration.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, and `composer test` pass with 94 tests / 476 assertions.

The script approval/API increment is in place:

- `Script`, `ScriptVersion`, and `ScriptApproval` persist project-scoped scripts, immutable executable versions, and scoped approvals. `script_runs` now links back to project/script/version so each execution record is traceable to the exact approved payload.
- `/api/v1/scripts`, `/api/v1/scripts/{script}`, `/api/v1/scripts/{script}/approvals`, and `/api/v1/scripts/{script}/runs` expose the first script definition, edit, approval, and run API surface.
- Runner-kind permissions now map through the PRD §10 permission strings: `script.cloudinit.create`, `script.openqa.create`, `script.network.create`, `script.advanced_code.create`, `script.approve`, `script.publish`, and `script.run_unapproved`. The default-role snapshot was updated so admin/support/instructor/TA can create network scripts and admin/support/instructor can publish scripts.
- Script creation and mutation authorize through `AccessResolver` against the containing Project; raw Spatie role checks are not used. Advanced-code create denial is audit-logged.
- Non-executable metadata edits preserve active approvals. Command/source edits create a new immutable `ScriptVersion`, move the script pointer, invalidate active approvals, and audit the invalidation with old/new version ids.
- Approved script runs enqueue the existing container jobs by runner kind (`RunUserScript`, `RunAnsiblePlaybook`, `RunConsoleScript`). Running without a matching active approval requires `script.run_unapproved`; denied attempts are audit-logged.
- Contract tests cover advanced-code create denial, approval preservation/invalidation, approved run queuing, and denial after executable edits invalidate approval.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, and `composer test` pass with 97 tests / 501 assertions.

The minimal cloud-init provisioning increment is in place:

- `ProjectSshKey`, `HostKeyPhoneHomeToken`, and `DeploymentHostKey` persist Project SSH keys, deployment-scoped phone-home tokens, and captured guest host public keys.
- `/api/v1/projects/{project}/ssh-keys` supports create/list with server-computed OpenSSH-style `SHA256:` fingerprints. Access goes through `AccessResolver` with `project.ssh_key.create` / `project.ssh_key.read`.
- `/api/v1/deployments/{deployment}/cloud-init` attaches a cloud-init `ScriptVersion` to an existing deployment, selects Project SSH keys, creates a short-lived deployment-scoped host-key phone-home token, and stores only redacted rendered cloud-init in deployment metadata.
- `/api/v1/provisioning/host-keys/{token}` accepts unauthenticated guest phone-home once, records deployment host keys, marks the token used, rejects reuse, and audit-logs both success and reuse denial.
- `CloudInitRenderer` preserves user-authored `#cloud-config`, injects selected Project SSH public keys, and adds RackLab phone-home metadata for the guest bootstrap path. The raw phone-home token is returned for immediate provider use but is not persisted in deployment metadata.
- Audit coverage now includes `project.ssh_key`, `cloud_init.render`, and `host_key.phone_home` events.
- Contract tests cover Project SSH key create/list, cloud-init attachment with redacted metadata, single-use host-key phone-home, and reuse denial.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, and `composer test` pass with 100 tests / 530 assertions.

The Proxmox task-discipline seed increment is in place:

- `ProviderTask` now carries the Proxmox-specific ledger fields needed by M3: UPID, decoded node/pid/pstart/starttime/type/vmid/user, idempotency key, operation class, lease/deadline timestamps, attempt count, and last-poll timestamp.
- `App\Providers\Proxmox\Models\ProxmoxUpid` decodes Proxmox UPIDs into typed parts, and `ProxmoxTaskStatus` represents the typed task-status response the discipline layer consumes.
- `ProxmoxClientContract` defines the first strict seam for `taskStatus()` and `taskLog()`; the app binds it to `UnavailableProxmoxClient` by default so real Proxmox access must be configured explicitly.
- `TaskPoller` implements the first owned polling loop over an existing UPID: it polls status, retrieves logs only on terminal state, updates the durable ProviderTask row, updates the linked DeploymentOperation, maps non-`OK` terminal exits to `ProviderTaskFailed`, and maps still-running tasks after the poll budget to `ProviderTaskWaitTimeout`.
- `PollProxmoxTask` runs on the provider-worker queue and catches `ProviderTaskWaitTimeout` so the reconciler can resume by UPID without Horizon re-submitting the original Proxmox operation.
- Tiny/Contract tests cover UPID decoding, successful terminal polling without any operation re-submit path, and the “stop waiting” path that leaves the original operation pending.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, and `composer test` pass with 103 tests / 552 assertions.

The Proxmox Guzzle client seam increment is in place:

- `ProxmoxEndpointConfig` validates endpoint URL/token settings, composes the API-token authorization header, carries timeout/TLS options, and rejects `verify_ssl=false` outside local development.
- `GuzzleProxmoxClient` implements `ProxmoxClientContract` for task-status and task-log reads against `/api2/json/nodes/{node}/tasks/{upid}/...`, using Guzzle 7.10 with explicit API-token auth, safe timeout/TLS options, and no raw HTTP surface above the client seam.
- Provider exception mapping now includes `ProviderAuthError`, `ProviderNotFound`, `ProviderTransient`, and `ProviderBug`; task polling keeps using `ProviderTaskWaitTimeout` / `ProviderTaskFailed`.
- `AppServiceProvider` binds `ProxmoxClientContract` to `UnavailableProxmoxClient` by default and switches to `GuzzleProxmoxClient` only when `RACKLAB_PROXMOX_ENABLED=true` plus endpoint/token config is present.
- Contract tests cover production TLS rejection, API-token header generation, Proxmox task-status/log JSON mapping, and HTTP 401 → `ProviderAuthError` mapping through Guzzle fixtures.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, and `composer test` pass with 106 tests / 560 assertions.

The Proxmox clone operation adapter increment is in place:

- `ProxmoxClientContract` now includes `cloneVm(ProxmoxVmCloneRequest)` in addition to task status/log polling. `GuzzleProxmoxClient` maps it to Proxmox's QEMU clone endpoint and returns the UPID string from the JSON `data` payload.
- `ProxmoxVmCloneRequest` is a typed request model for node/template VMID/target VMID/name/full-clone/storage parameters and owns the form-parameter shape passed to Guzzle.
- `ProxmoxProviderOperations::requestClone()` submits a clone exactly once per deployment-operation idempotency key, decodes the returned UPID, persists the Proxmox ProviderTask ledger fields, and dispatches `PollProxmoxTask` after commit.
- Repeated calls with the same idempotency key return the existing ProviderTask without calling Proxmox again or pushing a second poll job.
- Contract tests cover QEMU clone request mapping through Guzzle and idempotent ProviderTask creation/dispatch through the provider operation adapter.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, and `composer test` pass with 108 tests / 574 assertions.

The Proxmox capability-probe increment is in place:

- `ProxmoxClientContract` now exposes `version()` and `GuzzleProxmoxClient` maps `/api2/json/version` into a typed `ProxmoxVersion`.
- `ProxmoxVersion` parses major/minor/patch and exposes `supportsAtLeast()`, giving later provider code a typed feature-gating primitive instead of raw version strings.
- `ClusterCapabilities` and `CapabilityProbe` surface first-pass M3 feature flags for cloud-init, SDN, PVE 9.2 dynamic SDN load balancing, backup, and console support.
- Tiny/Contract tests cover Guzzle version mapping and the roadmap-called-out difference between PVE 8.4 and PVE 9.2 dynamic SDN load-balancer capability.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, and `composer test` pass with 110 tests / 582 assertions.

The Proxmox deployment-lifecycle integration increment is in place:

- `DeploymentStoreController` now routes Stack definitions with `definition.provider=proxmox` to a dedicated `ProxmoxDeploymentLifecycle`; existing fake-provider flows remain on `FakeDeploymentLifecycle`.
- Proxmox-backed catalog deployments create a pending `Deployment`, pending `DeploymentResource`, pending `DeploymentOperation`, requester `RoleBinding`, initial state transition, and deployment-request audit row before submitting the clone request.
- `ProxmoxDeploymentLifecycle` extracts the first Proxmox VM component from the Stack definition and submits a typed `ProxmoxVmCloneRequest` through `ProxmoxProviderOperations`, preserving idempotent ProviderTask creation.
- The Proxmox ProviderTask now carries deployment-resource provenance (`deployment_resource_id`, `component_key`) so task polling can reconcile the RackLab resource from the UPID result.
- `TaskPoller` now promotes completed clone tasks into RackLab state: the pending DeploymentResource becomes `running`, `provider_resource_id` is set to the Proxmox VMID, the Deployment becomes `running`, and a `proxmox_clone_completed` state transition is recorded.
- Contract tests cover routing a Proxmox-backed catalog deployment through clone submission, pending resource/task persistence, poll-job dispatch, and VMID persistence after polling completes.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, and `composer test` pass with 111 tests / 598 assertions.

The Proxmox release operation increment is in place:

- `ProxmoxClientContract` now includes `deleteVm(ProxmoxVmDeleteRequest)`, and `GuzzleProxmoxClient` maps it to `DELETE /api2/json/nodes/{node}/qemu/{vmid}` with `purge=1`, returning the Proxmox UPID.
- `ProxmoxProviderOperations::requestDelete()` creates idempotent delete ProviderTask rows, carries deployment-resource provenance, and dispatches `PollProxmoxTask` after commit.
- `DeploymentOperationStoreController` routes `release` operations for Proxmox deployments through `ProxmoxDeploymentLifecycle::operateRelease()`; fake deployments still use the fake lifecycle.
- `TaskPoller` now maps completed Proxmox delete tasks to RackLab release state: the resource becomes `released`, the deployment becomes `released`, and a `proxmox_delete_completed` state transition is recorded.
- Contract tests cover Guzzle delete mapping, Proxmox release operation routing, idempotent delete task persistence, poll-job dispatch, and release-state reconciliation after polling.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, and `composer test` pass with 113 tests / 614 assertions.

The Proxmox power operation increment is in place:

- `ProxmoxClientContract` now includes `powerVm(ProxmoxVmPowerRequest)`, and `GuzzleProxmoxClient` maps it to `POST /api2/json/nodes/{node}/qemu/{vmid}/status/{start|stop|reset|shutdown}`.
- `StoreDeploymentOperationRequest` accepts `power_on` and `power_off` operation kinds.
- `ProxmoxProviderOperations::requestPower()` creates idempotent power ProviderTask rows, records the Proxmox power action and target deployment resource, and dispatches polling after commit.
- `DeploymentOperationStoreController` routes `power_on` / `power_off` for Proxmox deployments through `ProxmoxDeploymentLifecycle::operatePower()`.
- `TaskPoller` now reconciles completed power tasks: `stop` moves the resource and deployment to `stopped`, while `start` maps back to `running`, with a `proxmox_power_completed` state transition.
- Contract tests cover Guzzle power mapping, Proxmox power-off operation routing, task persistence, and stopped-state reconciliation after polling.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, and `composer test` pass with 115 tests / 627 assertions.

The script-output redaction hardening increment is in place:

- `ScriptContainerRunner` now treats `ScriptRun.metadata.redactions` as one-shot secret values to redact at the script-worker ledger boundary.
- Persisted stdout/stderr replace configured secrets with `[redacted]`; the original `redactions` list is removed from persisted metadata so secret values are not retained in the `script_runs` row.
- Metadata records `redaction_count` when output redaction occurs, while preserving non-secret runtime metadata from the container runtime.
- Contract tests cover stdout/stderr redaction, removal of the raw secret from metadata, and redaction-count persistence.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, and `composer test` pass with 116 tests / 631 assertions.

The script-runtime timeout/reaper hardening increment is in place:

- `ContainerRunResult` now carries a first-class `timedOut` flag, and `NativeContainerProcessRunner` normalizes Symfony process timeouts into exit code `124`, timeout metadata, captured partial stdout/stderr, and no escaping exception.
- `ScriptContainerRunner` persists timed-out runs with state `timed_out` while still applying stdout/stderr redaction before anything lands in the ledger.
- `PodmanContainerRuntime` force-removes the named container after a timed-out run via `podman rm -f racklab-script-<script_run_id>` and records cleanup result metadata.
- `PodmanCommandBuilder` labels script containers with `racklab.kind=script-run`, `racklab.script_run_id`, and creation time so cleanup tooling can find only RackLab-owned script containers.
- `PodmanStaleContainerReaper` plus `php artisan racklab:reap-script-containers --max-age=...` list script containers through Podman's label filter and remove stale RackLab script containers without relying on a real Podman host in contract tests.
- Real Podman timeout/kill behavior and sandbox isolation remain unverified locally because `podman` is not installed; those checks still require a Podman-capable integration host.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, and `composer test` pass with 120 tests / 653 assertions.

The script artifact/log capture increment is in place:

- `Artifact` and `ArtifactReference` provide the first generic tenant-scoped artifact store promised by PRD §19, with kind, content type, size, SHA-256, quarantine flag, owner scope, RBAC visibility, and storage backend/path metadata.
- `ScriptLogArtifactWriter` writes redacted stdout/stderr to the local artifact backend as `script_log` artifacts, creates references back to the producing `ScriptRun`, and stores artifact ids in script-run metadata.
- `GET /api/v1/scripts/{script}/runs/{scriptRun}` returns a project-authorized script-run payload with artifact metadata but does not expose internal storage paths.
- `GET /api/v1/artifacts/{artifact}` returns artifact bytes through RackLab auth/tenant checks; project-owned artifacts authorize via `AccessResolver` with `project.read`.
- Contract tests cover redacted log artifact creation, artifact references, script-run show payloads, and authenticated artifact download.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, and `composer test` pass with 123 tests / 674 assertions.

The first console-runner primitive validation increment is in place:

- `ConsoleScriptPrimitiveValidator` validates `openqa` / `console_script` sources as JSON arrays of allowed openQA-inspired primitives before they can be versioned.
- The accepted primitive surface now covers `send_key`, `type_string`, `wait_screen`, `assert_screen`, `wait_serial`, `script_run`, `capture_screenshot`, `capture_serial`, and `capture_artifact`, with required field checks for key/text/needle/command/path and bounded `timeout_seconds`.
- Script create and update paths call the validator before executable hashes are recorded, so unsupported console primitives cannot be approved or dispatched.
- Contract tests cover valid console automation creation and 422 rejection for an unsupported primitive.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, and `composer test` pass with 124 tests / 679 assertions.

The no-lint-overrides CI guardrail coverage increment is in place:

- `NoLintOverridesRule` already forbids inline linter suppressions in production code under `app/` and `packages/racklab/*/src/`.
- Tiny tests now prove the rule flags `@phpstan-ignore*`-style suppressions in production paths while ignoring test paths, matching the PRD §17 exception model.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, and `composer test` pass with 125 tests / 681 assertions.

The runner-output artifact capture increment is in place:

- `ContainerRunResult` can now carry typed `ContainerOutputArtifact` entries from runner implementations in addition to stdout/stderr.
- `ScriptContainerRunner` persists runner-produced artifacts through the generic artifact store, records their ids in `ScriptRun.metadata.output_artifact_ids`, and creates `ArtifactReference` rows back to the run with runner-specific purposes.
- Runner artifact capture supports the M7b artifact kinds needed for first Ansible/console automation output: `script_log`, `script_screenshot`, and `script_serial`.
- Text-like runner artifacts (`script_log`, `script_serial`, `text/*`, JSON, XML) are redacted with the same one-shot `ScriptRun.metadata.redactions` list before storage; binary screenshots are stored without text replacement.
- Contract tests cover Ansible-style result artifacts, console screenshot artifacts, console serial artifacts, artifact references, storage contents, and artifact redaction.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, and `composer test` pass with 126 tests / 694 assertions.

The first Ansible-runner validation increment is in place:

- `symfony/yaml` is installed so RackLab validates Ansible playbook source structurally instead of treating YAML as an opaque string.
- `AnsiblePlaybookValidator` validates `network` / `ansible` sources as non-empty YAML lists of plays, requires each play to declare `hosts`, and checks that `tasks` is a list when present.
- Runtime `ansible-galaxy` invocations are rejected before script versioning, matching the M7b policy that collections are pinned in the runner image and not installed from inside a job container.
- Script create and update paths call the Ansible validator before executable hashes are recorded.
- Contract tests cover valid playbook creation and 422 rejection for runtime `ansible-galaxy` use.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, and `composer test` pass with 127 tests / 699 assertions.

The opt-in fake script-runner runtime increment is in place:

- `FakeContainerRuntime` provides a deterministic, local runner backend for development and contract tests without weakening the default production-safe `UnavailableContainerRuntime`.
- `RACKLAB_CONTAINER_RUNTIME=fake` now binds `ContainerRuntime` to the fake backend; `podman` remains the explicit real-container backend, and unspecified/unknown values still fail closed.
- Fake Ansible runs parse the validated playbook source, report play/task counts, and emit an `ansible_result` `script_log` artifact through the same artifact path used by real runner outputs.
- Fake console/openQA runs parse the validated primitive source, record executed-step metadata, emit deterministic serial output, and emit screenshot / serial artifacts when the source includes `capture_screenshot` / `capture_serial` primitives.
- Contract tests cover fake Ansible execution, fake console execution, artifact storage/reference behavior, and explicit fake-runtime binding.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, and `composer test` pass with 130 tests / 715 assertions.

The fake-runtime script API journey increment is in place:

- Contract tests now exercise the full API journey for approved Ansible scripts on the fake runtime: create script, approve project scope, run script, inspect the completed run, and download the generated `ansible_result` artifact.
- Contract tests now exercise the same API journey for console/openQA scripts on the fake runtime, including generated screenshot and serial artifacts from `capture_screenshot` / `capture_serial` primitives.
- These tests prove the existing authenticated route surface can drive the runner substrate end to end with synchronous test queues and artifact download authorization.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, and `composer test` pass with 132 tests / 743 assertions.

The browser-visible fake-runtime script workflow increment is in place:

- The authenticated dashboard now includes an Automation section with project-scoped controls for running a fake Ansible check or fake console/openQA check.
- `ScriptFakeRunnerController` creates a project-scoped script, validates the built-in runner source, creates a project-scoped approval, creates a `ScriptRun`, and dispatches the correct runner job. When `RACKLAB_CONTAINER_RUNTIME=fake`, the job is executed synchronously so the browser workflow is immediately inspectable without a queue worker.
- The dashboard now lists recent visible script runs with runner, state, artifact purposes, artifact ids, and browser download links.
- `/artifacts/{artifact}` exposes the existing artifact download controller through the authenticated web middleware so dashboard links work with the user's browser session.
- Contract tests cover dashboard Ansible and console workflows end to end: visible controls, run submission, completed run persistence, dashboard artifact links, and artifact download content.
- Translation coverage for the new dashboard strings passes in English and Spanish.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer i18n:missing`, and `composer test` pass with 134 tests / 773 assertions.

The audit-emission snapshot CI gate increment is in place:

- `tests/Snapshots/AuditEventsTest.php` scans production audit emitters and compares the implemented event types to the committed `tests/Snapshots/audit-events.json` snapshot.
- The same snapshot test requires every implemented audit event type to appear in contract tests, so new audit emissions cannot land without executable behavioral coverage.
- Contract coverage now explicitly proves `project.ssh_key` and `script.update` audit rows in addition to the previously covered auth, token, deployment, script, cloud-init, host-key, and cross-tenant events.
- `composer pest:snapshots` now includes the audit-emission gate, and CI already runs the snapshot suite.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer pest:snapshots`, and `composer test` pass with 136 tests / 792 assertions.

The OpenAPI drift and Semgrep CI gate increment is in place:

- `knuckleswtf/scribe` v5.10 is installed and configured to generate RackLab's public API as OpenAPI 3.1 under `docs/api/openapi.yaml`.
- `composer openapi:generate` regenerates the committed schema, and `composer openapi:check` regenerates quietly then fails on `git diff --exit-code -- docs/api/openapi.yaml`.
- GitHub Actions now runs the OpenAPI schema-drift gate in the PHP matrix.
- `tests/Integration/OpenApiSchemaTest.php` parses the committed OpenAPI artifact and verifies every `api/v1` route method is represented, normalizing parameter names because Scribe maps Eloquent route-model parameters to their route keys.
- `.semgrep.yml` adds deterministic RackLab security rules for raw Spatie RBAC bypasses, bare tenant-scope bypasses, production linter suppressions, and shell-command process execution. GitHub Actions now has a dedicated Semgrep scanner job, and `composer security:semgrep` runs the same rules locally with `--no-git-ignore` so dirty-worktree files are scanned too.
- `roave/security-advisories` is installed as a Composer advisory blocker, complementing the existing `composer audit` CI step.
- `enlightn/security-checker` is not installed: every published version currently caps `symfony/console` below RackLab's Symfony 8 dependency set. Composer rejected the package without downgrading Symfony, so this remains a compatibility-blocked Laravel-specific scanner item.
- Current quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:integration -- tests/Integration/OpenApiSchemaTest.php`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 137 tests / 821 assertions.

The Podman integration harness increment is in place:

- `PodmanCommandBuilder` now accepts a command prefix, so local/integration hosts that require rootful Podman can use `sudo podman` without changing the production-safe default of `podman`.
- `RACKLAB_PODMAN_BINARY` is wired through `config/racklab.php` and `AppServiceProvider`, and contract tests cover both direct builder usage and container binding from RackLab config.
- `tests/Integration/PodmanRuntimeIntegrationTest.php` exercises the real `PodmanContainerRuntime` against BusyBox with RackLab's hardening flags (`--network=none`, `--read-only`, `/tmp` tmpfs, non-root uid/gid, CPU, memory, and pids limits) when Podman is actually usable.
- The integration test skips by default when Podman is unavailable; setting `RACKLAB_REQUIRE_PODMAN_INTEGRATION=1` turns that skip into a hard failure for a dedicated Podman-capable CI/host.
- Local evidence: Podman 5.8.2 was installable in this Fedora Toolbx. Rootless Podman cannot start in this namespace, and rootful `sudo podman` can run a basic cgroups-disabled container but fails RackLab's required hardened limits because the Toolbx cgroup namespace lacks `memory.max`. Full hardened runtime verification still needs a cgroup-delegated Podman host.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer openapi:check`, `composer security:semgrep`, `composer pest:snapshots`, and `composer test` pass with 140 tests / 825 assertions; one Podman integration test is skipped on this host for the cgroup reason above.

The OpenAPI request-body enrichment increment is in place:

- Every current API `FormRequest` used by a write endpoint now defines Scribe `bodyParameters()`, so `composer openapi:generate` no longer emits missing-body-parameter warnings for RackLab routes.
- `docs/api/openapi.yaml` has been regenerated with human-authored descriptions/examples for deployment creation/operations, cloud-init rendering, Project SSH keys, Project Stacks, script create/update/approval/run, Track-B token creation, and host-key phone-home.
- `tests/Integration/OpenApiSchemaTest.php` now checks both route/method coverage and representative request-body intent for high-traffic write surfaces.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer openapi:check`, `composer security:semgrep`, `composer pest:snapshots`, and `composer test` pass with 141 tests / 829 assertions; one Podman integration test is skipped on this Toolbx host for the cgroup reason above.

The OpenAPI response-defaults increment is in place:

- `App\OpenApi\RackLabResponseDefaultsGenerator` plugs into Scribe and fills response documentation when an endpoint has no explicit response extraction data.
- The generated schema now documents JSON data envelopes for normal reads/writes, collection-shaped `data` arrays for list endpoints, 204 no-content token revocation, binary artifact downloads, and replay's `{gap, events}` response shape.
- `tests/Integration/OpenApiSchemaTest.php` now proves every documented `api/v1` operation has a concrete response object and checks representative special cases for token revocation, artifact download, replay, and list endpoints.
- `composer openapi:check` remains the drift gate, so route or generator changes that alter `docs/api/openapi.yaml` fail CI until the committed artifact is regenerated.
- Current quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 142 tests / 859 assertions; one Podman integration test is skipped on this Toolbx host for the cgroup reason above.

The OpenAPI operation-summary increment is in place:

- The same `RackLabResponseDefaultsGenerator` now fills missing operation summaries and descriptions from RackLab's current route surface.
- `docs/api/openapi.yaml` now has human-readable summaries/descriptions for every current `api/v1` operation, including auth context, catalog, Projects, deployments, replay, scripts, artifacts, provisioning, and Track-B tokens.
- `tests/Integration/OpenApiSchemaTest.php` now fails if any documented operation has an empty summary or description.
- Current quality gate: `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 143 tests / 909 assertions; one Podman integration test is skipped on this Toolbx host for the cgroup reason above.

The RackLab Laravel security-check increment is in place:

- `php artisan racklab:security-check` now runs RackLab-owned Laravel configuration checks compatible with the current Laravel 13 / Symfony 8 stack.
- The command fails if Scribe Laravel routes or Try It Out are enabled, if Spatie's package-level permission Gate hook is enabled, if Proxmox TLS verification is disabled outside local/test environments, or if production has debug mode, unencrypted sessions, insecure session cookies, or non-HTTPS `APP_URL`.
- `composer security:racklab` runs the command locally, and GitHub Actions now runs it in the PHP matrix after `composer audit`.
- Integration tests cover the passing baseline, public Scribe-route failure, and production debug failure.
- This does not install `enlightn/security-checker`; that package remains incompatible with Symfony 8. The RackLab command plus Semgrep plus Roave plus `composer audit` cover the MVP gate without downgrading core framework packages.
- Current quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 146 tests / 915 assertions; one Podman integration test is skipped on this Toolbx host for the cgroup reason above.

The dashboard Track-B token management increment is in place:

- The authenticated dashboard now has an API tokens section for project-scoped Track-B token creation, listing, and revocation.
- Dashboard token creation uses the same `TrackBTokenService` path as the JSON API, so project access checks, Sanctum token creation, grant persistence, and audit semantics stay shared.
- The raw authorization header is shown only in the one-time post-create flash message as `Token ...`; subsequent dashboard loads retain the grant metadata but do not expose the secret again.
- Dashboard revocation is owner-scoped, calls the shared revoke service, deletes the underlying Sanctum token hash, and leaves the grant record visible as revoked.
- Contract tests cover the browser-facing workflow end to end: dashboard form rendering, token issue flash, non-replay of the raw token, active grant listing, revoke action, Sanctum token deletion, and revoked-state rendering.
- Current quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 147 tests / 938 assertions; one Podman integration test is skipped on this Toolbx host for the cgroup reason above.

The first authenticated dashboard Dusk workflow increment is in place:

- `tests/Browser/DashboardTokenWorkflowTest.php` drives the real browser flow for Track-B token creation and revocation from `/dashboard`.
- The Dusk workflow logs in with Laravel Dusk, creates a project-scoped token through the rendered form, verifies the one-time `Token ...` authorization header, refreshes to prove the secret is not replayed, revokes the token, and verifies the Sanctum hash is deleted while the grant becomes revoked.
- The dashboard now renders generic `session('status')` messages, fixing the browser-visible gap where token revocation flashed `Token revoked.` but the page never displayed it.
- Token form controls and revoke actions now expose non-visible `dusk` selectors so browser tests are stable without depending on copy or layout.
- Browser verification uses an isolated SQLite database at `/tmp/racklab-dusk.sqlite` and a local test server at `http://127.0.0.1:8002`.
- Current browser gate: `composer pest:browser` passes with 3 tests / 16 assertions. Current default quality gate remains `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 147 tests / 938 assertions; one Podman integration test is skipped on this Toolbx host for the cgroup reason above.

The dashboard automation Dusk workflow increment is in place:

- `tests/Browser/DashboardAutomationWorkflowTest.php` drives the browser path for running dashboard Ansible automation on the fake runtime and downloading the resulting artifact.
- The workflow provisions a real user/project fixture, clicks the dashboard's `Run Ansible` control, waits for the completed `ansible_result` artifact to appear, opens the artifact link in the browser, and verifies the JSON result body.
- Automation controls and script artifact links now expose non-visible `dusk` selectors so the browser workflow is stable.
- Browser verification now runs with `RACKLAB_CONTAINER_RUNTIME=fake` so fake Ansible jobs complete synchronously in the server process.
- Current browser gate: `composer pest:browser` passes with 4 tests / 20 assertions. Current default quality gate remains `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 147 tests / 938 assertions; one Podman integration test is skipped on this Toolbx host for the cgroup reason above.

The dashboard locale Dusk workflow increment is in place:

- `tests/Browser/DashboardLocaleWorkflowTest.php` drives the real browser path for switching the authenticated dashboard from English to Spanish.
- The workflow selects `es`, submits the locale form, waits for the dashboard title and logout action to re-render in Spanish, asserts the selector remains on `es`, and verifies `UserProfile.locale` persisted.
- The locale selector and save button now expose non-visible `dusk` selectors.
- Current browser gate: `composer pest:browser` passes with 5 tests / 24 assertions. Current default quality gate remains `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 147 tests / 938 assertions; one Podman integration test is skipped on this Toolbx host for the cgroup reason above.

The first Filament admin Dusk workflow increment is in place:

- `tests/Browser/FilamentAdminWorkflowTest.php` verifies a tenant member can enter the tenant-scoped Filament admin panel in a real browser.
- The workflow visits the tenant dashboard and browses the MVP admin resources for Projects, Courses, and Users, proving the registered resources are reachable and tenant/user data is visible through Filament.
- This did not require product-code changes beyond the existing Filament resources and tenant middleware.
- Current browser gate: `composer pest:browser` passes with 6 tests / 28 assertions. Current default quality gate remains `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 147 tests / 938 assertions; one Podman integration test is skipped on this Toolbx host for the cgroup reason above.

The Filament admin create/edit Dusk workflow increment is in place:

- `FilamentAdminWorkflowTest` now also creates and edits Courses and Projects through the real Filament browser UI.
- Course coverage fills the Filament Livewire form, creates a tenant-scoped course, verifies the generated edit form state, saves an updated name, and asserts the database update.
- Project coverage does the same for tenant-scoped projects, proving the MVP admin create/edit path works for the two mutable admin resources.
- The generated Filament field ids use dotted names (`#form\.name`, `#form\.slug`, `#form\.description`), which the test now targets directly.
- Current browser gate: `composer pest:browser` passes with 8 tests / 32 assertions. Current default quality gate remains `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 147 tests / 938 assertions; one Podman integration test is skipped on this Toolbx host for the cgroup reason above.

The OpenAPI response-example depth increment is in place:

- `RackLabResponseDefaultsGenerator` now adds concrete JSON examples for high-traffic API responses instead of only generic `{data: {id: ...}}` placeholders.
- Examples now reflect controller payloads for `/me`, Courses, Projects, Deployments, script create/update/approval/run responses, Track-B token list/create responses, and replay events.
- Track-B token creation documents the one-time `plain_text_token` and `authorization_header`; token list examples intentionally omit raw secrets.
- Deployment examples include resources and latest operation context; script-run examples include generated artifact metadata.
- `tests/Integration/OpenApiSchemaTest.php` now verifies these high-traffic examples are present, so future OpenAPI generator changes cannot silently regress to generic examples.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 148 tests / 962 assertions; one Podman integration test is skipped on this Toolbx host for the cgroup reason above. Current browser gate remains `composer pest:browser` with 8 tests / 32 assertions.

The OpenAPI no-generic-example gate increment is in place:

- The remaining lower-traffic JSON responses now have concrete examples too: catalog items/versions, Project SSH keys, Project Stacks, deployment cloud-init rendering, and provisioning host-key phone-home.
- The host-key phone-home example documents the unauthenticated guest callback response with recorded key metadata.
- Cloud-init examples show both `rendered_cloud_init` and `rendered_redacted`, making the phone-home-token redaction behavior explicit in the API artifact.
- `OpenApiSchemaTest` now fails if any current JSON response publishes the generic fallback example id, so future endpoints must either provide a real example or intentionally update the test.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 149 tests / 995 assertions; one Podman integration test is skipped on this Toolbx host for the cgroup reason above. Current browser gate remains `composer pest:browser` with 8 tests / 32 assertions.

The OpenAPI operation-specific schema increment is in place:

- Response schemas are now derived from RackLab's concrete response examples for JSON endpoints, replacing coarse `additionalProperties: true` object envelopes on current operations.
- The generated schema now exposes concrete property maps for high-traffic responses such as `/me`, Projects, Track-B token creation, and script-run artifact metadata.
- `OpenApiSchemaTest` now verifies those operation-specific schemas for representative endpoints, so future generator changes cannot silently collapse them back to generic object shapes.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 150 tests / 1020 assertions; one Podman integration test is skipped on this Toolbx host for the cgroup reason above. Current browser gate remains `composer pest:browser` with 8 tests / 32 assertions.

The browser CI hardening increment is in place:

- The GitHub Actions browser job now overrides the process-local test defaults that break Dusk in CI.
- Browser CI now uses a shared SQLite file at `database/dusk.sqlite`, `SESSION_DRIVER=file`, `APP_URL=http://127.0.0.1:8000`, and `RACKLAB_CONTAINER_RUNTIME=fake` so the Laravel server process and Pest/Dusk process see the same database, session state, and synchronous fake runner behavior.
- The job now prepares the browser database file before starting `php artisan serve`.
- Browser CI now uploads Laravel logs, Dusk screenshots, Dusk console output, and captured page source on failure, so failed browser checks are diagnosable from GitHub Actions artifacts.
- `tests/Integration/BrowserCiConfigurationTest.php` locks the browser job environment contract so it cannot regress back to `:memory:` SQLite or array sessions.
- Local CI-shaped browser verification passed with a shared SQLite file, file sessions, and fake runtime: `composer pest:browser` passes with 8 tests / 32 assertions. `npm run a11y` passes on the two configured Pa11y URLs with 0 errors.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 151 tests / 1029 assertions; one Podman integration test is skipped on this Toolbx host for the cgroup reason above.

The dedicated Podman runtime CI workflow increment is in place:

- `.github/workflows/podman-runtime-ci.yml` defines a manual workflow for the remaining external runtime gate.
- The workflow targets a self-hosted Linux runner labelled `podman` and `cgroup-delegated`, accepts a `podman-binary` input such as `podman` or `sudo podman`, and hard-fails the Podman integration test with `RACKLAB_REQUIRE_PODMAN_INTEGRATION=1`.
- The workflow runs `podman info` first for host diagnostics, then runs `composer pest:integration -- tests/Integration/PodmanRuntimeIntegrationTest.php`.
- `tests/Integration/PodmanRuntimeCiWorkflowTest.php` locks the workflow labels, hard-fail environment, Podman binary input wiring, and test command.
- This creates the concrete verification path for the cgroup-delegated host pass; it still has to be executed on an actual matching self-hosted runner before the Podman runtime can be called fully verified.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 152 tests / 1034 assertions; one Podman integration test is skipped on this Toolbx host for the cgroup reason above.

The real-Podman hardening assertion increment is in place:

- `tests/Integration/PodmanRuntimeIntegrationTest.php` now checks the RackLab hardened Podman runtime beyond a successful echo script on hosts that pass the capability probe.
- The same real-runtime path now asserts scripts execute as the configured non-root UID (`id -u` returns `10001`).
- The test also asserts the read-only root filesystem is enforced by attempting to write to `/racklab-readonly-denied` and expecting a non-zero exit.
- Network isolation is now checked without relying on external DNS or an outbound endpoint: the real-host test inspects `/proc/net/route` inside the container and fails if a default route is present under RackLab's `networkMode: none` manifest.
- Timeout cleanup is now part of the real-host path as well: the test runs a sleeping script with a two-second manifest timeout and asserts the result is marked timed out with successful cleanup metadata.
- `PodmanCommandBuilder` now emits `podman rm -f --ignore ...` for timeout cleanup and stale reaping, making cleanup idempotent when Podman has already removed the container. Contract coverage locks the explicit/rootful binary path, timeout cleanup path, and stale-container reaper path.
- Real stale-container reaping is also covered on capable hosts: the integration test creates a stopped, old, RackLab-labelled script container with a unique name, runs `PodmanStaleContainerReaper` through the native Podman process runner, and asserts the container no longer exists.
- These checks still skip locally on this Toolbx host because the existing Podman capability probe cannot run RackLab's cgroup limits here. The dedicated self-hosted workflow hard-fails instead of skipping by setting `RACKLAB_REQUIRE_PODMAN_INTEGRATION=1`.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 152 tests / 1034 assertions; one Podman integration test is skipped on this Toolbx host for the cgroup reason above.

The PostgreSQL CI smoke increment is in place:

- `.github/workflows/code-ci.yml` now includes a `PostgreSQL smoke` job backed by a real `postgres:16` GitHub Actions service.
- The job runs Laravel against `DB_CONNECTION=pgsql`, waits for the service with `pg_isready`, runs `php artisan migrate --force`, and then runs the persistence-heavy contract tests for tenancy persistence, RBAC persistence, audit hash-chain behavior, and tenant-scope/cross-tenant fetch.
- `tests/Integration/PostgresCiConfigurationTest.php` locks the workflow service image, database environment, migration command, and PostgreSQL contract-smoke command.
- A local Postgres smoke was attempted with rootful Podman, but this Toolbx host cannot run the service container reliably: bridge networking fails at netavark setup and host networking exits before Postgres listens. The CI service job is the verification path for real PostgreSQL until a less-constrained local/runtime host is available.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 153 tests / 1045 assertions; one Podman integration test is skipped on this Toolbx host for the cgroup reason above.

The Pa11y CI flexibility increment is in place:

- `.pa11yci.cjs` now derives its target base URL from `PA11Y_BASE_URL`, then `APP_URL`, and falls back to `http://127.0.0.1:8000`.
- The configured URLs remain `/` and `/hello`, but local browser/a11y checks can now run against an alternate test-server port without editing the config.
- `tests/Integration/Pa11yConfigurationTest.php` locks the environment-driven base URL and WCAG/chrome launch settings.
- Local accessibility verification passed with `APP_URL=http://127.0.0.1:8000 npm run a11y`: 2/2 URLs, 0 errors.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 153 tests / 1047 assertions; one Podman integration test is skipped on this Toolbx host for the cgroup reason above.

The Podman capability/no-new-privileges hardening increment is in place:

- `PodmanCommandBuilder` now includes `--cap-drop=all` and `--security-opt=no-new-privileges` on script-runner containers, making the PRD's dropped-capabilities/no-privilege-escalation contract explicit instead of relying on Podman's defaults.
- The hardened-command contract test now locks those flags alongside `--network=none`, `--read-only`, non-root UID, CPU/memory caps, and pids limit.
- The real-host Podman integration probe also includes the flags, so a self-hosted runtime that cannot support the full hardened manifest fails before running the rest of the sandbox assertions.
- On capable hosts, the same integration test now reads `/proc/self/status` inside the container and asserts `CapEff` is `0000000000000000` and `NoNewPrivs` is `1`.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 153 tests / 1049 assertions; one Podman integration test is skipped on this Toolbx host for the cgroup reason above.

The clean-install bootstrap increment is in place:

- `DatabaseSeeder` is now an idempotent RackLab bootstrap seed instead of Laravel's demo-user seed. It creates the configured default tenant (`RACKLAB_DEFAULT_TENANT_SLUG`, default `default`) and syncs the default RackLab RBAC catalog.
- `RbacDefaultsSynchronizer` now refreshes Spatie's permission cache after permission creation and before role permission sync, which keeps seeding safe when model events are disabled.
- `composer setup`, Composer's `post-create-project-cmd`, and the PostgreSQL smoke CI job now run `php artisan db:seed --force` after migrations so a fresh install has the default tenant and role catalog required for registration/login.
- Contract tests now prove the bootstrap seed is idempotent, creates no demo users, syncs the role catalog, and lets a newly registered user provision their personal Project on a clean seeded install.
- Local clean-install smoke passed against a throwaway SQLite database: `php artisan migrate --force`, `php artisan db:seed --force`, and `php artisan racklab:verify-audit-chain`.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 156 tests / 1063 assertions; one Podman integration test is skipped on this Toolbx host for the cgroup reason above.

The PostgreSQL browser release-smoke workflow increment is in place:

- `.github/workflows/release-smoke-ci.yml` defines a manual release-smoke workflow backed by a real `postgres:16` service.
- The workflow performs a clean install path with Composer dependencies, npm dependencies, Vite build, PostgreSQL readiness, `php artisan migrate --force`, `php artisan db:seed --force`, RackLab security config, ChromeDriver matching, Laravel server startup, `composer pest:browser`, and `npm run a11y`.
- The release-smoke environment uses `DB_CONNECTION=pgsql`, `SESSION_DRIVER=file`, and `RACKLAB_CONTAINER_RUNTIME=fake`, so the browser suite runs against a shared PostgreSQL database with deterministic fake script automation.
- Failure artifacts upload Laravel/server logs plus Dusk screenshots, console output, and page source.
- `tests/Integration/ReleaseSmokeWorkflowTest.php` locks the workflow trigger, Postgres service, app env, migration/seed/security/browser/a11y commands, and failure artifact upload.
- This workflow still has to be executed in GitHub Actions to prove the PostgreSQL browser path on a real CI runner; local Toolbx cannot run the Postgres service container reliably.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 157 tests / 1075 assertions; one Podman integration test is skipped on this Toolbx host for the cgroup reason above.

The Baseline health/readiness endpoint increment is in place:

- `/healthz` is now a public liveness endpoint that returns JSON `{"status":"ok"}` when the Laravel process can boot and route requests.
- `/readyz` is now a public readiness endpoint that verifies the configured database connection, verifies the RackLab schema has been migrated (`migrations` and `tenants` tables), and returns HTTP 503 with per-check failure details when the database or schema is not ready.
- `/readyz` also checks Redis with `PING` when the app is configured to depend on Redis for queues, cache, sessions, or `RACKLAB_HEALTH_REDIS_REQUIRED=true`. This keeps local/file-backed test profiles lightweight while making Baseline Redis failures visible to load balancers and systemd health checks.
- Browser CI and the PostgreSQL/Redis browser release-smoke workflow now wait on `/readyz` before running Dusk/Pa11y, so CI uses the operational readiness contract instead of treating `/hello` as a server-start probe.
- The SQLite browser CI job now runs `php artisan racklab:migrate --skip-plugins` before starting the Laravel server, because `/readyz` correctly refuses an unmigrated but reachable database.
- `tests/Contract/HealthEndpointTest.php` covers unauthenticated liveness, successful readiness, database-failure readiness, reachable-but-unmigrated database readiness failure, required Redis success, and required Redis failure. The browser/release-smoke workflow tests lock the `/readyz` server wait and pre-server migration step.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 184 tests / 1178 assertions; one Podman integration test is skipped on this Toolbx host for the cgroup reason above. `npm run build` passes. Local browser gate passes with 8 tests / 32 assertions after pre-migrating the Dusk SQLite database, and `npm run a11y` passes on 2/2 URLs with 0 errors.

The RackLab migrate command increment is in place:

- `php artisan racklab:migrate` now provides the M2.5 operator migration entrypoint. It runs Laravel core migrations with `--force`, runs the RackLab bootstrap seed by default, and migrates any installed-but-unmigrated plugins in deterministic slug order.
- The command supports `--no-seed` and `--skip-plugins` for narrower operational paths while keeping the default safe for a fresh install.
- `composer setup`, Composer's `post-create-project-cmd`, the PostgreSQL smoke CI job, and the PostgreSQL browser release-smoke workflow now call `php artisan racklab:migrate` instead of raw `migrate` / `db:seed` pairs.
- Contract tests cover core migration/bootstrap behavior and installed plugin migration behavior; configuration tests lock the setup and CI workflow usage.
- Local clean-install smoke passed against a throwaway SQLite database with `php artisan racklab:migrate` followed by `php artisan racklab:verify-audit-chain`.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 162 tests / 1089 assertions; one Podman integration test is skipped on this Toolbx host for the cgroup reason above.

The backup archive integrity prerequisite increment is in place:

- `BackupArchiveVerifier` and `BackupArchiveVerificationResult` provide the pure-PHP sha256/schema-version validation needed before `racklab:restore` accepts a backup archive.
- The verifier accepts the current manifest schema version (`1`) only, checks every manifest file is present, rejects sha256 mismatches, and rejects unmanifested archive files.
- Tiny tests cover the valid path, unsupported schema version, missing files, tampered file contents, and unexpected files.
- This is not the full `racklab:backup` / `racklab:restore` implementation yet; it is the integrity-checking core that those commands should call.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 167 tests / 1103 assertions; one Podman integration test is skipped on this Toolbx host for the cgroup reason above.

The RackLab backup command increment is in place:

- `BackupArchiveWriter` now writes RackLab backup archives as zip files containing `manifest.json` plus payload files. The manifest includes schema version, creation timestamp, metadata, per-file sha256 hashes, and byte counts.
- `php artisan racklab:backup --to=/path/archive.zip` creates a verified backup archive for file-backed SQLite installs by storing the database as `database.sqlite` with `database_driver=sqlite` metadata.
- The command also supports PostgreSQL installs through `pg_dump --format=custom --no-owner --no-privileges`, storing the dump as `database.pg_dump` with `database_driver=pgsql` and `database_dump_format=custom` metadata.
- `--include-redis` adds a binary-safe logical Redis dump at `redis-logical.json`. It scans the configured Redis DB indexes, stores keys and `DUMP` payloads base64-encoded with PTTL metadata, and marks the archive with `redis_included=true` / `redis_backup_format=logical-dump-v1`.
- The command refuses missing `--to`, non-file SQLite (`:memory:`), missing SQLite database files, unreadable SQLite database files, unsupported database drivers, failed `pg_dump`, and failed Redis connections/dumps.
- Tiny tests cover archive writing, verification, and Redis logical dump/restore encoding; contract tests cover successful SQLite backup, PostgreSQL `pg_dump` command construction, Redis inclusion, and in-memory SQLite refusal.
- Local command smoke passed against a throwaway migrated SQLite database: `php artisan racklab:migrate --skip-plugins`, `php artisan racklab:backup --to=/tmp/racklab-backup-smoke.zip`, and zip inspection of `database.sqlite`.

The RackLab restore and Baseline backup-drill increment is in place:

- `BackupArchiveReader` opens zip archives, extracts `manifest.json` and payload files, and runs `BackupArchiveVerifier` before `racklab:restore` trusts archive contents.
- `php artisan racklab:restore --from=/path/archive.zip --force` restores verified file-backed SQLite archives through a temp-file swap and refuses missing `--from`, unsupported archive drivers, missing payloads, in-memory SQLite, and overwrite without `--force`.
- PostgreSQL restore uses `pg_restore --clean --if-exists --no-owner --no-privileges --exit-on-error --single-transaction`, feeds the verified `database.pg_dump` through stdin, and requires `--force` because it is destructive.
- If an archive contains `redis-logical.json`, restore flushes and repopulates the included Redis DB indexes after the database restore, using Redis `RESTORE ... REPLACE` with the saved serialized payloads.
- The manual release-smoke workflow now runs both `postgres:16` and `redis:7` services, installs `postgresql-client` and `redis-tools`, runs `racklab:migrate`, writes a Redis smoke key, backs up with `--include-redis`, deletes Postgres tenant state, flushes Redis, restores with `--force`, verifies the default tenant row, verifies the Redis smoke key, then continues to browser and Pa11y smoke.
- Local SQLite backup/delete/restore smoke passed twice against throwaway migrated databases. Local Redis backup/flush/restore smoke passed against a temporary Valkey/Redis server on port 6385: `racklab:smoke` restored to `value`.
- Earlier local real PostgreSQL backup/restore smoke could not run because this Toolbx host lacked a local Postgres server and Podman Postgres attempts failed under the host networking/cgroup restrictions. A later local PostgreSQL/Redis release-smoke pass installed and used a temporary local PostgreSQL server process; see the release-smoke hardening increment below.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 181 tests / 1165 assertions; one Podman integration test is skipped on this Toolbx host for the cgroup reason above. `npm run build` passes. Local browser gate passes with 8 tests / 32 assertions, and `npm run a11y` passes on 2/2 URLs with 0 errors.

The Baseline worker-restart ops-smoke increment is in place:

- `php artisan racklab:ops-smoke` now runs a bounded fake-provider operational drill. Each cycle provisions a real default tenant/user/personal Project path, routes the deployment request through Laravel's `null` queue to model a stopped provider worker, verifies a pending provider task exists, ages it into the reconciler window, runs `ProviderTaskReconciler`, and asserts the deployment reaches `running` with no provider task left `pending` or `running`.
- The command accepts `--cycles=N` for repeated restart/drain cycles and `--backup-dir=/path` for per-cycle RackLab backup archives named `racklab-ops-smoke-cycle-NNN.zip`.
- Contract tests cover the stopped-worker/reconciler path and backup archive creation with a file-backed SQLite database.
- Local command smoke passed against a throwaway migrated SQLite database: `php artisan racklab:ops-smoke --cycles=2 --backup-dir=/tmp/racklab-ops-smoke-backups` produced two verified command cycles and two backup archives.
- This is the local/direct-process M2.5 drill path. Full systemd `systemctl restart racklab-provider-worker@1` and a true 4-hour soak still require a Baseline host or self-hosted runner with the actual worker unit/process topology.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 186 tests / 1189 assertions; one Podman integration test is skipped on this Toolbx host for the cgroup reason above. `npm run build` passes. Local browser gate passes with 8 tests / 32 assertions after pre-migrating the Dusk SQLite database, and `npm run a11y` passes on 2/2 URLs with 0 errors.

The local PostgreSQL/Redis release-smoke hardening increment is in place:

- A real service-backed local release-smoke run surfaced a PostgreSQL migration bug: `audit_events.target_tenant_set` was created as `json` while the migration created a GIN index over it. PostgreSQL rejects that shape because `json` has no default GIN operator class.
- The audit migration now uses `jsonb` for PostgreSQL audit JSON columns and keeps SQLite on `json`; the `audit_events_target_tenant_set_gin` index is now valid on PostgreSQL.
- `tests/Integration/PostgresMigrationBehaviorTest.php` locks the PostgreSQL behavior when the integration suite is pointed at a real `pgsql` database, while skipping under the default SQLite test profile.
- The manual release-smoke workflow now installs the PHP `redis` extension in `shivammathur/setup-php`, matching its `REDIS_CLIENT=phpredis` configuration. `tests/Integration/ReleaseSmokeWorkflowTest.php` locks that extension requirement.
- Local service-backed release smoke passed on this host using a temporary PostgreSQL 18.3 server and Valkey 9.0.4 process: `racklab:migrate`, Redis smoke key write, `racklab:backup --include-redis`, destructive tenant/Redis deletion, `racklab:restore --force`, default-tenant verification, Redis key verification, `/readyz`, 8 Dusk browser tests / 32 assertions, and Pa11y 2/2 URLs with 0 errors.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, and `composer test` pass with 187 tests / 1190 assertions; two integration tests are skipped in the default SQLite/Toolbx profile (Podman host capability and PostgreSQL-only migration behavior). `npm run build` passes. The service-backed browser/a11y smoke above passes against the restored PostgreSQL/Redis state.

The Reverb live-broadcast increment is in place:

- `laravel/reverb` v1.10.2 is installed; current package metadata supports Illuminate 13. `laravel-echo` v2.3.4 and `pusher-js` v8.5.0 are installed for the Vite client bundle.
- `config/broadcasting.php`, `config/reverb.php`, and `.env.example` now define the Reverb broadcaster/server defaults and Vite-facing Reverb variables. The default test profile still sets `BROADCAST_CONNECTION=null`, so tests do not require a running websocket daemon.
- `bootstrap/app.php` registers `routes/channels.php` through `withBroadcasting()` with `web`, `auth`, and `BindAuthenticatedTenant` middleware. The deployment private channel pattern is `private-tenant.{tenant_id}.deployment.{deployment_id}` and authorizes through `AccessResolver` with `deployment.read`; no raw Spatie role checks are used.
- `RackLabBroadcastEvent` wraps durable `broadcast_event_log` rows as `ShouldBroadcast` + `ShouldDispatchAfterCommit` events. `BroadcastEventLogWriter` now persists first, then dispatches the broadcastable wrapper so the replay log and live Reverb message share the same id/payload source.
- `resources/js/bootstrap.ts` initializes Laravel Echo with the Reverb/Pusher protocol when `VITE_REVERB_APP_KEY` is present; otherwise the bundle stays inert for local/test profiles.
- Contract tests cover broadcast wrapper dispatch and private deployment channel authorization/denial. Integration tests lock the Reverb/Echo dependency declarations and the config/bootstrap/frontend wiring.
- Local Reverb command smoke passed: `php artisan reverb:start --host=127.0.0.1 --port=18080` booted and listened on the expected high port, then was stopped cleanly.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, `npm audit --audit-level=high`, `npm run build`, and `composer test` pass with 191 tests / 1209 assertions; the same two integration tests are skipped in the default SQLite/Toolbx profile.

The first M6 quota-reservation increment is in place:

- `quota_limits`, `quota_reservations`, `quota_usages`, and `quota_events` now back the initial deployment quota path. Operation foreign keys use ULIDs so the schema works against PostgreSQL as well as SQLite.
- `StackQuotaEstimator` derives first-pass `vcpu` and `concurrent_deployments` quantities from Stack definitions, defaulting a VM to 1 vCPU when no sizing metadata exists.
- `QuotaReservationService` reserves applicable tenant/project/user quota buckets before provider work starts, counts pending reservations and active usage, returns 422 validation errors on denial, writes `quota_events`, and emits hash-chained `quota.denied` audit rows with the limit/request context.
- Course-scoped quota limits now apply to course members, and the shared `QuotaScopeResolver` keeps enforcement and display scope math aligned.
- Fake-provider deployment creation and `add_vm` operations reserve quota before provider-task dispatch. Provider success consumes reservations into active usage; provider failure releases reservations; release, remove-VM, and lease-expiry paths release usage.
- Proxmox-backed deployment requests reserve before clone submission, release reservations if clone submission fails, and task polling consumes clone reservations or releases quota after delete/failure terminal states.
- The authenticated dashboard now shows per-project effective quota indicators for vCPU and concurrent deployments. The displayed limit is the most restrictive currently applicable quota bucket, with reserved and active usage counted.
- Contract tests cover exhausted project vCPU denial, course-scoped quota denial, pending-provider overcommit prevention, concurrent-deployment reservations, failure release, successful consume/release behavior, and the dashboard effective-quota indicator.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, `npm audit --audit-level=high`, `npm run build`, `git diff --check`, and `composer test` pass with 198 tests / 1260 assertions; the same two integration tests are skipped in the default SQLite/Toolbx profile.

The first M6 placement-scheduler increment is in place:

- `ProviderCapacitySnapshot` now persists per-node provider inventory signals for scheduling: provider/cluster/node, health, maintenance mode, available vCPU/memory/storage, job pressure, template locality, tags, metadata, and observation time.
- `ProviderScheduler` ranks eligible Proxmox targets from those snapshots, excluding unhealthy and maintenance-mode nodes, enforcing requested vCPU/memory/storage/tag requirements, preferring template locality, then lower job pressure and higher free capacity.
- Proxmox-backed Stack deployments can now omit `proxmox.node`; RackLab schedules the deployment onto an eligible snapshot node, fills the clone request node, and emits a `deployment.scheduled` audit row with selected node, candidate set, reasons, and resource requirements.
- Contract tests cover template-locality selection, placement denial when no node has enough capacity, maintenance exclusion through the integrated Proxmox path, clone-request node selection, and scheduling audit metadata.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, `npm audit --audit-level=high`, `npm run build`, `git diff --check`, and `composer test` pass with 201 tests / 1269 assertions; the same two integration tests are skipped in the default SQLite/Toolbx profile.

The first M5a network-offering/reachability increment is in place:

- `ProviderNetwork`, `NetworkOffering`, and `DeploymentNetworkBinding` now persist the first admin-published networking model: provider-backed network mappings, offering type, management-plane reachability, provider binding metadata, and per-resource NIC bindings.
- `POST /api/v1/network-offerings` lets tenant-level admins/support publish provider-backed offerings with reachability values `routable_from_management`, `nat_from_management`, or `isolated_no_ingress`. It authorizes through `AccessResolver` against a tenant-scoped resource and the existing `network.attach_provider` permission; denied attempts emit `network.offering` audit rows.
- Stack definitions can declare VM `networks` entries with `offering_slug` or `offering_id`. Fake-provider and Proxmox provider-completion paths resolve those offerings into idempotent `DeploymentNetworkBinding` rows when VM resources become active.
- Deployment API payloads now include each resource's attached networks, offering slug, reachability, provider binding, and optional NAT management host/port. The dashboard previews SSH availability from the binding reachability, including "SSH not available" for isolated networks and "SSH via NAT" for NAT-backed offerings.
- NAT offerings persist static gateway/port metadata (`metadata.nat.host` / `metadata.nat.port`) onto deployment network bindings for later SSH-console use.
- Contract tests cover admin offering publish authorization, denied-offering audit, isolated offering resolution into deployment bindings, dashboard SSH-unavailable preview, and NAT reachability metadata.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, `npm audit --audit-level=high`, `npm run build`, `git diff --check`, and `composer test` pass with 204 tests / 1302 assertions; the same two integration tests are skipped in the default SQLite/Toolbx profile.

The M5a network-attachment validation increment is in place:

- `NetworkAttachmentValidator` now validates Stack `networks` entries before quota reservation or provider dispatch. A VM NIC entry must name a tenant-local `NetworkOffering` by slug or id.
- Fake and Proxmox deployment request paths both reject missing offerings, provider mismatches, and provider-unsupported network types with 422 validation errors before creating deployments, deployment operations, or provider tasks.
- `StackNetworkSpecExtractor` centralizes Stack NIC parsing so deployment-time validation and provider-completion binding consume the same network spec shape.
- Denied network attachment validation emits hash-chained `network.attach` audit rows with provider, stack, project, and NIC context.
- Contract tests cover missing offering references, fake-vs-Proxmox provider mismatches in both lifecycle paths, unsupported fake-provider network types, and the existing successful isolated/NAT binding flows.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, `npm audit --audit-level=high`, `npm run build`, `git diff --check`, and `composer test` pass with 208 tests / 1331 assertions; the same two integration tests are skipped in the default SQLite/Toolbx profile.

The M5a Filament network-admin increment is in place:

- Filament now exposes tenant-scoped admin resources for `ProviderNetwork` and `NetworkOffering` under a Networking navigation group.
- Provider Network forms cover provider slug, provider cluster, backend network type, external id, bridge, and VLAN tag. Network Offering forms map an offering to a provider network, select offering type/reachability, and capture optional NAT host/port metadata.
- `ProviderNetwork` and `NetworkOffering` now expose the `tenant()` relationship expected by Filament's tenant ownership scope, aligning them with the existing Project/Course admin resources.
- Contract coverage verifies the resources are registered with index/create/edit pages. Browser coverage verifies the tenant admin panel can browse Provider Networks and Network Offerings alongside Projects, Courses, and Users.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, `npm audit --audit-level=high`, `npm run build`, `git diff --check`, and `composer test` pass with 209 tests / 1339 assertions; the same two integration tests are skipped in the default SQLite/Toolbx profile. Current browser gate passes with 8 tests / 34 assertions against a CI-shaped local SQLite server.

The M6 Filament quota-admin increment is in place:

- Filament now exposes tenant-scoped `QuotaLimit` administration under an Operations navigation group.
- Admins can create and edit quota limits by scope type/id, dimension, and limit value for the v1 quota dimensions used by deployment, networking, and VPN policy.
- `QuotaLimit` now exposes the `tenant()` relationship expected by Filament's tenant ownership scope, aligning quota limits with other tenant-owned admin resources.
- Contract coverage verifies the resource is registered with index/create/edit pages. Browser coverage verifies the tenant admin panel can browse Quota Limits alongside Projects, Courses, Users, Provider Networks, and Network Offerings.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, `npm audit --audit-level=high`, `npm run build`, `git diff --check`, and `composer test` pass with 210 tests / 1343 assertions; the same two integration tests are skipped in the default SQLite/Toolbx profile. Current browser gate passes with 8 tests / 35 assertions against a CI-shaped local SQLite server.

The M6 lease-policy increment is in place:

- Deployment create requests now accept optional `lease_duration_minutes`; RackLab applies the most restrictive applicable `lease_duration_minutes` quota when a request omits an explicit duration.
- `LeasePolicyService` rejects overlong requested leases before deployment creation, writes `quota_events`, and emits hash-chained `quota.denied` audit rows with the requested duration, scope, and limit context.
- Leased deployment creation now reserves `concurrent_leased_deployments` through the existing quota reservation pipeline, so pending provider work counts before resources become active and usage releases through the existing release/expiry paths.
- Fake and Proxmox deployment creation persist `lease_expires_at` plus lease metadata on newly-created deployments; API payloads and OpenAPI examples now include `lease_expires_at`.
- The dashboard deployment table now surfaces lease expiry/no-expiry state, and Filament quota administration includes `lease_duration_minutes` and `concurrent_leased_deployments` dimensions.
- Contract coverage verifies lease-duration denial, most-restrictive automatic lease caps, and concurrent leased deployment reservation denial. OpenAPI coverage verifies the new request/response fields. Browser coverage verifies the dashboard and Filament flows after the lease indicator change.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, `npm audit --audit-level=high`, `npm run build`, `git diff --check`, and `composer test` pass with 213 tests / 1365 assertions; the same two integration tests are skipped in the default SQLite/Toolbx profile. Current browser gate passes with 8 tests / 35 assertions against a local SQLite server using file sessions, sync queue, and fake container runtime.

The M6 scheduler anti-affinity primitive is in place:

- `PlacementRequest` now accepts `antiAffinityExcludedNodes`, giving Stack/component schedulers a concrete way to keep later placements off nodes already selected for anti-affinity groups.
- `ProviderScheduler` filters excluded nodes before ranking, fails with the existing placement validation error when anti-affinity leaves no eligible node, and annotates successful decisions with an `anti_affinity` reason.
- Contract coverage verifies placement moves to a non-excluded eligible node and rejects a request when every eligible node is excluded. Existing Proxmox placement tests continue to pass against the backward-compatible request shape.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, `npm audit --audit-level=high`, `npm run build`, `git diff --check`, and `composer test` pass with 215 tests / 1369 assertions; the same two integration tests are skipped in the default SQLite/Toolbx profile. The browser gate remains green with 8 tests / 35 assertions from the lease-policy/dashboard pass.

The M5b managed-networking API / SubnetPool / router increment is in place:

- `Network` and `Subnet` now persist student-created project networks and initial subnets selected from admin-published `NetworkOffering` rows.
- `SubnetPool` now persists tenant-scoped admin-approved IPv4 pools with default/min/max prefix policy. The network create API can now omit `subnet.cidr` and allocate the next free CIDR from a pool id or slug; exhausted pools and out-of-policy prefix requests return validation errors before rows are created.
- Filament now exposes tenant-scoped Subnet Pool administration under the Networking navigation group, and browser coverage verifies the admin panel can browse it beside Provider Networks, Network Offerings, and Quota Limits.
- `POST /api/v1/networks` creates a tenant/project-scoped network from an offering id or slug, validates explicit or pool-backed IPv4 subnet shape, authorizes through `AccessResolver` with the offering-derived network permission, and returns the created network plus subnet payload.
- Private network creation now enforces the `private_networks` quota dimension before creating rows, records active `QuotaUsage`, writes `quota_events` for consumed/denied outcomes, and emits hash-chained `quota.denied` / `network.create` audit rows for denied and allowed paths.
- `Router` and `RouterNetwork` now persist the first managed L3/NAT object shape. `POST /api/v1/routers` creates a project router over two or more project networks, enforces same-provider interfaces, realizes fake-provider routers immediately, and returns interface/provider-binding payloads.
- Router creation enforces the `routers` quota dimension, records active `QuotaUsage`, writes `quota_events`, emits hash-chained `network.router` audit rows, and records quota denials with action `network.router.create`.
- The default roles now include `network.create_router` across `admin`, `support`, `instructor`, `ta`, and `student`, preserving the requested default group order (`admin`, `support`, `instructor`, `ta`, `student`) while provider attachment and public IP allocation remain admin/support controlled.
- OpenAPI response defaults and body-parameter coverage now include `POST /api/v1/networks` and `POST /api/v1/routers`, and the audit-event snapshot includes `network.create`, `network.router`, plus the quota consumption event emitted by the managed-networking flow.
- Contract coverage verifies successful student network/subnet creation with quota usage, pool-backed allocation, prefix-policy denial, exhausted-pool denial, router creation with quota usage, router authorization denial, exhausted router quota denial, and the existing attachment-validation paths.
- Current default quality gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, `npm audit --audit-level=high`, `npm run build`, `git diff --check`, and `composer test` pass with 224 tests / 1443 assertions; the same two integration tests are skipped in the default SQLite/Toolbx profile. Current browser gate passes with 8 tests / 36 assertions against a local SQLite server using file sessions, sync queue, and fake container runtime.

The M5b floating-IP and security-group increment is in place:

- `FloatingIpPool` and `FloatingIp` now persist tenant-scoped public-address pools, project allocations, optional deployment NIC bindings, provider binding metadata, lifecycle state, and release timestamps.
- `POST /api/v1/floating-ips` allocates the first available IPv4 address from a pool id or slug, optionally binds it to an attached `DeploymentNetworkBinding`, authorizes through `AccessResolver` with `network.allocate_public_ip`, enforces the `floating_ips` quota dimension, records quota usage/events, and emits hash-chained `network.floating_ip` audit rows.
- `DELETE /api/v1/floating-ips/{floatingIp}` releases the allocation, clears the binding, returns quota capacity, records a `quota.released` event, emits a release audit row, and makes the released address reusable.
- Filament now exposes tenant-scoped Floating IP Pool administration under the Networking navigation group. The resource pins explicit `IP` acronym labels so the real browser flow sees `Floating IP Pools` rather than Filament's default `Floating Ip Pools` humanization.
- `SecurityGroup` and `SecurityGroupRule` now persist project firewall policy with direction, protocol, ethertype, optional port range, remote CIDR, position, state, and fake-provider realization metadata.
- `POST /api/v1/security-groups` and `PATCH /api/v1/security-groups/{securityGroup}` create and replace full rule lists, authorize through `AccessResolver` with `network.manage_security_group`, enforce the `security_group_rules` quota dimension by active rule count, refresh fake firewall realization metadata, write quota usage/events, and emit hash-chained `network.security_group` audit rows.
- The default roles now include `network.manage_security_group` across `admin`, `support`, `instructor`, `ta`, and `student`, preserving the requested default group order (`admin`, `support`, `instructor`, `ta`, `student`) while public floating-IP allocation remains admin/support controlled.
- OpenAPI response defaults and body-parameter coverage now include floating-IP allocate/release and security-group create/update. Audit-event and role-permission snapshots lock the new events and permission catalog.
- Contract coverage verifies floating-IP allocation, release/reuse, authorization denial, quota exhaustion, security-group creation with five fake-realized rules, rule replacement, authorization denial, and rule-quota exhaustion.
- Current full gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, `npm audit --audit-level=high`, `npm run build`, `git diff --check`, and `composer test` pass with 232 tests / 1529 assertions; the same two integration tests are skipped in the default SQLite/Toolbx profile. Current browser gate passes with 8 tests / 37 assertions against a local SQLite server using file sessions, sync queue, and fake container runtime.

The M5b provider-drift repair/adoption increment is in place:

- `ProviderDrift` now persists detected provider divergence for RackLab-managed `Network`, `Router`, `FloatingIp`, and `SecurityGroup` resources with expected state, observed state, structured diff paths, detected/resolved timestamps, resolution, and actor metadata.
- `ProviderDriftDiffer` provides the pure nested diff algorithm used by the reconciler, with stable dot-path output for nested lists like security-group rules.
- `ProviderStateSnapshotter` derives RackLab's expected provider state from the managed networking models and reads fake-provider observed state from `metadata.provider_observed_state`, giving the fake provider a deterministic way to simulate out-of-band Proxmox changes.
- `php artisan racklab:detect-provider-drift --tenant=<id-or-slug> --provider=<provider>` scans managed networking resources, upserts active drift records, and emits hash-chained `provider.drift` audit rows for detected drift.
- `POST /api/v1/provider-drifts/{providerDrift}/repair` reasserts RackLab intent by resetting observed provider state to the RackLab snapshot, marks the drift repaired, and emits a repair audit row.
- `POST /api/v1/provider-drifts/{providerDrift}/adopt` treats the observed provider-side state as authoritative, updates the RackLab model from the observed snapshot, marks the drift adopted, and emits an adoption audit row. Security-group adoption replaces the local rule list from the observed provider rules.
- Repair/adopt authorization uses the existing admin/support `network.attach_provider` permission through `AccessResolver`; denied attempts emit `provider.drift` audit rows.
- Filament now exposes a tenant-scoped Provider Drift admin page under Operations with visible Repair and Adopt record actions for detected drift. Browser coverage verifies the page is reachable and surfaces a detected drift row.
- OpenAPI response defaults now include the provider-drift repair/adopt endpoints, and the audit-event snapshot locks `provider.drift`.
- Tiny, contract, OpenAPI, snapshot, and browser coverage verify nested diff output, fake-provider drift detection, repair, security-group adoption, denied repair audit, Filament resource registration, and the real browser Provider Drift admin page.
- Current full gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, `npm audit --audit-level=high`, `npm run build`, `git diff --check`, and `composer test` pass with 237 tests / 1577 assertions; the same two integration tests are skipped in the default SQLite/Toolbx profile. Current browser gate passes with 8 tests / 38 assertions against a local SQLite server using file sessions, sync queue, and fake container runtime.

The host Podman runtime verification increment is in place:

- The default execution shell is still inside the `dev` Distrobox (`/run/.containerenv`, `CONTAINER_ID=dev`), so direct nested `podman info` still fails with Podman's user-namespace pause-process error.
- Host Podman is reachable through `distrobox-host-exec podman`; `podman info` reports rootless Fedora 44 with cgroup v2, systemd cgroup manager, crun, netavark, pasta, and the delegated `cpu`, `io`, `memory`, and `pids` controllers.
- The hardened Podman integration test now passes with `RACKLAB_PODMAN_BINARY='distrobox-host-exec podman' RACKLAB_REQUIRE_PODMAN_INTEGRATION=1 vendor/bin/pest tests/Integration/PodmanRuntimeIntegrationTest.php`: 1 test / 19 assertions.
- That pass verifies RackLab's real container runner uses non-root UID `10001`, `--network=none`, read-only root, tmpfs `/tmp`, `--cap-drop=all`, `--security-opt=no-new-privileges`, CPU/memory/pids limits, no default route, timeout cleanup, and stale RackLab script-container reaping.
- The timeout path exposed a real cleanup race: `podman rm -f` defaults to a 10-second stop grace period, matching RackLab's 10-second cleanup timeout. `PodmanCommandBuilder::cleanupByName()` now adds `--time=0`, so timed-out script containers are killed immediately and cleanup returns deterministically.
- Focused contract coverage for the Podman command/runtime path passes with 8 tests / 42 assertions. The default suite still skips the Podman integration unless `RACKLAB_REQUIRE_PODMAN_INTEGRATION=1` is set.

The PostgreSQL 16 + Redis 7 host-Podman release-smoke increment is in place:

- `.github/workflows/release-smoke-ci.yml` now verifies the audit hash chain immediately after backup restore and restored-data checks, before Dusk browser tests reset the testing schema.
- `racklab:ops-smoke` now accepts `--include-redis-backup`, so Baseline-style per-cycle smoke backups can include Redis logical dumps as well as SQL state.
- `.github/workflows/release-smoke-ci.yml` now runs `php artisan racklab:ops-smoke --cycles=3 --backup-dir=/tmp/racklab-ops-smoke-backups --include-redis-backup` before the backup/restore drill.
- The local release-smoke path passed end to end against host-Podman containers using `docker.io/library/postgres:16` and `docker.io/library/redis:7`: `racklab:migrate`, three-cycle `racklab:ops-smoke` with Redis-backed per-cycle backups, Redis sentinel write, `racklab:backup --include-redis`, `migrate:fresh --force` fresh-install reset, Redis flush, `racklab:restore`, restored tenant/deployment/Redis assertions, `racklab:verify-audit-chain`, `composer security:racklab`, Dusk browser smoke, and Pa11y.
- The release smoke exposed a PostgreSQL tool-version compatibility issue: newer `pg_restore` emits `SET transaction_timeout = 0;`, which PostgreSQL 16 rejects during direct restore. `PostgresBackupService::restore()` now renders restore SQL through `pg_restore`, strips that unsupported setting, and applies the sanitized SQL through `psql --single-transaction --set=ON_ERROR_STOP=1`.
- Contract/workflow coverage verifies the PostgreSQL render/apply restore path, Redis logical dump restore path, Redis-backed ops-smoke backups, release-smoke workflow audit-chain ordering, fresh-install reset, and restored running deployment assertion.
- GitHub Code CI exposed two install-time issues that are now fixed locally: Composer resolution is locked to the documented PHP 8.3 floor (`config.platform.php=8.3.0`, Symfony 7.4-compatible lockfile), and the Browser job creates `database/dusk.sqlite` before Composer's post-autoload Laravel discovery runs.
- GitHub Code CI also exposed a clean-checkout contract-test issue: dashboard-rendering contract tests were relying on a locally-built Vite manifest. The shared non-browser test case now disables Vite; Dusk/browser jobs still build and exercise the real frontend assets.
- OpenAPI request examples for subnet and security-group nested fields are now explicit, avoiding generator fallback examples that drift across dependency versions.
- Current full gate: `composer validate --strict --no-check-publish`, `composer pint:test`, `composer larastan`, `composer rector:dry`, `composer security:racklab`, `composer openapi:check`, `composer audit`, `composer security:semgrep`, `composer pest:snapshots`, `composer i18n:missing`, `composer check-platform-reqs --no-interaction`, `npm audit --audit-level=high`, `npm run build`, `git diff --check`, and `composer test` pass with 239 tests / 1594 assertions; the same two integration tests are skipped in the default SQLite profile. The real host-Podman runtime integration also passes with 1 test / 19 assertions. The local host-Podman PostgreSQL 16 + Redis 7 release smoke passes after the PHP 8.3 lockfile fix. GitHub Release Smoke CI run `26553995225` and GitHub Code CI run `26554111500` both passed on `main`.

The M0.5 Baseline Quadlet/install scaffold increment is in place:

- `deploy/quadlets/` now ships the first Baseline runtime topology: `racklab-runtime.target`, a private Podman network, PostgreSQL 16, Redis 7, plugin package bootstrap, web, Reverb, and default-instance worker templates for provider, script, console, scheduler/reconciler, and notification pools.
- The app containers mount the persistent plugin package store at `/var/lib/racklab/plugins`, with host-side rendering through `scripts/baseline-install.sh --data-dir=...`; script and console workers also mount the host Podman socket for per-job containers.
- `scripts/baseline-install.sh` now supports the M0.5 flag surface needed for unattended Baseline installs: dry-run, config file, domain/listen/data/config/unit paths, internal/external Postgres and Redis, self-signed/provided TLS, image tag/registry, upgrade, uninstall/keep-data, log options, systemd-skip render mode, and non-interactive license acknowledgement.
- The installer renders `racklab.env`, `racklab.toml`, a FrankenPHP Caddyfile with bootstrap TLS, self-signed P-256 certs, and Quadlets into custom temp directories without touching host systemd. Real install mode copies Quadlets to the configured unit directory, daemon-reloads, enables `racklab-runtime.target`, runs `racklab:migrate`, and bootstraps the first admin through the web container.
- `php artisan racklab:bootstrap-admin` now creates or verifies the first local admin, tenant membership, personal project, project-local admin role binding, and optional one-day Track B bootstrap token file. It is idempotent for the user/project path and leaves existing admin credentials untouched.
- Focused coverage verifies Quadlet topology, installer dry-run/no-mutation behavior, missing required non-interactive flags, temp-directory rendering, and bootstrap-admin idempotence/token creation.
- Current focused gate: `vendor/bin/pest tests/Integration/BaselineInstallScriptTest.php tests/Contract/BootstrapAdminCommandTest.php` passes with 6 tests / 75 assertions. `composer pint:test`, `composer larastan`, `composer rector:dry`, `git diff --check`, and `composer test` pass with 245 tests / 1669 assertions; the same two integration tests are skipped in the default SQLite profile.
- Current direct Podman probe from this shell is not yet a cgroup-delegated runtime pass: `podman --version` reports 5.8.2, but default rootless `podman info` still fails with the pause-process namespace error. A temp rootless store can report cgroup v2, but hardened container pulls fail because this user lacks subuid/subgid ranges; passwordless `sudo podman` reports cgroup v2 but fails RackLab's required memory limit with missing `memory.max`. I did not run `podman system migrate` because it can alter the user's Podman state.

The M0.5 container image build-pipeline increment is in place:

- `Containerfile` now defines one multi-stage build for the Baseline image family. It builds Vite assets in a Node 24 stage, then assembles a PHP 8.3 FrankenPHP runtime from `dunglas/frankenphp:php8.3-bookworm` with Composer, required PHP extensions, PostgreSQL client tools, Redis tools, and Podman for script/console worker host-socket access.
- The runtime image installs production Composer dependencies from the lockfile, copies first-party package paths, copies compiled assets, discovers Laravel packages without Composer script side effects, prepares Laravel storage/cache directories, and exposes target stages for `web`, `reverb`, `provider-worker`, `script-worker`, `console-worker`, `scheduler-reconciler`, and `notification-worker`.
- `.github/workflows/build-images.yml` builds each target on Ubuntu 24.04, runs `composer audit` inside each built image, runs a minimal Artisan smoke check inside the image, installs Syft v1.44.0, emits CycloneDX and Syft JSON SBOM artifacts, enforces the GPL-3.0 / AGPL-3.0 / BUSL/BSL runtime license denylist, and publishes `ghcr.io/cyberbalsa/racklab/<image>:sha-<sha>` plus `:main` or tag aliases on non-PR events.
- `.dockerignore` keeps local-only files, vendor/node_modules, test artifacts, logs, and prebuilt public assets out of the image context so the image is built from lockfiles and the asset stage.
- Focused coverage verifies the Containerfile target family, production build-context exclusions, image matrix, Syft pin, Composer-audit image gate, CycloneDX SBOM generation, license policy gate, artifact upload, and GHCR publishing path.
- The first GitHub image runs exposed four clean-checkout/production-image issues: the Containerfile initially copied `vite.config.ts` instead of the repo's `vite.config.js`, Laravel production package discovery loaded `config/scribe.php` without the dev-only Scribe package installed, `package:discover` ran before Laravel's storage/cache directories existed, and the plugin registry queried the install database before it was configured. The Containerfile now copies the correct Vite config, creates cache/storage directories before Artisan discovery, the Scribe config now uses literal strategy names/tuples so `--no-dev` images can load Laravel config while docs generation still works when Scribe is installed, and `PluginRegistry` skips enabled-plugin boot when the install database probe is unavailable.
- Focused coverage now includes a no-autoloader Scribe config regression test that reproduces the `composer install --no-dev` package-discovery failure mode, an image-build ordering assertion for the cache-directory bootstrap, and an isolated plugin-registry boot-safety regression test for missing install databases. Current focused gate: `vendor/bin/pest tests/Integration/PluginRegistryBootSafetyTest.php tests/Integration/ScribeConfigurationTest.php tests/Integration/OpenApiSchemaTest.php tests/Integration/BuildImagesWorkflowTest.php` passes. `composer pint:test`, `composer larastan`, `composer rector:dry`, `git diff --check`, and `composer test` pass with 250 tests / 1708 assertions; the same two integration tests are skipped in the default SQLite profile. The image workflow is under active GitHub verification because this host has no Docker daemon and the local Podman runtime is not currently cgroup/subuid usable for builds.

## Next

Remaining MVP hardening after the deployment-lifecycle, script-approval, cloud-init, Proxmox, fake-runtime, audit-snapshot, OpenAPI-drift/body-detail/response-defaults/summaries, Semgrep/RackLab security checks, Podman-harness, dashboard Dusk, Baseline health/readiness, Baseline backup/restore, local worker-restart ops-smoke, host-Podman PostgreSQL/Redis release-smoke, GitHub Code CI, GitHub Release Smoke CI, Reverb live-broadcast, first M6 quota-reservation, first M6 placement-scheduler/anti-affinity primitive, M6 quota-admin/lease-policy, M5a network-offering/reachability/validation/admin-UI, M5b managed networking, M5b provider-drift repair/adoption, and host Podman runtime verification increments:

1. **`build-images-github`** — watch the first `.github/workflows/build-images.yml` run after push and fix any image-build, SBOM, or license-gate issues it surfaces.
2. **`baseline-worker-host-soak`** — run the real systemd/worker version of the M2.5 drain/soak path on a Baseline host or self-hosted runner: install with `scripts/baseline-install.sh`, restart `racklab-provider-worker@1` during active fake-provider work, and run the 4-hour soak. The local host-Podman release smoke covers PostgreSQL/Redis, Redis-backed ops-smoke backups, and restore of running fake-provider deployments; the remaining gap is the literal Quadlet/systemd worker drain. Horizon remains a dependency gap: current Packagist metadata for `laravel/horizon` v5.47.1 / 6.x-dev still allows Illuminate only through 12.x, so adding Horizon to this Laravel 13 repo is blocked until upstream compatibility changes or RackLab carries a fork.
3. **`podman-runtime-github`** — the workflow exists but requires a self-hosted runner labeled `linux`, `podman`, and `cgroup-delegated`; the attempted run was cancelled after remaining queued. The current shell can see Podman 5.8.2, but it is not yet a hardened-runtime pass without a cgroup-delegated/rootless-subuid or equivalent host fix.
4. **`ci-gates`** — monitor for a Symfony 8-compatible upstream release of `enlightn/security-checker` or an equivalent Laravel-specific scanner, and add any final custom Larastan behavior gaps that emerge from the MVP code surface.
5. **`packaging-release`** — cut the MVP release notes from `PROGRESS.md` after the image workflow and Baseline worker-host soak are green.

The next concrete step is watching/fixing the first image workflow run, followed by external verification on a real Quadlet/systemd Baseline host or self-hosted runner.
