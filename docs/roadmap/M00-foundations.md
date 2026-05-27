# M0 — Foundations

**Status:** In progress.
**Estimated effort:** 4–6 weeks.
**Depends on:** (none — this is the foundation).
**Unblocks:** every subsequent milestone.

## Goal

Stand up the bones of the RackLab codebase so every later milestone has a stable, type-checked, test-driven, pluggable surface to build against. At the end of M0 there is no user-facing functionality yet — but a developer can install the project, run the test layers, register a stub plugin, persist a `Job` row, persist an `Artifact` row, emit an audit event, look up an RBAC permission, and watch CI enforce the no-overrides linter discipline.

## In scope

- PRD §05 architecture (skeleton without provider/worker wiring).
- PRD §13 plugin system — discovery, lifecycle commands, failure isolation rules.
- PRD §14 audit, logging, and observability — audit event base schema + emission framework.
- PRD §15 i18n scaffolding (catalogs for en-US, the translation-coverage admin model).
- PRD §17 engineering quality + TDD discipline (the entire CI matrix becomes real here).
- PRD §19 data model — `Job` (multi-table inheritance base), `Artifact` + `ArtifactReference`, `PluginLifecycleState`, `PluginMigrationRecord`, the identity/scope tables (without auth flows — those land in M1).
- The strong-linting + no-overrides discipline from PRD §17 — `composer.json` + `package.json` Pint / Larastan / Rector config, the no-lint-overrides hook.

## Dependencies

The repo already has `docs/`, CI for docs, pre-commit hooks for docs. M0 adds the Laravel skeleton, code CI gates, and the abstractions everything else needs.

## Deliverables

- `composer.json` + `package.json` with lockfiles, Pint at strictest sensible settings, Larastan strict mode, Pest 4 configuration covering the four test layers.
- Laravel 13 project skeleton via `composer create-project`: domain modules under `app/Domain/`, config split for dev / test / prod, Octane entrypoint, `app/Domain/Runtime/` package with the `PluginWorkerRuntime` + `WorkerRuntime` Contracts (concrete implementations land in M2 and M12).
- Plugin lifecycle CLI: `racklab plugin install` / `migrate` / `enable` / `disable` / `rollback` / `uninstall` with the state machine from PRD §13.
- A reference `racklab/plugin-hello` plugin (Composer package with `"extra.racklab.plugin": true`) that exercises every contract surface (capability declaration, RBAC contribution, audit emission, settings schema, health check, migration shipping, i18n catalog). Used in the plugin contract smoke test in CI.
- Universal `Job` model (multi-table inheritance base, no subtypes yet) + generic `Artifact` + `ArtifactReference` models + retention sweep `ReconcilerTask` scaffold.
- Audit subsystem: `AuditEvent` model, the emitter API plugins call, the audit-emission test that fails CI on missing events.
- RBAC primitives: structured CRUD `Permission` rows for every core resource, `PermissionPack` nested trees, `RolePreset` bundles, `Role`, `RoleBinding`, `Group`, the permission-snapshot test, the share-link primitive scaffold.
- Secret backend abstraction (Protocol + a dev-only filesystem backend; real backends are plugins).
- i18n scaffolding: `resources/lang/` directory layout per locale, `trans_choice()` plural handling, the `TranslationCoverage` model, the `php artisan racklab:lang:check` translation-coverage command.
- CI for code: `.github/workflows/code-ci.yml` running Pint format + Larastan lint + Rector + Pest tiny + Pest contract + Pest integration + permission-snapshot + audit-emission + plugin contract smoke + dependency audit (`composer audit`) + Semgrep + Scribe schema generation (placeholder until API controllers land in M1/M2).
- **Pinned baseline versions** in `composer.json` + `package.json`: Laravel `^13.0`, Livewire `^4.0`, Filament `^5.0`, Laravel Octane `^2.0`, Larastan `^3.0`, Pest `^4.0`. Each pin has a documented upgrade policy.
- **An empty `console-worker` Horizon queue scaffold** (a stub Reverb-fronted `WorkerPoolSpec` declaring a Horizon queue tag that has no listeners yet) so M4's console-plugin work has a stable dependency rather than needing to add the queue itself.
- Pre-commit hooks expanded: Pint, Larastan on changed files, Pest tiny layer. The existing markdownlint / gitleaks / no-lint-overrides hooks stay.
- `docs/architecture/` updated with the M0-reflective component diagram.
- Livewire 4 + Vite toolchain skeleton: `package.json` + Vite config + `laravel-vite-plugin` wiring + daisyUI 5 + Laravel built-in i18n (`resources/lang/*`) + Alpine.js (bundled with Livewire 4) + Prettier + TypeScript 5.5 strict (for vanilla JS island sources only; Blade/Livewire are PHP). A hello-world Livewire 4 component (with an optional vanilla JS island) demonstrates the full pipeline; axe-core in Dusk covers a11y verification of the rendered output.
- `Tenant`, `TenantMembership`, `UploadSession` Eloquent models + migrations. `RoleBinding` extended with `scope_type` + `tenant_set`. `IdentifyTenant` + `SetTenantContextForOctane` middleware (per spec §5 — Laravel request lifecycle, Octane state-leak hazards addressed via terminate-time reset). Tenant-aware global scopes on existing tenant-scoped models with the migration backfilling all existing rows to a `default` tenant (RIT).
- `AuditEvent` extended with `prev_hash` + `hash` columns for tamper-evident chaining + a `php artisan racklab:verify-audit-chain` command.
- Postgres outbox table + outbox-row schema + the `php artisan audit:drain-outbox` administrative command (M0 ships the table + drain command + a contract test that proves an `AuditEvent` insert always produces a matching outbox row in the same transaction). The outbox-drainer Horizon job that reads from the outbox and dispatches to Reverb / external integrations lands in M2 alongside the production Redis Quadlet and Reverb wiring — until then, the drain command can be invoked manually or via cron, but unbounded outbox growth is *not* an M0 concern.

