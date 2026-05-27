# RackLab Laravel Redesign — Architectural Spec

| Field | Value |
| --- | --- |
| Date | 2026-05-26 |
| Status | Draft |
| Author | Forrest Fuqua (with Claude Code + Codex review) |
| Supersedes | Stack-specific sections of the existing PRD (§05 architecture, §06 auth, §07 API, §13 plugin system, §15 UI, §17 engineering, §22 docs plugin, §23 SSH plugin) |
| Preserves | All functional requirements from PRD §02–§04, §08–§12, §14, §16, §18–§20 |

## 1. Context

The original RackLab design (Django 5.2 LTS + DRF + React islands via django-vite + Mantine + pluggy) was set aside in favor of a PHP / Laravel stack. The brief: keep every functional requirement from the PRD, replace the implementation stack with first-party Laravel ecosystem choices wherever possible, and use plugins instead of writing custom code where a maintained package fits.

This document fixes the architectural shape of the new stack. It is the source of truth for *how* RackLab is built going forward. The PRD remains the source of truth for *what* RackLab does; sections of the PRD that prescribe Python-specific implementation choices are superseded by this document and will be rewritten in a follow-up task tracked by the implementation plan.

The repo is now cleaned up — the previous stack's `src/`, `frontend/`, `tests/` etc. have been removed. Only `docs/` and the new-direction files remain.

**Relationship to existing specs in `docs/superpowers/specs/`:**

- `2026-05-24-podman-orchestration.md` — **still applies**. The Baseline (Quadlets) + Scale (Nomad + Podman driver) dual-profile model carries forward unchanged; this spec extends it by adding the per-job ephemeral container model in §7.
- `2026-05-24-proxmox-client-discipline.md` — **still applies**. The typed-client + task-polling + multi-issuer TLS-trust discipline is identical regardless of language; it ports from `proxmoxer` (Python) to a Guzzle-based PHP client.
- `2026-05-24-server-side-tls-acme.md` — **partially superseded**. Caddy's built-in TLS in FrankenPHP supersedes the *standard public-cert ACME profile* for Baseline. The three remaining issuance profiles (manual cert upload, custom internal CA, ACME-DNS-01 for private domains) still apply for both Baseline and Scale and are configured via Caddy's TLS directives; see §3 of this document. The original spec file was removed in the cleanup commit; its profile semantics carry forward in §3 here.

## 2. Stack (final, verified late May 2026)

