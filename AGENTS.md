# RackLab — Agent Orientation

This file is the load-bearing orientation document for AI agents (Claude Code, Codex, Aider, Cursor, Copilot, etc.) working in this repo. Read it first, before touching any code or documentation. Cross-referenced from `AGENTS.md`, which must remain byte-identical to this file. **The stack has changed**: RackLab was previously designed on Django + DRF + React + pluggy; it is now PHP / Laravel 13 + Octane + Livewire 4 + Filament 5. Every implementation reference in this file reflects the new stack.

## What RackLab is

A self-service educational lab platform replacing RIT's RLES. Students and instructors deploy VMs from an instructor-published catalog; admins run the platform. The control plane sits on top of Proxmox VE. Public repo at `github.com/cyberbalsa/racklab`. Apache-2.0.

The product must scale from a tiny 1–2 user install up to thousands of users by separating web, worker, database, event bus, artifact storage, and untrusted script execution onto separate processes. Baseline profile uses Podman Quadlets on a single host; Scale profile uses Nomad with the Podman driver for multi-host scheduling.

The stack was redesigned in May 2026 from Django 5.2 LTS + DRF + React islands to PHP 8.3+ / Laravel 13 + FrankenPHP + Octane + Livewire 4 + Filament 5. The canonical spec for *how* RackLab is built is `docs/superpowers/specs/2026-05-26-laravel-redesign.md`. The PRD (`docs/prd/`) remains the source of truth for *what* RackLab does.

## Where to read first

**Always start with these, in this order, when working on any feature:**

1. **`docs/superpowers/specs/2026-05-26-laravel-redesign.md`** — the architectural spec (source of truth for HOW RackLab is built). Stack table with version pins, process topology, repo layout, multi-tenancy/RBAC, plugin model, script execution, real-time, quality/CI.
2. **`docs/prd/`** — the long-term product specification (source of truth for WHAT RackLab does). 23 numbered sections plus two plugin PRDs:
   - `01-executive-summary.md` — what RackLab is at one screen
   - `02-goals-non-goals.md`
   - `03-users-personas.md`
   - `04-full-target-requirements.md`
   - `05-architecture.md` — *stack-specific sections are superseded by the redesign spec*
   - `06-auth-rbac-sharing-tokens.md` — auth + tokens (two-track: signed JWT + opaque PAT)
   - `07-api-openapi-sse.md` — API surface + Last-Event-ID replay semantics
   - `08-catalog-stacks-deployments.md` — catalog items + stack templates + deployment lifecycle
   - `09-networking.md` — provider networks + `NetworkOffering.reachability`
   - `10-scripting-automation-sandboxing.md` — per-job ephemeral containers; nsjail dropped
   - `11-quotas-scheduling-placement.md` — OpenStack-triangle quota model, placement
   - `12-proxmox-provider.md`
   - `13-plugin-system.md` — plugin lifecycle + ~80 hookspec catalog
   - `14-audit-logging-observability.md` — audit schema, hash chain, `tenant.cross_access` variants
   - `15-ui-ux.md` — *stack-specific sections superseded by the redesign spec (Livewire 4 / Filament 5 / Tailwind)*
   - `16-container-operations.md`
   - `17-engineering-quality-typing-ci.md` — TDD discipline, no-lint-overrides rule, CI matrix
   - `18-security.md` — multi-tenancy security, upload security, server-owned access provenance
   - `19-data-model.md` — Tenant + multi-tenancy at the top; denormalized `tenant_id`; `RoleBinding.scope_type`; `AuditEvent` three-tenant schema
   - `20-open-questions-risks.md`
   - `21-sources.md`
   - `22-docs-plugin.md` — TipTap-vanilla in Livewire 4 + Filament RichEditor
   - `23-ssh-plugin.md` — xterm.js + noVNC vanilla; cloud-init host-key phone-home
3. **`docs/roadmap/`** — 22 milestone slices M0 → M13d with explicit acceptance criteria.
   - `README.md` — milestone table + Mermaid dependency graph
   - Each milestone follows: Goal, In scope, Dependencies, Deliverables, Acceptance criteria, Test layers, Risks/open questions, Out of scope.