## Acceptance criteria

- [ ] `composer install && ./vendor/bin/pest` passes from a clean clone in under 5 minutes.
- [ ] `pre-commit run --all-files` passes from a clean clone.
- [ ] `racklab plugin install racklab/plugin-hello && racklab plugin migrate racklab/plugin-hello && racklab plugin enable racklab/plugin-hello` completes successfully and the plugin's health check reports OK.
- [ ] **Disabling** `racklab/plugin-hello` removes its routes/admin pages/hooks but leaves its models and migrations intact in Postgres; **rolling back** runs the reverse migrations (plugin must be disabled first); **uninstalling** rolls back to zero and removes plugin metadata. CI verifies each leg of this state machine separately.
- [ ] A `Job` row can be created, transition through `dispatching → pending → running → succeeded` via the universal API, and each transition emits an audit event observable via the audit query API.
- [ ] An `Artifact` can be uploaded to the filesystem backend, referenced by a `Job`, and reaped by the retention sweep after its `retention_until` passes.
- [ ] The permission-snapshot test refuses to merge a PR that changes a role's permission set without updating the snapshot.
- [ ] Every core resource has `read` / `create` / `update` / `delete` permission descriptors; every future app/plugin milestone must contribute the same CRUD surface for its resources plus any domain-specific operation permissions.
- [ ] Nested RBAC packs expand deterministically into effective permissions, reject cycles, and can be used by named role presets.
- [ ] `php artisan racklab:sync-rbac-defaults` installs the built-in CRUD permissions, nested permission packs, and role presets idempotently from the catalog definitions.
- [ ] The audit-emission test refuses to merge a PR that documents a new audit event without a code path emitting it.
- [ ] Attempting to add `@phpstan-ignore`, `// @phpstan-ignore-line`, `@psalm-suppress`, `// @phpcs:ignore`, or `// @phpcs:disable` in production code (under `app/` or `packages/racklab/*/src/`) fails pre-commit and CI.
- [ ] The translation-coverage admin command runs against an empty catalog and reports 100% coverage of the (currently zero) translatable strings; adding a translatable string and re-running shows the coverage drop until a catalog entry is added.
- [ ] Creating a `Tenant`, switching the request context to it, and creating a `Course` under it works; the `Course` row carries the tenant FK.
- [ ] A query made under tenant A's context cannot return rows owned by tenant B without an explicit `CrossTenantFetch::resolveForFetch()` call.
- [ ] Attempting cross-tenant access without sharing scope or a cross-tenant binding emits a `tenant.cross_access` audit event with `result=denied`.
- [ ] Granting a `multi_tenant` or `global` role binding requires the granter to hold a binding of equal or broader scope; escalation attempts fail with `tenant.cross_access` (`result=denied, reason=insufficient_scope`).
- [ ] `Job` and `Artifact` carry an immutable denormalized `tenant_id` column set at insert; updating it post-insert raises a model-level validation error. `AuditEvent` uses `actor_tenant` + `resource_tenant` + `target_tenant_set` (three-tenant schema) instead of a single `tenant_id`.
- [ ] Tenant context propagates correctly through Laravel request lifecycle, Octane worker reuse, and Reverb channel auth callbacks (`SetTenantContextForOctane` middleware sets the context on every request and resets at `terminate()`; channel auth pulls from the request context). A request handled under tenant A's context cannot read tenant B's rows; the Octane state-leak Pest test asserts a back-to-back request to the same worker for tenant B never sees tenant A's context.
- [ ] The `horizon_job_carries_tenant_id` contract test passes against a fake Horizon dispatcher — every Horizon job payload a code path dispatches carries `tenant_id`; a fake dispatcher refuses to accept jobs that don't. The `broadcast_event_carries_tenant_id` contract test passes against a fake Reverb channel — every broadcast event carries `tenant_id`. (Production Redis Quadlet and Reverb wiring land in M2; M0 ships the payload discipline + the contract tests.)
- [ ] An `UploadSession` row is created on session start and refuses creation if the actor has no `Tenant` (i.e., the tenant identity check is enforced at M0). The full quota gate lands in M6 — until then, M0's `UploadSession` accepts any size for tenants that exist.
- [ ] The `package.json` + Vite config + `laravel-vite-plugin` wiring + daisyUI 5 + Laravel built-in i18n (`resources/lang/*`) + axe-core in Dusk CI hook all exist and a hello Livewire 4 component renders cleanly in dev (`php artisan octane:start --server=frankenphp`), passes its Pest 4 browser-layer Dusk test, and reports zero axe-core violations on the page snapshot.
- [ ] Prettier (or equivalent JS/TS formatter) runs in pre-commit on the vanilla JS island TypeScript sources under `resources/js/islands/`. PHP code is formatted by Pint; Blade by Pint's Blade rules. There is no React/JSX in the tree, so `eslint-plugin-jsx-a11y` does not apply; axe-core via Dusk is the a11y gate.
- [ ] The new model-tenant CI test refuses to merge a tenant-scoped model without a `tenant` FK unless decorated `#[Untenanted]`.