| Slot | Pick | Version |
| --- | --- | --- |
| Language | PHP | 8.3+ (8.4 also supported; Pest 4 and Livewire 4 both require ≥8.3) |
| Framework | Laravel | 13.x (v13.11.2 current; no LTS — standard 18mo bug-fix + 24mo security) |
| Application server | FrankenPHP (Caddy + embedded PHP, MIT) + Laravel Octane (v2.17.4) worker mode | latest |
| Interactivity | Livewire 4 + bundled Alpine.js | v4.3.0 |
| CSS — public UI | Tailwind v4.1+ + daisyUI 5 | daisyui v5.5.x |
| CSS — admin UI | Tailwind v4.1+ + Filament 5 (MIT) vendor styles | Filament v5.6.5 |
| Vite entries | Separate `app.css` (Tailwind + daisyUI, public) and `filament.css` (Filament vendor, admin) | — |
| Multi-tenancy scaffolding | spatie/laravel-multitenancy + Filament 5 tenancy (with `isPersistent: true`) | spatie v4.1.3 |
| Multi-tenancy security | Custom — `tenant_id` columns, global scopes, `#[Untenanted]` Larastan rule, cross-tenant audit, queue context, channel auth | RackLab core |
| Real-time | Laravel Reverb (MIT, WebSockets, Pusher protocol) + Echo client + Livewire 4 broadcasting | ^1.10.2 |
| Real-time replay | Custom `GET /api/v1/replay?channel=…&since=…` endpoint backed by Postgres `broadcast_event_log` table with Laravel `ShouldBroadcast` + `ShouldDispatchAfterCommit` persist-before-broadcast discipline (matches PRD §07 Last-Event-ID semantics; see §7 for the table schema) | RackLab core |
| Auth — session/cookie | Sanctum | v4.3.2 |
| Auth — Track B opaque PAT (PRD §06) | Sanctum opaque PATs (scoped via abilities) | v4.3.2 |
| Auth — Track A signed JWT (PRD §06, required for console grants / share links / deployment tokens) | `firebase/php-jwt` (RS256) + custom `App\Auth\Jwt\TrackAIssuer` + `App\Http\Controllers\JwksController` | firebase/php-jwt ^7.0 |
| Auth — login / 2FA / passkey backend | Fortify | v1.37.2 |
| Auth — OAuth providers | Socialite | v5.27.0 |
| Auth — OIDC | Kovah/laravel-socialite-oidc | ^0.8.0 |
| Auth — SAML | socialiteproviders/saml2 | v4.8.0 |
| Queue + jobs | Horizon (Redis) — requires `pcntl` + `posix`. Also runs all script-container orchestration (NOT Octane request workers) | v5.47 |
| Audit | Custom append-only `AuditEvent` + hash chain (load-bearing core) + owen-it/laravel-auditing as subordinate model-change feed | owen-it v14 |
| OpenAPI | knuckleswtf/scribe | Scribe v5.10 |
| File uploads | spatie/livewire-filepond + custom chunk/retry/checksum design for VM/artifact flows | spatie v1.7.1 |
| Storage backends | Flysystem + plugin family (`racklab/storage-s3`, `racklab/storage-gcs`, `racklab/storage-azure`, `racklab/storage-proxmox-shared`) | Flysystem v3.34 |
| Observability | Pulse v1.7.3 (in-product) + Telescope v5.20 (dev only) + sentry/sentry-laravel v4.25.1 + spatie/laravel-health v1.39.3 | — |
| Other Spatie packages | laravel-permission v7.4.1, laravel-settings v3.9.0, laravel-backup v10.2.1, laravel-medialibrary v11.22.1. **Dropped**: `spatie/laravel-activitylog` (overlaps custom AuditEvent + latest v5 requires PHP 8.4) | — |
| Heavy JS islands | `@xterm/xterm@6.0.0`, `@novnc/novnc@1.7.0` (MPL-2.0), `chart.js@4.5.1`, `filepond@4.32.12`, `@tiptap/core@3.23.6` | latest |
| Quality tooling | Pest 4 (v4.7.0) + Pint v1.29.1 + larastan/larastan v3.9.6 (PHPStan 2 max level) + rector/rector v2.4.5 + Laravel Dusk v8.6 | — |
| Proxmox client | Codegen-from-schema PHP client (build-time generator reads Proxmox's `pve-doc-generator` JSON Schema dump and emits typed PSR + readonly DTO classes) + Guzzle 7.10 transport + hand-rolled discipline layer (task polling, retries, multi-issuer TLS trust, structured error mapping per `docs/superpowers/specs/2026-05-24-proxmox-client-discipline.md`). Same authoritative source as Proxmox's official `libpve-apiclient-perl`; PHP-only at runtime. | generator: RackLab core |
| Script execution | Per-job ephemeral Podman/Docker containers; Ansible runs inside container substrate; nsjail dropped | — |
| Plugin authoring | Composer packages + ServiceProvider + typed hookspec event bus over Laravel Events | RackLab core |

## 3. Process topology & deployment

```text
┌─────────────────────────────────────────────────────────────┐
│  FrankenPHP (Caddy + embedded PHP, single static binary)    │
│  ├─ Laravel Octane worker mode (app booted in memory)       │
│  │  ├─ HTTP request handling (Livewire 4 components,        │
│  │  │  Filament 5 panels, JSON API, Sanctum auth)           │
│  │  └─ Broadcast publisher → Reverb                         │
│  └─ Caddy TLS (automatic ACME, HTTP/2 + HTTP/3 + 103 hints) │
└─────────────────────────────────────────────────────────────┘
                            │
       ┌────────────────────┼────────────────────────────────┐
       ▼                    ▼                                ▼
┌──────────────────────┐  ┌─────────────────────┐  ┌──────────────────────────┐
│  Postgres 16         │  │  Redis 7            │  │  Reverb daemon (MIT)     │
│  ├─ row-level tenant │  │  ├─ Queues (Horizon)│  │  ├─ Pusher protocol      │
│  │  isolation        │  │  ├─ Cache           │  │  ├─ WebSocket listener   │
│  ├─ broadcast_event_ │  │  ├─ Session         │  │  └─ Behind Caddy upstream│
│  │  log (replay log; │  │  └─ Reverb backplane│  │     (or own TLS listener)│
│  │  see §7)          │  │                     │  └──────────────────────────┘
│  └─ audit_events     │  │                     │                              
│     hash chain       │  │                     │                              
└──────────────────────┘  └─────────────────────┘                              
       │                    │
       └────────────┬───────┘
                    ▼
        ┌──────────────────────────┐
        │  Horizon workers         │
        │  (separate processes,    │
        │   pcntl/posix, tagged    │
        │   per queue)             │
        └──────────────────────────┘
                    │
                    ▼
        ┌──────────────────────────────────┐
        │  Per-job ephemeral Podman/Docker │
        │  containers                      │
        │  ├─ racklab/ansible-runner:v1    │
        │  ├─ racklab/user-script:v1       │
        │  └─ racklab/console-script:v1    │
        └──────────────────────────────────┘
                    │
                    ▼
        ┌─────────────────────┐
        │  Proxmox VE cluster │
        │  (REST API via      │
        │  Guzzle from app +  │
        │  workers + console- │
        │  script containers) │
        └─────────────────────┘
```

**Deployment profiles** (carries forward from `2026-05-24-podman-orchestration.md`):

- **Baseline (1–~50 users)**: single host. FrankenPHP, Postgres, Redis, Reverb daemon, Horizon workers, container-runtime — all on one box. Systemd units (Quadlets) for non-PHP pieces. FrankenPHP binary runs directly. Backup is a Postgres dump + Redis snapshot + filesystem tar.
- **Scale (50+ users, multi-host)**: Nomad with the Podman driver schedules everything — FrankenPHP replicas behind a Nomad load balancer, Horizon worker pools, Reverb daemon replicas (sticky sessions via Pusher cluster ID), per-job containers as Nomad batch jobs, Postgres + Redis as managed services (or Nomad-scheduled if self-hosting).

**Supersession**: the Traefik 3.x ACME design in the deleted `2026-05-24-server-side-tls-acme.md` is superseded by Caddy's built-in TLS in FrankenPHP for Baseline. For Scale, the four ACME issuance profiles still apply but are configured against Caddy/FrankenPHP rather than Traefik, or fronted by a load balancer that terminates TLS upstream. The profile details are in §3 of this document.

## 4. Application layering & repo layout

Standard Laravel layout with RackLab-specific modules under `app/` and first-party plugins in `packages/`.

```text
.
├── AGENTS.md, CLAUDE.md             — agent orientation (rewritten for the new stack
│                                     in a follow-up step tracked by the implementation plan)
├── PROGRESS.md                      — shipping state
├── composer.json, composer.lock     — PHP deps
├── package.json, vite.config.ts     — Tailwind v4, daisyUI, Livewire bundle,
│                                     vanilla JS islands
├── pint.json                        — Pint formatter config
├── phpstan.neon                     — Larastan max level, no-overrides discipline
├── rector.php                       — automated refactor rules
├── pest.xml                         — Pest 4 config (tiny/contract/integration/browser)
├── lefthook.yml                     — pre-commit hooks (or captainhook)
│
├── docs/                            — PRD §01–§23 + roadmap (rewritten section-by-section
│                                     in the implementation plan; this spec lives in
│                                     docs/superpowers/specs/)
│
├── app/                             — Laravel application code
│   ├── Console/                     — Artisan commands (incl. `racklab plugin install/migrate/...`)
│   ├── Http/
│   │   ├── Controllers/             — JSON API controllers (Sanctum-protected)
│   │   ├── Middleware/              — IdentifyTenant, SetTenantContextForOctane,
│   │   │                              RoleBindingScope, AuditCorrelation
│   │   ├── Resources/               — API resource transformers
│   │   └── Requests/                — FormRequest validation (drives Scribe OpenAPI)
│   ├── Livewire/                    — Public-facing Livewire 4 single-file components
│   ├── Filament/                    — Admin panel: Resources, Pages, Widgets, Plugins
│   ├── Models/                      — Eloquent models (Tenant, User, Project,
│   │                                  Deployment, Job, Artifact, AuditEvent, ...)
│   ├── Domain/                      — Pure-PHP services (no Eloquent / HTTP deps)
│   │   ├── Rbac/                    — permission catalog, packs, presets, predicates
│   │   ├── Tenancy/                 — AccessResolver (cross-tenant policy)
│   │   ├── Jobs/                    — Job state machine
│   │   ├── Audit/                   — AuditEvent emitter + hash-chain head
│   │   ├── Quota/                   — OpenStack-triangle quota model
│   │   └── Plugins/                 — PluginLifecycleState machine, manifest contracts
│   ├── Jobs/                        — Laravel Queue jobs (Horizon-managed)
│   │   ├── RunAnsiblePlaybook       — spawns racklab/ansible-runner:v1 container
│   │   ├── RunUserScript            — spawns racklab/user-script:v1 container
│   │   ├── RunConsoleScript         — spawns racklab/console-script:v1 container
│   │   └── PollProxmoxTask          — task-polling discipline from
│   │                                  docs/superpowers/specs/2026-05-24-proxmox-client-discipline.md
│   ├── Events/                      — Broadcastable events (ShouldBroadcast)
│   │   └── Hookspecs/               — ~80 typed hookspec event classes (Pre/Post/Resolver/Validator)
│   ├── Providers/
│   │   ├── AppServiceProvider
│   │   ├── PluginServiceProvider    — discovers + boots installed plugins
│   │   ├── HookspecServiceProvider  — registers hookspec catalog + dispatcher
│   │   └── ProxmoxServiceProvider   — registers the codegen-from-schema Proxmox client w/ multi-issuer TLS trust; Guzzle transport
│   ├── Plugins/                     — Plugin runtime: hook dispatcher, manifest loader
│   └── Providers/Proxmox/           — Proxmox VE REST client (replaces proxmoxer)
│
├── packages/                        — first-party plugins developed in-monorepo,
│   │                                  each its own Composer package
│   ├── racklab/plugin-hello/        — reference plugin
│   ├── racklab/storage-proxmox-shared/  — Proxmox CephFS/NFS storage backend
│   ├── racklab/docs-plugin/         — PRD §22 (TipTap-vanilla in Livewire 4 +
│   │                                  Filament's built-in RichEditor)
│   └── racklab/ssh-plugin/          — PRD §23 (xterm.js + noVNC vanilla, cloud-init
│                                      host-key phone-home)
│
├── resources/
│   ├── views/                       — Blade templates (Livewire components + layouts)
│   ├── css/
│   │   ├── app.css                  — Public Tailwind v4 entry + `@plugin "daisyui"`
│   │   └── filament.css             — Filament 5 vendor CSS entry (separate bundle)
│   ├── js/
│   │   ├── islands/
│   │   │   ├── xterm-console.ts
│   │   │   ├── novnc-viewer.ts
│   │   │   ├── chart-board.ts
│   │   │   ├── filepond-uploader.ts (Livewire bridge)
│   │   │   └── tiptap-editor.ts
│   │   └── bootstrap.ts             — Echo + Pusher protocol client init, CSRF,
│   │                                  Livewire 4 bootstrap
│   └── lang/                        — Laravel built-in i18n (replaces LinguiJS)
│
├── routes/                          — web.php, api.php, channels.php, console.php
├── database/
│   ├── migrations/                  — core schema migrations
│   ├── factories/                   — Pest factories
│   └── seeders/                     — RBAC defaults seeder (replaces sync_rbac_defaults)
│
└── tests/
    ├── Tiny/                        — pure unit, no Laravel app boot
    ├── Contract/                    — module-boundary tests with in-memory fakes
    ├── Integration/                 — testcontainers Postgres + Redis + Podman socket
    ├── Browser/                     — Dusk E2E for named user journeys
    ├── Snapshots/                   — permission-snapshot + audit-emission gates
    └── Larastan/Rules/              — custom static-analysis rules (untenanted gate,
                                       no-lint-overrides, hookspec-typed)
```

**Boundary calls:**

- `app/Domain/` is **the boundary** — pure PHP services with no Eloquent/HTTP imports. Tiny tests run against this layer. Mirrors the role `src/racklab/core/` played in the original Django design.
- `app/Models/` is the Eloquent layer; behaviour lives in `app/Domain/`.
- `app/Filament/` is admin-only; public-facing UI lives in `app/Livewire/` and `resources/views/`.
- `packages/` lets first-party plugins live in-monorepo (synchronized release cadence) while being real Composer packages — wired into the app via `repositories: [{ type: path }]` during dev.
- Vanilla JS islands under `resources/js/islands/` are mounted by Livewire components via `wire:ignore` + `@push('scripts')` — no React.

## 5. Multi-tenancy & RBAC composition

Codex's review (`/tmp/codex-laravel-redesign-research.yARxQN.md`, P0 finding) confirms what PRD §06/§14/§19 already implied: the Spatie + Filament packages provide scaffolding, not security. RackLab's tenant-policy enforcement is core code.

### Tenant resolution chain

```text
HTTP request
  → IdentifyTenant middleware           [resolves from URL slug / Sanctum token / Filament panel]
  → spatie/laravel-multitenancy sets `currentTenant()`
  → SetTenantContextForOctane middleware  [contextvar-equivalent; resets on response]
  → Filament tenant middleware (isPersistent: true)  [Livewire AJAX requests don't re-run boot]
  → request hits Livewire / Filament / controller

Horizon job dispatch
  → TenantAwareJob trait serializes `tenant_id` + dispatching actor's active tenant into payload
  → JobMiddleware\BindTenantContext restores both before handle()
  → job runs; Eloquent global scopes filter by `tenant_id` for the active tenant context
  → AuditEvent emitted with `actor_tenant` + `resource_tenant` (+ `target_tenant_set`
    for issuance variants) FROM ENVELOPE, never from currentTenant() at audit time

Reverb WebSocket
  → channel auth (`private-tenant.{id}.deployment.{id}`) checks RoleBinding scope
    ⊇ resource tenant via AccessResolver
  → subscriber receives only events keyed to its tenant + cross-tenant-share allowlist
```

### Three-predicate access composition (PRD §06)

The `actor tenant context` is the tenant the actor is *currently operating within* — set per-request by `IdentifyTenant` middleware (or per-job by `BindTenantContext`), not derived from a `user.tenant_id` column. Users belong to multiple tenants; the active context is the question being asked.

```php
// app/Domain/Tenancy/AccessResolver.php
public function permitted(
    User $actor,
    Permission $perm,
    TenantScopedModel $resource,
    TenantContext $context,
): AccessDecision {
    $allowed = $this->bindingScopeCoversTenant($actor, $resource->tenant_id, $context)
        && $this->visibilityIncludesActor($resource, $context->activeTenantId)
        && $this->roleGrantsPermission($actor, $perm, $resource, $context);

    return new AccessDecision(
        allowed: $allowed,
        provenance: $this->resolveProvenance($actor, $resource, $context),
    );
}
```

All three predicates must pass. `bindingScopeCoversTenant` reads **RackLab's `RoleBinding`** (the load-bearing source of truth — RackLab extends, but does not delegate to, spatie/laravel-permission's role-binding rows). `RoleBinding.scope_type` is one of `tenant_local` / `multi_tenant` (with `tenant_set` array) / `global`. `visibilityIncludesActor` reads the resource's `sharing_scope` + `shared_with_tenants[]`. `roleGrantsPermission` defers to spatie/laravel-permission for the role → permission lookup *only* — never for the tenant-policy decision, which is always `AccessResolver`'s call.