4. **`docs/architecture/diagrams.md`** — Mermaid UML for system component overview, deployment lifecycle, console flow, etc.
5. **`docs/superpowers/specs/2026-05-24-podman-orchestration.md`** — Baseline (Quadlets) + Scale (Nomad + Podman driver) profiles. Still applies; the Laravel redesign extends it with the per-job ephemeral container model.
6. **`docs/superpowers/specs/2026-05-24-proxmox-client-discipline.md`** — typed Proxmox client + task-polling discipline + multi-issuer TLS trust. The strategy is codegen-from-Proxmox-schema + Guzzle transport + hand-rolled discipline layer; same authoritative source as Proxmox's official `libpve-apiclient-perl`.
7. **`PROGRESS.md`** — what's shipped vs what's next. Updated at the end of every session that lands code or substantive docs.

## Repo layout

Standard Laravel layout with RackLab-specific modules under `app/` and first-party plugins in `packages/`. (From spec §4.)

```text
.
├── AGENTS.md, CLAUDE.md             — agent orientation (this file; two copies, identical)
├── PROGRESS.md                      — shipping state + recommended next slice
├── CONTRIBUTING.md, LICENSE
├── composer.json, composer.lock     — PHP deps
├── package.json, vite.config.ts     — Tailwind v4, daisyUI, Livewire bundle, vanilla JS islands
├── pint.json                        — Pint formatter config
├── phpstan.neon                     — Larastan max level, no-overrides discipline
├── rector.php                       — automated refactor rules
├── pest.xml                         — Pest 4 config (tiny/contract/integration/browser)
├── lefthook.yml                     — pre-commit hooks (or captainhook)
│
├── docs/                            — PRD §01–§23 + roadmap + superpowers specs
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
│   │   └── PollProxmoxTask          — task-polling discipline from proxmox-client-discipline spec
│   ├── Events/                      — Broadcastable events (ShouldBroadcast)
│   │   └── Hookspecs/               — ~80 typed hookspec event classes (Pre/Post/Resolver/Validator)
│   ├── Providers/
│   │   ├── AppServiceProvider
│   │   ├── PluginServiceProvider    — discovers + boots installed (enabled) plugins
│   │   ├── HookspecServiceProvider  — registers hookspec catalog + dispatcher
│   │   └── ProxmoxServiceProvider   — codegen-from-schema Proxmox client w/ Guzzle transport + multi-issuer TLS trust
│   ├── Plugins/                     — Plugin runtime: HookDispatcher, PluginRegistry, manifest loader
│   └── Providers/Proxmox/           — Proxmox VE REST client (replaces proxmoxer)
│       └── Generated/               — codegen output: readonly DTOs + typed namespace clients (from pve-doc-generator schema)
│
├── packages/                        — first-party plugins developed in-monorepo
│   ├── racklab/plugin-hello/        — reference plugin
│   ├── racklab/storage-proxmox-shared/
│   ├── racklab/docs-plugin/         — PRD §22 (TipTap-vanilla + Filament RichEditor)
│   └── racklab/ssh-plugin/          — PRD §23 (xterm.js + noVNC vanilla, cloud-init)
│
├── resources/
│   ├── views/                       — Blade templates (Livewire components + layouts)
│   ├── css/
│   │   ├── app.css                  — Public Tailwind v4 entry + `@plugin "daisyui"`
│   │   └── filament.css             — Filament 5 vendor CSS entry (separate bundle)
│   ├── js/
│   │   ├── islands/                 — Vanilla JS islands (xterm-console, novnc-viewer,
│   │   │                              chart-board, filepond-uploader, tiptap-editor)
│   │   └── bootstrap.ts             — Echo + Pusher protocol client init, CSRF, Livewire 4 bootstrap
│   └── lang/                        — Laravel built-in i18n (replaces LinguiJS)
│
├── routes/                          — web.php, api.php, channels.php, console.php
├── database/
│   ├── migrations/                  — core schema migrations
│   ├── factories/                   — Pest factories
│   └── seeders/                     — RBAC defaults seeder
│
└── tests/
    ├── Tiny/                        — pure unit, no Laravel app boot
    ├── Contract/                    — module-boundary tests with in-memory fakes
    ├── Integration/                 — testcontainers Postgres + Redis + Podman socket
    ├── Browser/                     — Dusk E2E for named user journeys
    ├── Snapshots/                   — permission-snapshot + audit-emission gates
    └── Larastan/Rules/              — custom static-analysis rules
```