## Test layers

- **Tiny / unit**: PHP readonly DTO plugin metadata classes; `Job` state-machine transitions; RBAC predicate logic; permission-string parsing; `trans_choice()` plural-form resolution; UPID-shape parser (even though no Proxmox plugin yet — the parser lives in core for shared use); the no-lint-overrides regex matcher.
- **Contract**: the `PluginWorkerRuntime` Protocol (against a dummy in-memory implementation); the `Plugin` lifecycle CLI; the audit emitter; the secret-backend Protocol against the dev filesystem backend.
- **Integration**: plugin install → migrate → enable → exercise → disable → rollback → uninstall end-to-end against a testcontainers Postgres; `Job` create → transition → audit visible across two processes (web + a fake worker); artifact upload → reference → retention sweep.
- **E2E**: not applicable for M0 (no UI yet beyond Filament admin). The first E2E flow lands in M1.

## Risks / open questions

- **Plugin CLI surface**: M0 implements the canonical `racklab plugin install|migrate|enable|disable|rollback|uninstall <slug>` commands from the redesign spec. Packaging can expose that surface as a thin wrapper over Artisan, but documentation and acceptance tests use the `racklab plugin ...` form.
- **Migration restart semantics**: how is the controlled restart triggered in development? Production is systemd (Baseline) or Nomad (Scale), but the dev story (`php artisan serve` or Octane `--watch`) needs a clear pattern. Probably a sentinel file the dev server checks on each reload.
- **Retention sweep cadence**: configurable; default value matters because too aggressive will surprise developers, too lax delays artifact-store debugging. Propose default 1 hour, configurable.
- **Hookspec event class discipline**: typed hookspec event classes (under `app/Events/Hookspecs/<Domain>/<Verb>Event.php`) are the contract surface; pin the event-dispatch library version and document the upgrade policy.

## Out of scope (deferred)

- API controller wiring (Laravel Sanctum + Scribe) — lands in M1 (it's coupled to auth surfaces).
- Real worker pools (`provider-worker`, `script-worker`, `console-worker`) — M2 and later. M0 just defines the Protocols.
- The `WorkerRuntime` Quadlet and Nomad implementations — M2 (Quadlet for dev) and M12 (Nomad for Scale).
- Production Redis Quadlet + Reverb daemon wiring — M2 (the deployment lifecycle needs them).
- The full PRD audit event catalog — M0 ships the framework + base events for `Job` transitions and plugin lifecycle; per-domain events land with their respective milestones.
- Cell-level retention policy (per-artifact-kind retention windows) — defaults in M0, fine-grained tuning in M13d.