**Hard rule**: any code path that bypasses `AccessResolver` (e.g. `$model->where(…)->get()` without the `TenantScoped` global scope, or `Auth::user()->hasRole(…)` called outside `AccessResolver`) is a security bug. Caught by the `NoSpatieBypassRule` Larastan rule (§8).

### Cross-tenant fetch path

Eloquent global scopes filter every query by the active tenant context. **Legitimate cross-tenant access** (shared catalog templates, multi-tenant bindings, global resources) must go through an explicit, audited fetch path:

```php
// app/Domain/Tenancy/CrossTenantFetch.php
$resolver->resolveForFetch($actor, $perm, $modelClass, $filters, $context)
    // Yields rows with computed `access_provenance` per row
    // (tenant_local / binding:<id>:<scope> / sharing:<scope>:<owner>)
    // and fires tenant.cross_access audit for each cross-tenant row read,
    // matching PRD §18 server-owned access provenance.
```

Bare `withoutGlobalScopes()` or `Model::withoutGlobalScope(TenantScope::class)` is **forbidden in production code** — enforced by the `NoBareScopeBypassRule` Larastan rule (§8). The only allowed cross-tenant fetch entry point is `CrossTenantFetch::resolveForFetch()`, which always emits the per-row `access_provenance` and writes the audit row.

### Audit event schema (PRD §19:203)

`audit_events` does **not** carry a single `tenant_id`. Per PRD §19, it carries:

- `actor_tenant` — denormalized, immutable, set at insert. The active tenant context of the actor at action time.
- `resource_tenant` — denormalized, immutable, nullable for issuance-variant events (per PRD §14 `tenant.cross_access`).
- `target_tenant_set` — JSONB list of tenant IDs the event is relevant to. Populated for issuance-variant events so the audit query can surface them to tenants in the set.
- `prev_hash` + `hash` — sha256 tamper-evident hash chain (PRD §14). `App\Console\Commands\VerifyAuditChain` walks the chain and exits non-zero on any mismatch.

Indexes on each of `actor_tenant`, `resource_tenant`, and a GIN index on `target_tenant_set` keep the bidirectional-surfacing query fast: `actor_tenant = :viewing_tenant OR resource_tenant = :viewing_tenant OR :viewing_tenant = ANY(target_tenant_set)`. The standard tenant_id global scope **does not apply** to `AuditEvent`; access is gated by the bidirectional query alone.