`app/Domain/` is the architectural boundary — pure PHP services with no Eloquent or HTTP imports. Tiny tests run against this layer. Vanilla JS islands under `resources/js/islands/` are mounted by Livewire components via `wire:ignore` + `@push('scripts')` — no React.

## Stack at a glance

From spec §2. **If the spec table changes, update this table with it.**

| Slot | Pick | Version |
| --- | --- | --- |
| Language | PHP | 8.3+ (8.4 also supported) |
| Framework | Laravel | 13.x (v13.11.2 current) |
| Application server | FrankenPHP (Caddy + embedded PHP, MIT) + Laravel Octane (v2.17.4) worker mode | latest |
| Interactivity | Livewire 4 + bundled Alpine.js | v4.3.0 |
| CSS — public UI | Tailwind v4.1+ + daisyUI 5 | daisyui v5.5.x |
| CSS — admin UI | Tailwind v4.1+ + Filament 5 (MIT) vendor styles | Filament v5.6.5 |
| Vite entries | Separate `app.css` (Tailwind + daisyUI, public) and `filament.css` (Filament vendor, admin) | — |
| Multi-tenancy scaffolding | spatie/laravel-multitenancy + Filament 5 tenancy (`isPersistent: true`) | spatie v4.1.3 |
| Multi-tenancy security | Custom — `tenant_id` columns, global scopes, `#[Untenanted]` Larastan rule, cross-tenant audit, queue context, channel auth | RackLab core |
| Real-time | Laravel Reverb (MIT, WebSockets, Pusher protocol) + Echo client + Livewire 4 broadcasting | ^1.10.2 |
| Real-time replay | Custom `GET /api/v1/replay?channel=…&since=…` backed by Postgres `broadcast_event_log` | RackLab core |
| Auth — session/cookie | Sanctum | v4.3.2 |
| Auth — Track B opaque PAT (PRD §06) | Sanctum opaque PATs (scoped via abilities) | v4.3.2 |
| Auth — Track A signed JWT | `firebase/php-jwt` (RS256) + custom `TrackAIssuer` + `JwksController` | firebase/php-jwt ^7.0 |
| Auth — login / 2FA / passkey | Fortify | v1.37.2 |
| Auth — OAuth providers | Socialite | v5.27.0 |
| Auth — OIDC | Kovah/laravel-socialite-oidc | ^0.8.0 |
| Auth — SAML | socialiteproviders/saml2 | v4.8.0 |
| Queue + jobs | Horizon (Redis; requires `pcntl` + `posix`) | v5.47 |
| Audit | Custom append-only `AuditEvent` + hash chain + owen-it/laravel-auditing (subordinate model-change feed) | owen-it v14 |
| OpenAPI | knuckleswtf/scribe | v5.10 |
| File uploads | spatie/livewire-filepond + custom chunk/retry/checksum design | spatie v1.7.1 |
| Storage backends | Flysystem v3.34 + plugin family (s3, gcs, azure, proxmox-shared) | — |
| Observability | Pulse v1.7.3 + Telescope v5.20 (dev) + sentry/sentry-laravel v4.25.1 + spatie/laravel-health v1.39.3 | — |
| Other Spatie | laravel-permission v7.4.1, laravel-settings v3.9.0, laravel-backup v10.2.1, laravel-medialibrary v11.22.1. **Dropped**: `spatie/laravel-activitylog` (overlaps custom AuditEvent + latest v5 requires PHP 8.4) | — |
| Heavy JS islands | `@xterm/xterm@6.0.0`, `@novnc/novnc@1.7.0`, `chart.js@4.5.1`, `filepond@4.32.12`, `@tiptap/core@3.23.6` | — |
| Quality tooling | Pest 4 (v4.7.0) + Pint v1.29.1 + larastan/larastan v3.9.6 (PHPStan 2 max) + rector/rector v2.4.5 + Dusk v8.6 | — |
| Proxmox client | Codegen-from-`pve-doc-generator`-schema typed PHP client + Guzzle 7.10 transport + hand-written discipline layer | — |
| Script execution | Per-job ephemeral Podman/Docker containers; nsjail dropped | — |
| Plugin authoring | Composer packages + ServiceProvider + typed hookspec event bus over Laravel Events | RackLab core |