### Cross-tenant audit emission (PRD §14)

Every cross-tenant access fires a `tenant.cross_access` event with bidirectional surfacing — actor's tenant + resource owner's tenant + every tenant in `target_tenant_set` see the event. Issuance variant fires on cross-tenant binding / token / share-link creation. owen-it/laravel-auditing logs model-level field changes; cross-tenant access + issuance events go through Laravel Events directly into the custom `AuditEvent` table with the hash chain.

### Denormalized `tenant_id` discipline

Hot tables `jobs`, `artifacts`, `deployments`, `reservations` carry an immutable `tenant_id` column, indexed first in composite indexes. `audit_events` instead carries the three-tenant schema above. Two complementary checks enforce the rule (belt-and-suspenders, both required):

- **Static**: custom Larastan rule (`UntenantedRule`, see §8) refuses CI if `class extends Model` and no `tenant_id` migration column exists and no `#[Untenanted]` PHP attribute is present. `AuditEvent` has `#[Untenanted(reason: 'three-tenant schema, see §5')]`.
- **Runtime**: custom Pest tiny test boots the model factories and asserts each persisted row has a non-null `tenant_id` unless the model is on the untenanted allowlist.

### Octane state-leak hazard (codex P1)

`SetTenantContextForOctane` middleware **must** reset on response (`terminate()`). Pest contract test boots Octane, hits the same worker with two consecutive requests for different tenants, asserts the second never sees the first's context. Same pattern for the Proxmox client singleton (rebuilt per-tenant if tenant-specific credentials apply).

### CI gates

- Custom Larastan rule fails if `extends Model` and no `tenant_id` casts/columns and no `#[Untenanted]` attribute
- Permission-snapshot Pest test refuses merges that change a role's permission set without snapshot update
- Audit-emission Pest test refuses merges documenting a new audit event without code emitting it

## 6. Plugin model & hookspec event bus

**Plugin = Composer package + ServiceProvider + (optional) Livewire components + (optional) Filament resources + (optional) hookspec listeners.** Plugins run **in-process** with RackLab — trusted code that extends behaviour, distinct from per-job containers (which run untrusted user/Ansible scripts in isolation).

**Discovery is *not* Laravel's standard package auto-discovery.** That would boot every installed Composer package's ServiceProvider on app start, defeating the `racklab plugin enable` lifecycle gate (an installed-but-disabled plugin would still register routes/migrations/listeners). Instead, RackLab plugins declare `"extra.racklab.plugin": true` in `composer.json`, which the custom `App\Plugins\PluginRegistry` recognises during boot:

1. `composer install` puts the package on disk and adds `"extra.racklab.plugin": true`.
2. `PluginRegistry` (booted from `PluginServiceProvider`) reads `PluginInstallation` rows from the DB to find which plugins are in state `enabled`.
3. Only `enabled` plugins have their declared ServiceProviders instantiated and booted.
4. The plugin's `dont-discover` extra in composer.json (set to `"*"`) prevents Laravel's package-discovery from booting the SP behind RackLab's back.

`racklab plugin enable <slug>` is the gate that transitions an installed plugin to `enabled` and triggers `PluginRegistry::bootPlugin($slug)` — for already-running workers this requires Octane reload (graceful restart).

### Plugin anatomy

```text
racklab/plugin-mything/                    composer.json declares "extra.laravel.providers"
├── composer.json                          and (optional) "extra.racklab.manifest"
├── src/
│   ├── MyThingServiceProvider.php         standard Laravel SP — boot/register hooks
│   ├── Hooks/                             Listeners for hookspec events (typed)
│   │   ├── BeforeDeploymentCreate.php
│   │   └── AfterArtifactStore.php
│   ├── Livewire/                          public-UI components (loaded into public Vite entry)
│   ├── Filament/                          admin resources/pages (loaded into Filament panel)
│   ├── Models/                            plugin-owned Eloquent models (own tables, own tenant_id)
│   ├── Console/                           Artisan commands the plugin exposes
│   └── Manifest.php                       RackLab\Plugins\Contracts\Manifest — declares
│                                          required permissions, contributed hookspec impls,
│                                          contributed UI surfaces, capabilities
├── database/migrations/                   plugin migrations, namespaced
├── resources/views/                       Blade templates
├── routes/                                web.php / api.php — auto-prefixed by plugin slug
└── tests/                                 plugin's own Pest tests
```

### Hookspec event bus (~80 typed events from PRD §13)

Each hookspec is a **PHP class** under `app/Events/Hookspecs/<Domain>/<Verb>Event.php` (e.g. `Deployment\Creating`, `Artifact\Storing`, `Tenant\CrossAccessIssuing`, `Job\Completed`, `Storage\BackendResolving`). Plugins subscribe via the custom `App\Plugins\HookDispatcher` (not raw `Event::listen()`), or by tagging a listener class with `#[ListensTo(Creating::class)]`. The dispatcher uses Laravel's Events bus underneath but adds RackLab-specific semantics that `Event::until()` does not provide. Every hookspec event is **typed via PHP 8 readonly classes** so listeners get IDE autocomplete and Larastan-checked signatures.

**Four listener styles**, each with explicit dispatch semantics:

| Style | Examples | Sync/async | Mutation | Ordering | Failure | Timeout |
| --- | --- | --- | --- | --- | --- | --- |
| **Notification** (`post_*`, `_sink`) | `audit.appended`, `job.completed` | Async (Horizon) | None | Unordered | Isolated per listener; logged | Job-level (Horizon retry config) |
| **Filter** (`pre_*`, `_validator`) | `quota.validating`, `deployment.creating` | Sync | Listener may return modified payload or throw `AbortException` | Deterministic — listeners ordered by manifest-declared priority (default 1000), tie-break by plugin slug | Single failure aborts the operation (rolled back) | Per-listener wall-clock cap (default 500ms; configurable per hookspec) |
| **Contributor** (`_contributor`) | `container.kind.resolving`, `health.check.contributing` | Sync | Each listener contributes 0..N entries to a result set | Deterministic — manifest priority, tie-break by slug | One listener's failure does not prevent other contributions; failures are surfaced in the aggregated result | Per-listener cap (default 200ms) |
| **Resolver** (`_resolver`) | `storage.backend.resolving`, `tenant.identifier.resolving` | Sync | First listener to return non-null wins; subsequent listeners short-circuited | Deterministic — manifest priority, tie-break by slug | Failure of the highest-priority resolver falls through to next | Per-listener cap (default 200ms) |

**Critical guarantee** — **audit pre-emit must not block on filter/contributor/resolver hooks**: the `Audit\Appending` notification hook is dispatched *after* the hash chain head has been computed and the row is queued for write. Plugins cannot delay or prevent audit emission. The notification fires only if the row's transaction commits.

The `App\Plugins\HookDispatcher` enforces all of the above; raw `Event::dispatch()` / `Event::until()` calls against hookspec event classes from plugins are forbidden — caught by a Larastan rule that fails on direct dispatcher use outside `app/Plugins/`.

### Plugin lifecycle state machine (PRD §13)

```text
installed ──migrate──> migrated ──enable──> enabled ──disable──> disabled
                            │                                       │
                            └────────────rollback──────────────────┘
                                                                    │
                                                                    └──uninstall──> (removed)
```