## Multi-tenancy primer (load-bearing)

Resource hierarchy: **Tenant → Project → Deployment (deployed Stack) → DeploymentResource.** A single VM is represented as a one-component Stack in the Project's Default Stack, not as a separate standalone deployment type. Course is orthogonal — it's a membership/access-control concept, not a containment level.

**Soft isolation, RBAC-enforced.** One Postgres, one migration graph, one backup. Tenant context resolves via a chain: `IdentifyTenant` middleware sets the active tenant (from URL slug, Sanctum token, or Filament panel), `spatie/laravel-multitenancy` sets `currentTenant()`, `SetTenantContextForOctane` resets it on response (Octane state-leak hazard — mandatory `terminate()` call). Horizon jobs carry explicit `tenant_id` on every payload envelope via `TenantAwareJob` trait + `BindTenantContext` job middleware; audit events read the envelope, never `currentTenant()` at emit time.

**Three-predicate access composition (spec §5):** all three must pass.

```php
// app/Domain/Tenancy/AccessResolver.php
$allowed = $this->bindingScopeCoversTenant($actor, $resource->tenant_id, $context)
    && $this->visibilityIncludesActor($resource, $context->activeTenantId)
    && $this->roleGrantsPermission($actor, $perm, $resource, $context);
```

`RoleBinding.scope_type` is `tenant_local` / `multi_tenant` (with `tenant_set`) / `global`. `sharing_scope` on each resource is `tenant_local` / `shared_with_tenants=[...]` / `global`. `roleGrantsPermission` defers to spatie/laravel-permission for the role→permission lookup only — never for the tenant-policy decision, which is always `AccessResolver`'s call.

**`AccessResolver` is the only authorisation gatekeeper; raw `$user->hasRole()` outside that class is a Larastan failure** (`NoSpatieBypassRule`, §8). Bare `withoutGlobalScopes()` / `withoutGlobalScope(TenantScope::class)` outside `CrossTenantFetch::resolveForFetch()` is also forbidden (`NoBareScopeBypassRule`).

**`audit_events` schema** carries `actor_tenant` + `resource_tenant` + `target_tenant_set` (JSONB), not a single `tenant_id`. Indexes on each column + a GIN index on `target_tenant_set` serve the bidirectional surfacing query: `actor_tenant = :t OR resource_tenant = :t OR :t = ANY(target_tenant_set)`. Every cross-tenant access fires a `tenant.cross_access` audit event (access variant on read; issuance variant on binding/token/share-link creation). `prev_hash` + `hash` sha256 tamper-evident hash chain; `VerifyAuditChain` command exits non-zero on any mismatch.

**Denormalized `tenant_id`** on hot tables (`jobs`, `artifacts`, `deployments`, `reservations`) — immutable at insert, indexed first in composite indexes. `AuditEvent` has `#[Untenanted(reason: 'three-tenant schema')]` and is excluded from the untenanted CI gate.

## Plugin system primer

**Plugin = Composer package + ServiceProvider + (optional) Livewire components + (optional) Filament resources + (optional) hookspec listeners.** Plugins run **in-process** with RackLab — trusted code that extends behaviour. This is distinct from per-job containers, which run untrusted user/Ansible scripts in isolation.

**Discovery is NOT Laravel's standard package auto-discovery.** That would boot every installed package's `ServiceProvider` on app start, defeating the `racklab plugin enable` lifecycle gate. Instead: plugins declare `"extra.racklab.plugin": true` in `composer.json` and `"dont-discover": "*"` to block automatic boot. The custom `App\Plugins\PluginRegistry` (booted from `PluginServiceProvider`) reads `PluginInstallation` rows from the DB; only `enabled` plugins have their `ServiceProvider` instantiated. `racklab plugin enable <slug>` is the gate; already-running Octane workers require a graceful restart after enable.

**Plugin lifecycle state machine:**