Implemented as `racklab plugin install|migrate|enable|disable|rollback|uninstall <slug>` Artisan commands. `PluginInstallation` and `PluginMigrationRecord` Eloquent models track state. Lifecycle transitions emit hookspec events (`plugin.installing`, `plugin.migrated`, etc.) so observability/notification plugins can react.

### Storage backend plugin family

Core ships `local-fs`. S3 / GCS / Azure / MinIO / Proxmox-shared all ship as separate Composer packages implementing `RackLab\Storage\Contracts\ArtifactBackend` (wraps Flysystem 3.34 + adds RackLab-specific concerns: tenant-prefixed paths, chunk-upload coordination, server-side checksum verification, sharded artifact IDs). `racklab/storage-proxmox-shared` is the distinctive one — tunnels artifact bytes onto the Proxmox cluster's shared storage (CephFS / NFS / GlusterFS / ZFS-over-iSCSI) via `pvesm`.

### First-party plugins in monorepo

Live in `packages/racklab/*` for synchronized release cadence, shipped as real Composer packages via path-repositories during dev:

- `racklab/plugin-hello` — reference implementation
- `racklab/docs-plugin` — PRD §22 (TipTap-vanilla in Livewire 4 + Filament's RichEditor for admin)
- `racklab/ssh-plugin` — PRD §23 (xterm.js + noVNC vanilla, cloud-init host-key phone-home)
- `racklab/storage-proxmox-shared` — Proxmox cluster shared-storage backend

## 7. Script execution & real-time

### Container-job model (PRD §10, all custom on top of Horizon)

```text
Horizon worker (PHP, long-lived, pcntl/posix)        ⟵  trusted; holds provider creds
  ├─ ProviderConsoleProxy (unix socket on worker)    ⟵  trusted; the ONLY thing
  │                                                       that holds Proxmox API creds
  └─ App\Jobs\RunAnsiblePlaybook  ─┐
     App\Jobs\RunUserScript        ├─ each handle() shells out to:
     App\Jobs\RunConsoleScript     ─┘
       │
       ▼
  podman run --rm --network=none                       ⟵  PRD §18 default: NO network
             --cpus=2 --memory=4g --pids-limit=512
             --read-only --tmpfs=/tmp
             --user=10001:10001
             -v artifacts:/work/artifacts:ro
             -v /run/racklab/console-proxy.sock:/run/console-proxy.sock  # console-script only
             -e RACKLAB_JOB_ID=… -e RACKLAB_TENANT_ID=… -e RACKLAB_CORRELATION_ID=…
             -e RACKLAB_TRACK_A_JWT=<narrow-scope, one VM, short TTL>     # console-script only
             racklab/ansible-runner:v1   # or racklab/user-script:v1, racklab/console-script:v1
       │
       ▼
  container streams stdout/stderr line-by-line back through the Horizon job
       │
       ▼
  job dispatches App\Events\JobOutputChunk (ShouldBroadcast + ShouldDispatchAfterCommit)
       │
       ▼ (1) insert broadcast_event_log row in same DB transaction
       ▼ (2) after commit: dispatch to Reverb
       │
       ▼
  Reverb pushes to `private-tenant.{tid}.job.{jid}` channel
       │
       ▼
  Echo client appends to xterm.js / progress UI in the Livewire 4 component
```

**Container manifest** lives next to the job class — declares base image, resource caps, network policy, mounts (artifact volume read-only, console-proxy unix socket for console-script), env contract, required `Track A JWT` scope (for kinds that need to act on RackLab APIs). **Plugins can contribute new container kinds** by registering an `App\Events\Hookspecs\Container\KindResolving` contributor that returns a manifest for a custom kind (e.g., a Terraform plugin contributes `racklab/terraform-runner:v1`).

**Container network policy default is `--network=none`** (PRD §18 alignment). Each container kind declares a network mode explicitly in its manifest:

| Mode | What | When |
| --- | --- | --- |
| `none` (default) | No network at all | User scripts that operate only on artifact mounts |
| `via-console-proxy` | Only the `/run/console-proxy.sock` unix socket inside the container | Console-script containers |
| `egress-via-proxy` | Outbound through `podman network create racklab-egress` (`--network=racklab-egress`) which routes via an HTTP forward-proxy with per-job allow-list (destinations declared in the container manifest) | Ansible playbooks reaching out to package mirrors etc. |
| `isolated-net` | A per-job ephemeral network namespace with no upstream route (only intra-container traffic if multiple containers were spawned for the job) | Multi-step workflows that need internal coordination |

**Console-script case is special — no Proxmox credentials in the container** (PRD §18:37). The console-script container speaks only to the `ProviderConsoleProxy` over the bind-mounted unix socket. The proxy holds the Proxmox API creds; the container holds only a narrow Track A JWT scoped to a single `(tenant, deployment_resource, op_set, expiry)` tuple. The proxy authenticates each incoming request by verifying the JWT against the JWKS, then makes the actual Proxmox call (`sendkey`, `vncproxy`, `vncwebsocket`) on the container's behalf with its trusted creds. RackLab proxies the noVNC WebSocket to the browser for live-watch through this same proxy. Containers can never reach Proxmox directly; the network policy forbids it.

**Container lifecycle gotchas:**

- Horizon job timeout → `podman kill` + container cleanup (custom shutdown handler)
- Worker death → container leaks. Reaper sidecar (separate Horizon worker on `cleanup` queue) sweeps stale containers older than max-job-age. Container name encodes `RACKLAB_JOB_ID` for correlation.
- Retry semantics: script-running jobs are NOT auto-retried by Horizon — partial container side-effects are dangerous. Explicit re-dispatch by user/operator.
- **Provider-task idempotency** (carries forward from `2026-05-24-proxmox-client-discipline.md`, ported to PHP): every job that issues a Proxmox API call follows the persist-before-publish + idempotency-key + lease pattern: (1) the job persists an `IdempotencyKey` row keyed on `(tenant_id, intent, payload_hash)` inside the DB transaction; (2) when the Proxmox call returns a UPID, the UPID is stored on the row; (3) Horizon retry of the same idempotency key is a no-op if a UPID is already recorded — never resubmits a Proxmox task; (4) long-poll readers renew a `lease_expires_at` per poll cycle, reaper marks orphans as `requires_operator`. This is **separate from script-container retry semantics** above.
- Container image trust: all `racklab/*` job-runner images are pulled from a private OCI registry with cosign-signed manifests. `podman pull --signature-policy` enforces verification at pull time; CI builds sign on tag. Third-party plugin-contributed container kinds must declare their image source + signing material in the plugin Manifest, surfaced to operators at plugin install time.

### Real-time over Reverb

**Channel taxonomy** (all private; presence channels avoided for cost reasons):

| Channel | Audience | Events |
| --- | --- | --- |
| `private-tenant.{tid}.deployment.{did}` | actors with `deployment.view` on `did` | `DeploymentStateChanged`, `DeploymentResourceAttached` |
| `private-tenant.{tid}.job.{jid}` | actors with `job.view` on `jid` | `JobStateChanged`, `JobOutputChunk` |
| `private-tenant.{tid}.console.{cid}` | actors with `console.attach` on `cid` | `ConsolePreAttach`, `ConsoleAttached`, `ConsoleDetached` |
| `private-tenant.{tid}.audit.tail` | actors with `audit.tail` (typically admins) | `AuditAppended` |

**Channel auth** lives in `routes/channels.php` and delegates to `App\Domain\Tenancy\AccessResolver` — the same three-predicate composition from section 5. Channel name carries `{tid}` so the auth callback can validate `bindingScopeCoversTenant($user, $tid)` BEFORE the resource-level check.

### Last-Event-ID replay endpoint (PRD §07 semantics on top of Reverb)

Reverb gives reconnection but not durable replay. RackLab adds a small endpoint:

```http
GET /api/v1/replay?channel=private-tenant.42.job.5001&since=ev_01HXAB…
```

Backed by a **Postgres `broadcast_event_log` table** (NOT Redis Streams — Redis stream IDs are `ms-seq` not arbitrary ULIDs, and `MAXLEN` is a count cap, not a time TTL):

```sql
CREATE TABLE broadcast_event_log (
  id            ULID  PRIMARY KEY,           -- monotonic, sortable, client-facing
  tenant_id     UUID  NOT NULL,
  channel       TEXT  NOT NULL,              -- e.g. private-tenant.42.job.5001
  event_class   TEXT  NOT NULL,              -- e.g. App\Events\JobOutputChunk
  payload       JSONB NOT NULL,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
  -- BRIN index on created_at (sweep efficiency), btree on (channel, id) for replay
  -- btree on tenant_id for partition filtering; GIN reserved for JSONB fields like target_tenant_set
);
```

**Persist-before-broadcast** discipline: every event implements `ShouldBroadcast` and `ShouldDispatchAfterCommit`. The event's constructor opens (or joins) the active DB transaction, INSERTs the `broadcast_event_log` row, and only after the transaction commits does Reverb receive the dispatch. If the transaction rolls back, the broadcast never fires — preventing the "client saw event for state that doesn't exist" failure mode.

The replay endpoint reads `SELECT … FROM broadcast_event_log WHERE channel = :ch AND id > :since ORDER BY id ASC LIMIT 1000`. The client merges replay results with live Reverb messages (dedup by ULID, monotonic). The endpoint's scope/visibility check uses the same `AccessResolver`. A nightly sweep job deletes rows where `created_at < now() - interval '24 hours'`.

### Client flow on the xterm / noVNC islands

1. Mount xterm.js / noVNC instance via `@push('scripts')` in the Livewire 4 component
2. Echo subscribes to `private-tenant.{tid}.job.{jid}` (or `.console.{cid}`)
3. On WebSocket disconnect, client records last-seen ULID, reconnects via Echo
4. On reconnect, client fires `GET /api/v1/replay?since=<last_ULID>`, drains, then accepts live messages
5. xterm `write()` / noVNC `RFB` decoder gets fed the chunks in order

## 8. Quality, testing, CI, observability

### Test layering (PRD §17 — TDD non-negotiable; AI-assisted code makes tests the durable contract)

| Layer | Runner | Coverage gate | What it tests |
| --- | --- | --- | --- |
| **Tiny** | Pest 4 | 90% | Pure-PHP `app/Domain/*`, no Laravel boot, no I/O. RBAC predicates, quota math, hookspec dispatch, hash-chain head, manifest validation. |
| **Contract** | Pest 4 + Laravel container | 80% | Module-boundary with in-memory fakes (`Storage::fake()`, `Queue::fake()`, `Bus::fake()`, `Event::fake()`, `Http::fake()`). Tenant context propagation, channel auth callbacks, hookspec event emission, plugin lifecycle. |
| **Integration** | Pest 4 + Testcontainers (Postgres 16 + Redis 7 + Podman socket) | 70% | Real Eloquent against real Postgres, real Horizon dispatch against real Redis, real Reverb event broadcast. Eloquent global scopes, audit log, Filament resource CRUD, Reverb channel auth roundtrip. |
| **Browser (E2E)** | Laravel Dusk v8.6 | Named flows only | Each PRD §03 user journey gets a Dusk test: xterm console attach, noVNC viewer, FilePond chunked upload, deployment lifecycle, cross-tenant access denial. |

**Mutation testing** (`pest --mutate`) runs **nightly, not per-PR** on high-stakes surfaces: `AccessResolver`, `CrossTenantFetch`, quota math, Job state machine, Proxmox task-poller state machine, audit hash-chain head, plugin lifecycle, Track A JWT issuer/verifier. Mutation-score threshold reported in CI summary; regressions block release tags.

**Required negative-path tests** (each is a named test in the appropriate layer; CI fails if any is missing):

| Layer | Test | What it asserts |
| --- | --- | --- |
| Contract | `CrossTenantAllowedTest` | `AccessResolver` returns `allowed` with correct provenance for `multi_tenant` binding + matching `sharing_scope` |
| Contract | `CrossTenantDeniedTest` | `AccessResolver` returns `denied` with reason `insufficient_scope` when binding scope ⊉ resource tenant |
| Contract | `CrossTenantIssuanceDeniedTest` | Granter with `tenant_local` scope cannot issue `multi_tenant` binding; audit issuance-variant fires with `result=denied` |
| Contract | `ReplayGapSentinelTest` | Replay endpoint returns a `gap` sentinel when `since` ULID is older than the sweep window (24h) |
| Contract | `PluginListenerFailureIsolationTest` | A contributor-style listener that throws does not prevent other contributors from running; failure is surfaced in the aggregated result |
| Integration | `ContainerEgressDeniedTest` | A `--network=none` container cannot reach external hosts; integration test exec's `curl` in the container and asserts the call fails |
| Integration | `ProxmoxNoResubmitIdempotencyTest` | Two consecutive job dispatches with identical idempotency key + an existing UPID never call `POST` on Proxmox a second time |
| Integration | `TrackAJwtKeyRotationTest` | After JWKS key rotation, old JWT (still in TTL window) still verifies; new tokens use new key id; old `kid` is removed after grace period |
| Integration | `TrackAJwtRevocationTest` | `jti` blacklist entry blocks verification within next-cache-flush window |
| Browser | `ConsoleProxyAuthDeniedTest` | A console-script container with an expired Track A JWT receives 401 from `console-proxy.sock`; xterm shows the error |

### No-overrides linter discipline (load-bearing)

- No `@phpstan-ignore`, no `// @phpstan-ignore-line`, no `// @phpstan-ignore-next-line`, no `@psalm-suppress`, no `// @phpcs:ignore`, no `// @phpcs:disable` (block-level suppressor) in production code (`app/`, `packages/racklab/*/src/`).
- Two audited exceptions, enforced by a custom Pint/Larastan rule with a path-glob: test code may use `@phpstan-ignore` for runtime-only Eloquent attributes; `database/migrations/` Laravel auto-generated artifacts are excluded.
- If the linter is wrong, fix the underlying code OR the Larastan rule — not the source.
- Pre-commit hook (`lefthook` or `captainhook`) runs `pint --test`, `larastan --no-progress`, `rector --dry-run`, tiny-layer Pest.

### Custom Larastan rules (in `tests/Larastan/Rules/`)

1. **`UntenantedRule`** — fails if `class extends Model` AND no `tenant_id` column declared in migrations AND no `#[Untenanted]` PHP attribute. Allowlist enforced in CI.
2. **`NoLintOverridesRule`** — fails if `app/` or `packages/racklab/*/src/` contains a `@phpstan-ignore*` or `@psalm-suppress` annotation.
3. **`HookspecEventTypedRule`** — fails if a class in `app/Events/Hookspecs/**/*Event.php` is not `readonly` or doesn't have typed properties.
4. **`NoBareScopeBypassRule`** — fails on `withoutGlobalScopes()` or `withoutGlobalScope(TenantScope::class)` outside `app/Domain/Tenancy/CrossTenantFetch.php` (the only allowed cross-tenant fetch entry point — see §5).
5. **`NoSpatieBypassRule`** — fails on direct calls to `$user->hasRole(…)` / `$user->can(…)` outside `App\Domain\Tenancy\AccessResolver`. All authorisation decisions must go through `AccessResolver`.
6. **`NoBareEventDispatchOnHookspecsRule`** — fails on `Event::dispatch(SomeHookspec\Event::class)` or `Event::until(SomeHookspec\Event::class)` outside `app/Plugins/HookDispatcher.php`. Forces dispatch semantics to go through the typed dispatcher (see §6).

### Snapshot CI gates (Pest tests)

- **`tests/Snapshots/RolePermissions.test.php`** — asserts each role's permission set matches `tests/Snapshots/roles.json`. PR that mutates permissions must update the snapshot.
- **`tests/Snapshots/AuditEvents.test.php`** — asserts every documented audit event in `docs/prd/14-audit-logging-observability.md` has a code path emitting it.

### Observability stack

| Layer | Tool | Scope |
| --- | --- | --- |
| In-product dashboard | Laravel Pulse v1.7.3 | Request rate, slow queries, queue depth, job runtime, cache hit ratio. Tenant-scoped via custom Pulse recorder. |
| Dev-only debug | Laravel Telescope v5.20 | Disabled in production via `APP_ENV` gate. |
| Error tracking | sentry/sentry-laravel v4.25.1 | Production traces + breadcrumbs, `tenant_id` tag on every event. |
| Health endpoints | spatie/laravel-health v1.39.3 | `/healthz` (liveness), `/readyz` (postgres + redis + podman socket + proxmox cluster ping). Checks registered per-plugin. |
| OpenTelemetry | Deferred to M13b-equivalent | OTLP exporter behind a feature flag; not required for Baseline profile. |

### CI matrix (`.github/workflows/code-ci.yml`)

- PHP 8.3 + 8.4 matrix
- Laravel 13.x (constraint `^13.0`)
- Postgres 16 + Redis 7 + Podman 5 (Testcontainers-managed)
- Per-PR job sequence:
  1. `pint --test` (format check)
  2. `larastan --memory-limit=2G --no-progress` (custom rules incl. `UntenantedRule`, `NoBareScopeBypassRule`, `NoSpatieBypassRule`, `NoBareEventDispatchOnHookspecsRule`)
  3. `rector --dry-run`
  4. `pest --parallel --coverage --min=90` (tiny layer)
  5. `pest --testsuite=contract` (≥80% coverage)
  6. `pest --testsuite=integration` (≥70% coverage)
  7. `pest --testsuite=browser` (Dusk; named journeys)
  8. Custom snapshot gates: `RolePermissions`, `AuditEvents`
  9. **OpenAPI schema-drift gate**: `php artisan scribe:generate --no-extraction` then `git diff --exit-code docs/api/openapi.yaml` — PRs that change the route surface must update the committed OpenAPI artifact
  10. **Security scanning** beyond `composer audit` / `npm audit`: `roave/security-advisories` (composer dev dep that aborts install on known-CVE deps), `enlightn/security-checker` for Laravel-specific patterns, `phpcs-security-audit` for taint analysis on `app/Http/`, `semgrep` with Laravel + PHP rule packs for OWASP top 10
  11. **a11y gate** via axe-core in Dusk runs (every Browser test asserts no axe-core violations on the page-load snapshot)
  12. **i18n catalog drift**: custom RackLab artisan command (working name `php artisan racklab:lang:check`) — fails if any Blade/Livewire template uses `__('…')` with a string not present in `resources/lang/en/*.php` or vice-versa. Laravel core does not ship a `lang:check` command; the working name reserves the verb in our namespace. A community package (e.g. `amir9480/laravel-translations-status`) may be adopted instead during the ci-gates sub-plan.
- Nightly job: `pest --mutate` on high-stakes surfaces; mutation score posted to PR comments
- Codex review fires on PRs touching `docs/prd/`, `docs/superpowers/specs/`, `app/Domain/`, `app/Plugins/`, or `app/Auth/`

### `docs-ci.yml` (separate workflow)

- `markdownlint` on `docs/**/*.md`
- `mermaid-cli` renders Mermaid to SVG, fails on parse errors
- broken-link check (`lychee`)
- `gitleaks` scan

## 9. Open questions & deferred decisions

- **Track A JWT key rotation cadence**: the JWKS endpoint serves the current signing key + a grace-period overlap of the previous key. Cadence (30 / 60 / 90 days?) is operational, not architectural — decide during the auth implementation slice. Old `kid` removed from JWKS after grace period; subscriber JWTs minted under the old `kid` still verify until they expire.
- **OpenTelemetry exporter**: deferred to a later milestone (M13b-equivalent). Pulse + Sentry cover the Baseline observability need.
- **TimescaleDB for time-series**: deferred. PRD §02 + §14 in-product graphs use plain Postgres + BRIN indexes + materialized rollups + Chart.js until a spike proves the bottleneck.
- **Filament-plugin vetting**: licenses vary across Filament's plugin ecosystem. Each prospective Filament plugin needs a per-package licence + maintenance review before adoption. Flux is paid and proprietary — explicitly excluded.
- **PRD rewrite cadence**: the implementation plan (next step) decides whether the rewrite of PRD §05/§06/§07/§13/§15/§17/§22/§23 happens upfront in one batch or section-by-section as each implementation milestone lands.
- **`broadcast_event_log` retention**: 24h default is a starting point matched to the Last-Event-ID replay window. May extend for forensics use cases (auditors replaying a deployment lifecycle from t-7d). Cheap to bump since the table is BRIN-indexed.

## 10. Transition to implementation

After this spec is approved, the next step is the **writing-plans** skill. The work this spec implies is **too large for a single implementation plan** — codex P2 finding flagged this and we agree. The writing-plans invocation should produce **a small portfolio of focused sub-plans** rather than one mega-plan, each with its own milestones, test layers, and risk register. Suggested decomposition:

1. **`prd-rewrite`** — rewrite the stack-specific PRD sections (§05 architecture, §06 auth, §07 API, §13 plugin system, §15 UI, §17 engineering, §22 docs plugin, §23 SSH plugin). Roadmap milestone deliverables/test-layers/risks renumbering. Replace `AGENTS.md` / `CLAUDE.md` with Laravel-stack orientation. (Note: the leftover Django-era artefacts in the working tree were already cleaned up in commit `a6bc105` before this plan starts.)
2. **`laravel-scaffold`** — initial Laravel 13 + Octane + FrankenPHP + Filament 5 + Livewire 4 project skeleton. Vite entries (`app.css` public + daisyUI; `filament.css` admin). Pint + Larastan + Rector + Pest 4 wired up. CI matrix from §8.
3. **`tenancy-auth`** — `app/Domain/Tenancy/AccessResolver`, `CrossTenantFetch`, `IdentifyTenant` + `SetTenantContextForOctane` + `BindTenantContext` middleware, `RoleBinding` model with `scope_type` + `tenant_set`, spatie/laravel-multitenancy + spatie/laravel-permission integration, Filament tenancy with `isPersistent: true`. Track A JWT issuer + JWKS endpoint + Sanctum PATs + Fortify + Socialite + OIDC + SAML. `AuditEvent` three-tenant schema + hash chain + `VerifyAuditChain` command + bidirectional surfacing query.
4. **`plugin-lifecycle`** — `PluginRegistry`, `PluginInstallation` + `PluginMigrationRecord` models, `racklab plugin install/migrate/enable/disable/rollback/uninstall` Artisan commands, `HookDispatcher` with the four listener-style semantics, hookspec event class scaffold, `racklab/plugin-hello` reference implementation.
5. **`realtime-replay`** — Reverb daemon, channel auth, `broadcast_event_log` table + `ShouldBroadcast` / `ShouldDispatchAfterCommit` discipline, `/api/v1/replay` endpoint + sweep job. xterm.js + noVNC islands. Negative-path tests for replay gap sentinel.
6. **`script-containers`** — Horizon worker setup (pcntl/posix), `RunAnsiblePlaybook` + `RunUserScript` + `RunConsoleScript` job classes, container manifests, `ProviderConsoleProxy` unix-socket service, container image build pipeline (cosign-signed), reaper sidecar. Provider-task idempotency port from `2026-05-24-proxmox-client-discipline.md`.
7. **`ci-gates`** — custom Larastan rules (`UntenantedRule`, `NoBareScopeBypassRule`, `NoSpatieBypassRule`, `NoBareEventDispatchOnHookspecsRule`, `NoLintOverridesRule`, `HookspecEventTypedRule`), snapshot tests, OpenAPI schema-drift gate, semgrep + security-checker, axe-core a11y in Dusk, the custom `racklab:lang:check` (or adopted equivalent) i18n drift gate.

Sub-plans 2 → 7 can run in parallel after sub-plan 1 (PRD rewrite) sets the functional ground truth. Some natural ordering still applies (e.g., `tenancy-auth` should land before `plugin-lifecycle` since plugins use the AccessResolver).

## Appendix A — Research provenance

- **Independent codex review**: `/tmp/codex-laravel-redesign-research.yARxQN.md` (May 26, 2026). Surfaced two P0 findings (Mercure AGPL-3.0 contamination → switched to Reverb; Spatie + Filament tenancy is scaffolding not security → custom AccessResolver) and ~12 P1 corrections (Pest 4, Filament tenancy persistence, Octane state-leak hazards, mvanduijker adapter staleness, etc.).
- **Key URLs consulted**:
  - [Laravel 13 release notes](https://laravel.com/docs/13.x/releases)
  - [Filament 5 installation](https://filamentphp.com/docs/5.x/introduction/installation)
  - [Filament 5 tenancy](https://filamentphp.com/docs/5.x/users/tenancy)
  - [Livewire 4 upgrade guide](https://livewire.laravel.com/docs/4.x/upgrading)
  - [FrankenPHP + Laravel](https://frankenphp.dev/docs/laravel/)
  - [Laravel Octane](https://laravel.com/docs/13.x/octane)
  - [Laravel Reverb](https://laravel.com/docs/reverb)
  - [daisyUI v5 upgrade](https://daisyui.com/docs/upgrade/)
  - [spatie/laravel-multitenancy v4](https://spatie.be/docs/laravel-multitenancy/v4/installation/base-installation)
  - [Pest v4 docs](https://pestphp.com/docs/installation)
  - [Mercure license (AGPL-3.0)](https://github.com/dunglas/mercure)

## Appendix B — Decisions log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-26 | Adopt PHP / Laravel 13 stack over Django 5.2 LTS | User direction; full Laravel ecosystem play |
| 2026-05-26 | Livewire 4 over alternatives (pure Blade, HTMX, Inertia) | Reactive UX; Filament 5 alignment; bundled Alpine |
| 2026-05-26 | Tailwind v4 + Filament 5 + daisyUI 5 (admin + public CSS in separate Vite entries) | Ecosystem default; Filament 5 requires Tailwind 4.1+ |
| 2026-05-26 | spatie/laravel-multitenancy + Filament tenancy (scaffolding) + custom AccessResolver (security) | Packages don't implement RackLab's cross-tenant policy |
| 2026-05-26 | Reverb (WebSockets, MIT) over Mercure (SSE, AGPL-3.0) | License compatibility with Apache-2.0 RackLab |
| 2026-05-26 | Sanctum-only auth tokens (cookie + PATs); PRD §06 Track-A JWT deferred | YAGNI until a concrete Track-A consumer appears |
| 2026-05-26 | **REVERSED** by codex spec review: reintroduce Track A signed JWT (`firebase/php-jwt` + custom JWKS endpoint + `App\Auth\Jwt\TrackAIssuer`) | PRD §06:124 + §18:37 require Track A for concrete consumers (console grants via the console-proxy, share links, deployment tokens). YAGNI was wrong. |
| 2026-05-26 | Per-job container network default `--network=none`; explicit allow-list per kind | PRD §18 alignment |
| 2026-05-26 | Console-script containers carry no Proxmox creds; they speak to `ProviderConsoleProxy` over unix socket with Track A JWT | PRD §18:37 — "no provider credentials in script workers" |
| 2026-05-26 | `broadcast_event_log` Postgres table + `ShouldBroadcast` / `ShouldDispatchAfterCommit` (NOT Redis Streams) | Redis stream IDs aren't arbitrary ULIDs; persist-before-broadcast required |
| 2026-05-26 | Plugin discovery via `PluginRegistry` gated by `enabled` lifecycle state — NOT Laravel package auto-discovery | Auto-discovery boots installed plugins before the lifecycle gate |
| 2026-05-26 | `AuditEvent` schema: `actor_tenant` + `resource_tenant` + `target_tenant_set`, not single `tenant_id` | PRD §19:203 |
| 2026-05-26 | All authorisation decisions go through `App\Domain\Tenancy\AccessResolver`; raw `$user->hasRole()` outside that class is a Larastan failure | Single source of truth for the three-predicate composition |
| 2026-05-26 | Per-job ephemeral Podman/Docker containers (drop nsjail) | Simpler isolation story; Ansible runs inside containers |
| 2026-05-26 | Composer + ServiceProvider + typed hookspec event bus for plugins | Laravel-idiomatic; preserves PRD §13 ~80 hookspec contract |
| 2026-05-26 | Custom append-only AuditEvent + hash chain (load-bearing); owen-it subordinate; drop spatie/laravel-activitylog | Server-owned provenance can't be delegated to a package |
| 2026-05-26 | Pest 4 + Pint + larastan/larastan (PHPStan 2 max) + Rector + Dusk | Ecosystem-standard quality stack for Laravel 13 |
| 2026-05-26 | Guzzle-direct against Proxmox REST API; reject community PHP Proxmox packages | Existing packages too thin / unmaintained |
| 2026-05-27 | **REVISED**: codegen-from-schema PHP client (build-time generator reads Proxmox's `pve-doc-generator` JSON Schema dump and emits typed PSR + readonly DTOs) + Guzzle transport + hand-rolled discipline layer. Considered `libpve-apiclient-perl` sidecar daemon (Option A from the design discussion) and `jefersonflus/proxmox-php-sdk` community package (Option C); rejected: Perl-sidecar adds a runtime language dependency, the community package is bus-factor-1 (3 stars, 14 packagist downloads, uses `php-curl-class` not Guzzle). | Codegen gives us Proxmox's authoritative API surface (same source as `libpve-apiclient-perl`) without the Perl runtime, with type safety by construction, and matches our HTTP-client-family choice (Guzzle). Generator engineering cost is ~2-3 weeks; pays for itself by tracking Proxmox API versions automatically on regeneration. |