```text
installed ──migrate──> migrated ──enable──> enabled ──disable──> disabled
                           │                                       │
                           └────────────rollback──────────────────┘
                                                                   │
                                                                   └──uninstall──> (removed)
```

Artisan commands: `racklab plugin install|migrate|enable|disable|rollback|uninstall <slug>`. `PluginInstallation` and `PluginMigrationRecord` Eloquent models track state.

**Hookspec event bus (~80 typed events from PRD §13).** Each hookspec is a PHP `readonly` class under `app/Events/Hookspecs/<Domain>/<Verb>Event.php`. Plugins subscribe via `App\Plugins\HookDispatcher` (not raw `Event::listen()`) or `#[ListensTo(...)]`. Four listener styles with explicit dispatch semantics: Notification (async/Horizon, no mutation), Filter (sync, may abort with rollback), Contributor (sync, each contributes 0..N entries), Resolver (sync, first non-null wins). Raw `Event::dispatch()` / `Event::until()` against hookspec event classes is forbidden outside `app/Plugins/HookDispatcher.php` — caught by `NoBareEventDispatchOnHookspecsRule`.

**Storage backend is a plugin family.** Core ships `local-fs`. S3 / GCS / Azure / MinIO / Proxmox-shared are separate Composer packages implementing `RackLab\Storage\Contracts\ArtifactBackend` (wraps Flysystem 3.34 + tenant-prefixed paths, chunk-upload coordination, server-side checksum). First-party plugins in `packages/racklab/*` ship in-monorepo for synchronized release cadence (path-repositories during dev).

## Engineering discipline (load-bearing)

**TDD per PRD §17** is non-negotiable, particularly because most implementation is AI-assisted: tests are the durable contract between AI-generated code and human-defined behavior.

- **Write the failing test first.** Every new behavior is preceded by a failing test that captures the requirement.
- **Belt and suspenders.** Tiny + Contract + Integration + Browser. Overlap is the point.
- **Coverage gates per layer.** 90% Tiny, 80% Contract, 70% Integration, named Dusk flows for every user journey.
- **Mutation testing** (`pest --mutate`) on `AccessResolver`, `CrossTenantFetch`, quota math, Job state machine, Proxmox task-poller, audit hash-chain head, plugin lifecycle, Track A JWT issuer/verifier — nightly, not per-PR.

**No-overrides linter discipline.** No `@phpstan-ignore`, no `@psalm-suppress`, no `// @phpcs:ignore`, no `// @phpstan-ignore-next-line` in production code (`app/`, `packages/racklab/*/src/`). Two narrow audited exceptions: test code may use `@phpstan-ignore` for runtime-only Eloquent attributes; `database/migrations/` auto-generated artifacts are excluded. If the linter is wrong, fix the underlying code or the Larastan rule — not the source.

**Custom Larastan rules** (`tests/Larastan/Rules/`):

1. `UntenantedRule` — fails if `extends Model` and no `tenant_id` column and no `#[Untenanted]` attribute.
2. `NoLintOverridesRule` — fails on `@phpstan-ignore*` / `@psalm-suppress` in `app/` or `packages/racklab/*/src/`.
3. `HookspecEventTypedRule` — fails if hookspec event classes are not `readonly` or lack typed properties.
4. `NoBareScopeBypassRule` — fails on `withoutGlobalScopes()` outside `CrossTenantFetch.php`.
5. `NoSpatieBypassRule` — fails on `$user->hasRole(…)` / `$user->can(…)` outside `AccessResolver`.
6. `NoBareEventDispatchOnHookspecsRule` — fails on direct `Event::dispatch()` against hookspec classes outside `HookDispatcher.php`.

**Snapshot CI gates:** `tests/Snapshots/RolePermissions.test.php` refuses PRs that change a role's permission set without updating `tests/Snapshots/roles.json`. `tests/Snapshots/AuditEvents.test.php` refuses PRs documenting a new audit event without a code path emitting it. The `UntenantedRule` gate refuses models without a `tenant_id` column unless decorated `#[Untenanted]`.

**Codex review pattern.** For substantive design specs and PRD edits, a codex review fires before commit. Also fires on PRs touching `docs/prd/`, `docs/superpowers/specs/`, `app/Domain/`, `app/Plugins/`, or `app/Auth/`. Pattern:

```bash
tmpfile=$(mktemp /tmp/codex-review.XXXXXX.md)
codex exec --dangerously-bypass-approvals-and-sandbox --skip-git-repo-check \
  "Review <target>. Goal: <goal>. Constraints: <list>. Findings: correctness, security, edge cases." \
  > "$tmpfile" 2>&1 &
# read tmpfile after completion, fold P0/P1 findings, then commit
```

For PRD edits, the established pattern is "propose wording before applying" — show the actual proposed text in a code block, get directional approval, then `Edit`.

## Commit conventions

**Conventional Commits.** `feat:` / `fix:` / `chore:` / `refactor:` / `docs:` / `test:` / `perf:` / `build:` / `ci:` / `style:`. Imperative mood, lower-case subject after the prefix, no trailing period. Optional scope: `feat(tenancy): add AccessResolver`. Body explains *why* when non-obvious.

**Signed commits mandatory** via the local SSH signing config (Bitwarden agent on the development laptop). **Never use `--no-verify`, `--no-gpg-sign`, or `-c commit.gpgsign=false`.** If a pre-commit hook fails, fix the underlying issue and create a NEW commit (never amend after a hook failure — the commit didn't happen).

**Small logical chunks.** Commit at natural breakpoints — feature complete, tests passing, before a risky refactor. Don't bundle unrelated changes.

**Never force-push or `reset --hard` shared branches** without explicit approval.

## What NOT to do

- **Don't fabricate APIs, version numbers, or config keys.** Look them up — official docs > installed source > tests. If unsure, say "I don't know, let me check."
- **Don't claim "done" without verification.** Run Pint, Larastan, and Pest. For UI changes, exercise the feature in a browser. Partial success is fine; silent partial success is not.
- **Don't introduce scope creep.** Do what was asked, nothing more. No surprise refactors, no speculative abstractions, no "while I was in there" cleanups.
- **Don't bypass the audit / permission / quota / tenant checks** in models or views — those are load-bearing and the CI gates will catch you.
- **Don't add `@phpstan-ignore` or equivalent** — fix the type or the Larastan rule instead.
- **Don't write documentation files** unless explicitly requested.
- **Don't sleep / poll** when waiting for background work — the harness will notify you on completion.
- **Don't call `$user->hasRole()` or `$user->can()` outside `AccessResolver`** — it's a Larastan failure and a security bug.
- **Don't call `withoutGlobalScopes()` outside `CrossTenantFetch::resolveForFetch()`** — same.

## Operational notes

- **Composer** is the canonical PHP package manager. `composer install` from lockfile; `composer require <pkg>` to add deps. Run tools as `vendor/bin/<tool>`.
- **Node + npm** for the Vite-compiled frontend assets (Tailwind v4, daisyUI, Livewire bundle, vanilla JS islands).
- **Pre-commit hook** (`lefthook` or `captainhook`) runs `pint --test`, `larastan --no-progress`, `rector --dry-run`, and the Tiny Pest layer. Run before committing.
- **Tests:** `vendor/bin/pest` runs all layers. `pest --testsuite=tiny` for the fast loop. `pest --testsuite=integration` for testcontainers-backed integration. `pest --testsuite=browser` for Dusk.
- **Dev server:** `php artisan octane:start --server=frankenphp` (after `composer install` + `php artisan migrate`).
- **Settings:** Laravel's `config/*` + `.env`, with dev/test/prod profiles.
- **PHP 8.3+** is required. Pest 4 and Livewire 4 both require ≥ 8.3.

## Asking the user

- Be decisive — propose a recommendation with the main tradeoff in 2–3 sentences for exploratory questions; don't ask for direction when the path is clear.
- Use `AskUserQuestion` only when there's a real decision point with multiple valid answers. Make the first option the recommendation when one exists.
- For PRD edits, show proposed wording in a markdown code block before applying.

## When in doubt

Read the Laravel redesign spec (`docs/superpowers/specs/2026-05-26-laravel-redesign.md`) for HOW. Read `docs/prd/` for WHAT. Read `docs/roadmap/` for WHEN. This file is the index — start here, follow the links.
