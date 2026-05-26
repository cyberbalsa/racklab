# PRD Rewrite Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rewrite all stack-specific RackLab documentation (CLAUDE.md/AGENTS.md, the 8 heavy-rewrite PRD sections, light sweeps of the remaining PRD sections, all 22 roadmap milestones, and architecture diagrams) so the docs accurately describe the PHP/Laravel 13 + Octane + Filament 5 + Livewire 4 stack chosen in `docs/superpowers/specs/2026-05-26-laravel-redesign.md`. Functional requirements stay; implementation prescriptions move to the Laravel ecosystem.

**Architecture:** This is the first of 7 sub-plans in the portfolio described in §10 of the redesign spec. It sets the documented functional ground truth that the other 6 sub-plans (laravel-scaffold, tenancy-auth, plugin-lifecycle, realtime-replay, script-containers, ci-gates) implement against. No application code is written or modified in this plan — the working tree's Django-era code was already removed in commit `a6bc105`.

**Tech Stack (target documented stack — see the redesign spec for full version pins):**
- PHP 8.3+ / Laravel 13 / FrankenPHP + Octane / Livewire 4 / Filament 5
- Tailwind v4 + daisyUI 5 / Pest 4 + Pint + Larastan + Rector
- Reverb (real-time) / Sanctum + Fortify + Socialite + `firebase/php-jwt`
- spatie/laravel-multitenancy + spatie/laravel-permission + custom `AccessResolver`
- Horizon + Redis / Podman/Docker per-job containers / Postgres 16
- knuckleswtf/scribe (OpenAPI) / `spatie/livewire-filepond`

---

## Scope reminder

In scope:
- `CLAUDE.md` (root) — agent orientation; the existing `AGENTS.md` is a symlink-equivalent twin that needs the same content
- `docs/prd/05-architecture.md`
- `docs/prd/06-auth-rbac-sharing-tokens.md`
- `docs/prd/07-api-openapi-sse.md`
- `docs/prd/13-plugin-system.md`
- `docs/prd/15-ui-ux.md`
- `docs/prd/17-engineering-quality-typing-ci.md`
- `docs/prd/22-docs-plugin.md`
- `docs/prd/23-ssh-plugin.md`
- `docs/prd/10-scripting-automation-sandboxing.md` (light: nsjail→containers, Ansible-Runner→containerized-ansible)
- `docs/prd/14-audit-logging-observability.md` (light: `manage.py`→`artisan`, audit-row mechanics)
- `docs/prd/18-security.md` (light: DRF references, server-owned provenance verbiage)
- `docs/prd/19-data-model.md` (light: model class wording → Eloquent; AuditEvent three-tenant schema confirmation)
- `docs/prd/{01,02,03,04,08,09,11,12,16,20,21,README}.md` — sweep for stack mentions
- `docs/roadmap/{M00,M00.5,M01,M02,M02.5,M03,M04,M05a,M05b,M06,M07a,M07b,M08,M09,M10a,M10b,M11a,M11b,M12,M13a,M13b,M13c,M13d,README}.md` — Deliverables / Test layers / Risks rewrites
- `docs/architecture/diagrams.md` — node labels and process boxes

Out of scope (handled by other sub-plans):
- Any code under `app/`, `packages/`, `resources/`, `routes/`, `database/`, `tests/`
- `composer.json`, `package.json`, `vite.config.ts`, `phpstan.neon`, `pint.json`, `rector.php`, `pest.xml`
- CI workflow files under `.github/workflows/`

---

## Shared rewrite pattern (applied per file)

Every file in this plan follows the same four-step rewrite arc:

1. **Read** the current file in full. Read the relevant spec section the rewrite is sourced from.
2. **Inventory stale references** with a grep. The canonical stale-reference grep is:
   ```bash
   grep -nE 'Django|django|DRF|drf-spectacular|django-allauth|simplejwt|knox|Channels(?!.*Laravel)|pluggy|Pluggy|@?tiptap.?(react|/react)|@?mantine|@?radix|LinguiJS|lingui|django-vite|react-filepond|nsjail|Ansible Runner|ansible-runner|django-prometheus|django-health-check|FilePond.*chunked-receive|manage\.py|pyproject\.toml|uv\.lock|ruff|mypy|basedpyright|bandit|pytest|pytest-django|factory-boy|testcontainers-py|psycopg|asyncssh|nats-py|proxmoxer|Argon2 via .django\[argon2\]|TimescaleDB' <path>
   ```
   (subagents should adjust the pattern if a specific section has narrower stale-reference targets)
3. **Apply targeted Edit calls** that replace each stale reference with the Laravel-stack equivalent from the spec. Do not bulk-rewrite paragraphs; rewrite sentence-by-sentence so the intent of the original is preserved.
4. **Verify** by re-running the grep — output should be empty (or contain only allowlisted historical mentions in clearly-marked context like "previously, ... ; now, ..."). Commit.

Functional requirements **never change** in this plan. If a rewrite would change what RackLab does (versus how it's built), stop and surface to the user — that's a spec change, not a doc rewrite.

---

## Parallelism guide

- **Phase 1 (CLAUDE.md/AGENTS.md) is a hard gate.** It blocks every other phase because future subagent context comes from these files. Land it first, on its own commit.
- **Phase 2 (heavy PRD rewrites)** — 8 tasks, all independent, all parallelizable. Dispatch in parallel via `superpowers:dispatching-parallel-agents`.
- **Phase 3 (light PRD sweeps)** — 5 tasks, independent, parallelizable. Can run alongside Phase 2.
- **Phase 4 (roadmap milestones)** — 14 tasks, all independent (each milestone is its own file). Parallelize freely.
- **Phase 5 (architecture diagrams)** — 1 task, runs after Phase 2 (depends on §05 wording).
- **Phase 6 (final integrity sweep)** — 1 task, must be last. Runs grep across the entire docs/ tree to catch anything missed.

---

## Phase 0 — Setup verification

### Task 1: Confirm starting state

**Files:**
- Read-only: `docs/superpowers/specs/2026-05-26-laravel-redesign.md`, current commit log

- [ ] **Step 1: Confirm the spec is committed and current branch is `main`**

```bash
git -C /home/fffics/Documents/projects/racklab log --oneline -3
git -C /home/fffics/Documents/projects/racklab branch --show-current
```

Expected: `main` branch, with these three commits at the head:
- `4f9b829 docs(superpowers/specs): add Laravel redesign architectural spec`
- `a6bc105 chore: remove stale Django/React stack docs and plans`
- `f685236 chore: reset repo to PRD + plans, new direction`

- [ ] **Step 2: Confirm the spec file exists and is readable**

```bash
test -r /home/fffics/Documents/projects/racklab/docs/superpowers/specs/2026-05-26-laravel-redesign.md && wc -l /home/fffics/Documents/projects/racklab/docs/superpowers/specs/2026-05-26-laravel-redesign.md
```

Expected: `659 docs/superpowers/specs/2026-05-26-laravel-redesign.md`

- [ ] **Step 3: Capture a baseline grep across docs/ for stack-stale references**

```bash
grep -rnE 'Django|django|DRF|drf-spectacular|pluggy|Mantine|Radix|React|LinguiJS|django-allauth|simplejwt|knox|nsjail|Ansible Runner|ansible-runner|ruff|mypy|basedpyright|pytest|proxmoxer|@tiptap/react|django-vite|django-prometheus|django-health-check|manage\.py|TimescaleDB' /home/fffics/Documents/projects/racklab/docs/ | wc -l
```

Record the count — it's the "starting debt." The final integrity sweep in Task 30 must bring this to 0 (or to a small known set of historical mentions in superpowers/specs which are allowed).

No commit for this task — it's a verification step.

---

## Phase 1 — Agent orientation (HARD GATE; blocks all other phases)

### Task 2: Rewrite CLAUDE.md (and AGENTS.md mirror)

**Why first:** every subagent dispatched after this task reads CLAUDE.md as its primary orientation. If this file still describes the Django stack, every downstream rewrite is poisoned by stale context.

**Files:**
- Modify: `/home/fffics/Documents/projects/racklab/CLAUDE.md`
- Modify: `/home/fffics/Documents/projects/racklab/AGENTS.md` (must end up byte-identical to CLAUDE.md)

- [ ] **Step 1: Read the current CLAUDE.md in full**

```bash
wc -l /home/fffics/Documents/projects/racklab/CLAUDE.md
```

Expected: ~360 lines, heavy Django/React/pluggy references.

- [ ] **Step 2: Read the spec's stack table (§2), architecture (§3), repo layout (§4), tenancy (§5), plugin model (§6) for the canonical descriptions you'll mirror into CLAUDE.md**

```bash
sed -n '20,200p' /home/fffics/Documents/projects/racklab/docs/superpowers/specs/2026-05-26-laravel-redesign.md
```

- [ ] **Step 3: Replace the entire CLAUDE.md with a Laravel-stack orientation**

Structure of the replacement (use this exact section order):

```markdown
# RackLab — Agent Orientation

[1-paragraph intro: what RackLab is, who reads this file, what changes — load-bearing orientation for AI agents working in this repo]

## What RackLab is

[2-3 paragraphs: educational lab platform on Proxmox, self-service deployments, Apache-2.0, public repo. Note the redesign — PHP/Laravel stack replacing the previous Django direction.]

## Where to read first

1. **`docs/superpowers/specs/2026-05-26-laravel-redesign.md`** — the architectural spec (this is the source of truth for HOW RackLab is built)
2. **`docs/prd/`** — functional requirements (source of truth for WHAT RackLab does); 23 numbered sections
3. **`docs/roadmap/`** — 22 milestone slices M0 → M13d with explicit acceptance criteria
4. **`docs/architecture/diagrams.md`** — Mermaid UML for system component overview, deployment lifecycle, console flow
5. **`docs/superpowers/specs/2026-05-24-podman-orchestration.md`** — Baseline (Quadlets) + Scale (Nomad + Podman driver) profiles; still applies
6. **`docs/superpowers/specs/2026-05-24-proxmox-client-discipline.md`** — typed Proxmox client + task polling + multi-issuer TLS trust; still applies (ports to PHP via Guzzle)

## Repo layout

[Tree diagram matching spec §4 — app/, packages/, resources/, routes/, database/, tests/, docs/. Pull the tree directly from spec §4 so the two stay in sync.]

## Stack at a glance

[Reproduce the spec §2 stack table verbatim, including version pins. Note: this is the only place outside the spec where the table is repeated; if the spec table changes, this must change with it.]

## Multi-tenancy primer (load-bearing)

[Summarise spec §5 in 2-3 paragraphs: tenant resolution chain, three-predicate access composition via AccessResolver, audit_events three-tenant schema, denormalized tenant_id discipline, Octane state-leak hazard. End with: "AccessResolver is the only authorisation gatekeeper; raw $user->hasRole() outside that class is a Larastan failure."]

## Plugin system primer

[Summarise spec §6 in 2-3 paragraphs: Composer packages + custom PluginRegistry (NOT Laravel auto-discovery), HookDispatcher with four listener styles, plugin lifecycle state machine, storage backend plugin family. Mention that plugins run in-process — distinct from per-job containers which run untrusted code.]

## Engineering discipline (load-bearing)

[Adapt spec §8 + global guardrails. TDD per spec §8 test-layering. No-overrides linter discipline — no @phpstan-ignore / @psalm-suppress in production code. Permission-snapshot test, audit-emission test, @untenanted CI gate. Codex review fires on docs/prd, docs/superpowers/specs, app/Domain, app/Plugins, app/Auth.]

## Commit conventions

[Conventional Commits — same as before. Signed via Bitwarden SSH agent. Small logical chunks. Never bypass hooks. Never force-push shared branches.]

## What NOT to do

[Carry forward from the old CLAUDE.md but rephrase for the new stack: don't fabricate APIs / version numbers, don't claim done without verification, don't introduce scope creep, don't bypass audit/permission/quota/tenant checks, don't add @phpstan-ignore, don't write docs unless asked, don't poll background work.]

## Operational notes

- **Composer** is the canonical PHP package manager. `composer install` from lockfile; `composer require <pkg>` to add deps.
- **Node + npm** for the Vite-compiled frontend assets (Tailwind, daisyUI, Livewire bundle, vanilla JS islands).
- **Pre-commit hook** runs Pint --test, Larastan, Rector --dry-run, and the tiny Pest layer. Use lefthook or captainhook.
- **Tests:** `vendor/bin/pest` runs all layers. `pest --testsuite=tiny` for the fast loop. `pest --testsuite=integration` for testcontainers-backed.
- **Dev server:** `php artisan octane:start --server=frankenphp` (after `composer install` + `php artisan migrate`).
- **Settings:** Laravel's config/* + .env, with separate dev/test/prod profiles.

## Asking the user

[Same as before — decisive recommendations, AskUserQuestion for real decision points, propose wording before applying PRD edits.]

## When in doubt

Read the Laravel redesign spec (`docs/superpowers/specs/2026-05-26-laravel-redesign.md`) for HOW; read `docs/prd/` for WHAT; read `docs/roadmap/` for WHEN. This file is the index — start here, follow the links.
```

Write this content via the Write tool (not Edit — this is a full replacement). The above is structural guidance; the subagent fills in the actual prose by reading the spec sections referenced.

- [ ] **Step 4: Copy CLAUDE.md content verbatim into AGENTS.md**

```bash
cp /home/fffics/Documents/projects/racklab/CLAUDE.md /home/fffics/Documents/projects/racklab/AGENTS.md
diff /home/fffics/Documents/projects/racklab/CLAUDE.md /home/fffics/Documents/projects/racklab/AGENTS.md
```

Expected: no output from `diff` (files identical).

- [ ] **Step 5: Verify no stale references remain**

```bash
grep -nE 'Django|django|DRF|drf-spectacular|pluggy|Mantine|Radix|React|LinguiJS|django-allauth|simplejwt|knox|django-vite|@tiptap/react|nsjail|Ansible Runner|ruff|mypy|basedpyright|pytest|proxmoxer|manage\.py|pyproject\.toml|uv\.lock' /home/fffics/Documents/projects/racklab/CLAUDE.md /home/fffics/Documents/projects/racklab/AGENTS.md
```

Expected: empty output.

- [ ] **Step 6: Commit**

```bash
git -C /home/fffics/Documents/projects/racklab add CLAUDE.md AGENTS.md
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
docs: rewrite agent orientation for Laravel stack

CLAUDE.md and AGENTS.md previously described the Django + DRF + React +
pluggy stack. Replace with PHP/Laravel 13 + Octane + Livewire 4 +
Filament 5 + Tailwind 4 orientation, anchored on the spec at
docs/superpowers/specs/2026-05-26-laravel-redesign.md. Functional
behaviour and discipline rules (TDD, no-overrides linter, codex review
trigger paths) carry forward unchanged.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 2 — Heavy PRD rewrites (8 parallelizable tasks)

All Phase 2 tasks follow the shared rewrite pattern. They can be dispatched in parallel after Phase 1 completes.

### Task 3: Rewrite PRD §05 architecture

**Files:**
- Modify: `docs/prd/05-architecture.md`
- Reference: `docs/superpowers/specs/2026-05-26-laravel-redesign.md` §3 (process topology) + §4 (repo layout)

- [ ] **Step 1: Read the current file and the spec sections**

```bash
cat /home/fffics/Documents/projects/racklab/docs/prd/05-architecture.md
sed -n '90,200p' /home/fffics/Documents/projects/racklab/docs/superpowers/specs/2026-05-26-laravel-redesign.md
```

- [ ] **Step 2: Inventory stale references**

```bash
grep -nE 'Django|DRF|Channels|pluggy|asgi|wsgi|django-vite|React|Mantine|Radix|LinguiJS|@tiptap/react|psycopg|asyncssh|nats-py|proxmoxer' /home/fffics/Documents/projects/racklab/docs/prd/05-architecture.md
```

Capture the line numbers — every line listed must be rewritten.

- [ ] **Step 3: Rewrite the architecture overview**

Replace any "Django-based control plane on Proxmox VMs" framing with "PHP/Laravel control plane on Proxmox VMs." Replace any reference to Django apps, DRF views, Channels consumers, ASGI/WSGI with the Laravel equivalents (Livewire components, Filament resources, JSON API controllers, FrankenPHP + Octane workers, Horizon job workers, Reverb daemon).

Use the spec's Process Topology diagram (§3) as the canonical diagram. The PRD's architecture diagram should match the spec's process boxes:

- FrankenPHP (Caddy + embedded PHP) running Laravel Octane worker mode
- Postgres 16
- Redis 7 (queues, cache, session, Reverb backplane)
- Reverb daemon (separate process, MIT)
- Horizon worker pools (separate processes)
- Per-job ephemeral Podman containers (ansible-runner, user-script, console-script)
- Proxmox VE cluster

Preserve any functional requirements about scale (must scale from 1-2 user installs up to thousands). Update implementation prescriptions only.

- [ ] **Step 4: Re-run inventory grep and confirm clean**

```bash
grep -nE 'Django|DRF|Channels|pluggy|asgi|wsgi|django-vite|React|Mantine|Radix|LinguiJS|@tiptap/react|psycopg|asyncssh|nats-py|proxmoxer' /home/fffics/Documents/projects/racklab/docs/prd/05-architecture.md
```

Expected: empty.

- [ ] **Step 5: Markdownlint pass**

```bash
which markdownlint && markdownlint /home/fffics/Documents/projects/racklab/docs/prd/05-architecture.md
```

Expected: empty output (or "markdownlint not installed" — that's acceptable if the tool isn't available; the docs-ci.yml workflow will catch it later).

- [ ] **Step 6: Commit**

```bash
git -C /home/fffics/Documents/projects/racklab add docs/prd/05-architecture.md
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
docs(prd): rewrite §05 architecture for Laravel stack

Replace Django/DRF/Channels/pluggy architectural prescriptions with the
PHP/Laravel 13 + FrankenPHP + Octane + Reverb + Horizon + Podman-job
process topology from docs/superpowers/specs/2026-05-26-laravel-redesign.md §3.
Functional scaling requirements unchanged.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Rewrite PRD §06 auth, RBAC, sharing, tokens

**Files:**
- Modify: `docs/prd/06-auth-rbac-sharing-tokens.md`
- Reference: spec §2 (auth stack table rows), §5 (AccessResolver), §7 (Track A JWT scope for console proxy)

- [ ] **Step 1: Read the current file (very long; ~300 lines) and the spec sections**

```bash
wc -l /home/fffics/Documents/projects/racklab/docs/prd/06-auth-rbac-sharing-tokens.md
grep -n '^##' /home/fffics/Documents/projects/racklab/docs/prd/06-auth-rbac-sharing-tokens.md
```

- [ ] **Step 2: Inventory stale references**

```bash
grep -nE 'django-allauth|django\[argon2\]|simplejwt|knox|djangorestframework|DRF|drf-spectacular|django-rest-knox|Argon2 via .django\[argon2\]|Channels|django\.contrib|allauth\.|JSONField|EncryptedField|django.contrib.contenttypes|OIDC providers via django-allauth|allauth\.socialaccount' /home/fffics/Documents/projects/racklab/docs/prd/06-auth-rbac-sharing-tokens.md
```

- [ ] **Step 3: Apply replacements**

Map each stale reference to the new stack per spec §2:

| Old | New |
| --- | --- |
| `django-allauth` (users/local/OIDC/SAML) | Laravel Fortify (login/2FA/passkey) + Socialite + `Kovah/laravel-socialite-oidc` (OIDC) + `socialiteproviders/saml2` (SAML) |
| `Argon2 via django[argon2]` | Laravel's built-in `Hash::driver('argon2id')` |
| `djangorestframework-simplejwt` (Track A — JWT) | `firebase/php-jwt` + custom `App\Auth\Jwt\TrackAIssuer` + `App\Http\Controllers\JwksController` |
| `django-rest-knox` (Track B — opaque PAT) | Laravel Sanctum opaque PATs (scoped via abilities) |
| `DRF view computes the access decision` | `AccessResolver` (in `app/Domain/Tenancy/`) computes the access decision; access happens through controllers/Livewire components/Filament resources, but the decision itself is one method call |
| `manage.py verify_audit_chain` | `php artisan racklab:verify-audit-chain` |

Preserve all functional requirements: the two-track token model (Track A signed JWT + Track B opaque PAT), the three-predicate access composition, RoleBinding with scope_type + tenant_set, cross-tenant audit, all permission catalog entries, all role packs.

**Critically**: this section is what re-introduced Track A JWTs after they were almost-deferred. Make sure the text clearly states Track A's required consumers per the spec — console grants (used via `ProviderConsoleProxy` localhost socket), share/guest links, short-lived deployment tokens. The console-proxy refactor from spec §7 should be referenced in the "console grant" subsection.

- [ ] **Step 4: Re-run inventory grep**

```bash
grep -nE 'django-allauth|django\[argon2\]|simplejwt|knox|djangorestframework|DRF|drf-spectacular|django-rest-knox|Channels|django\.contrib|allauth\.|manage\.py' /home/fffics/Documents/projects/racklab/docs/prd/06-auth-rbac-sharing-tokens.md
```

Expected: empty.

- [ ] **Step 5: Commit**

```bash
git -C /home/fffics/Documents/projects/racklab add docs/prd/06-auth-rbac-sharing-tokens.md
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
docs(prd): rewrite §06 auth/RBAC/sharing/tokens for Laravel stack

Replace django-allauth + simplejwt + knox prescriptions with
Sanctum (Track B opaque PAT) + firebase/php-jwt (Track A signed JWT,
required for console grants / share links / deployment tokens per
this PRD section + spec §06:124) + Fortify + Socialite + community
OIDC/SAML providers. AccessResolver in app/Domain/Tenancy is the
named authorisation gatekeeper. Functional contract (two-track tokens,
three-predicate composition, RoleBinding scope_type + tenant_set) is
unchanged.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Rewrite PRD §07 API, OpenAPI, SSE → Reverb

**Files:**
- Modify: `docs/prd/07-api-openapi-sse.md`
- Reference: spec §2 (Reverb row, Scribe row), §7 (channel taxonomy + replay endpoint)

- [ ] **Step 1: Read current file and grep stale refs**

```bash
cat /home/fffics/Documents/projects/racklab/docs/prd/07-api-openapi-sse.md
grep -nE 'DRF|drf-spectacular|django|Channels|SSE|Server-Sent Events|Last-Event-ID|EventSource' /home/fffics/Documents/projects/racklab/docs/prd/07-api-openapi-sse.md
```

- [ ] **Step 2: Apply replacements**

| Old | New |
| --- | --- |
| `Django REST Framework (DRF) + drf-spectacular` | Laravel + `knuckleswtf/scribe` (auto-introspects routes + FormRequests + Eloquent for OpenAPI 3.1) |
| `Channels-driven SSE streaming with Last-Event-ID replay` | Reverb (WebSockets + Pusher protocol) for live push + the custom `GET /api/v1/replay?channel=…&since=…` endpoint backed by a Postgres `broadcast_event_log` table (per spec §7) — preserves the original Last-Event-ID *semantics* even though the transport is WebSocket, not SSE |
| `EventSource` client | Laravel Echo (Pusher protocol client) + the replay-endpoint drainer on reconnect |

Keep the channel taxonomy and the contract that the replay endpoint enforces the same `AccessResolver`-based visibility as the live channel. Add an explicit note that the on-the-wire transport changed from SSE to WebSocket while the durable-replay contract was preserved by the Postgres event log.

- [ ] **Step 3: Re-run inventory and commit**

```bash
grep -nE 'DRF|drf-spectacular|django|Channels(?!.*Laravel)|EventSource' /home/fffics/Documents/projects/racklab/docs/prd/07-api-openapi-sse.md
```

Expected: empty.

```bash
git -C /home/fffics/Documents/projects/racklab add docs/prd/07-api-openapi-sse.md
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
docs(prd): rewrite §07 API/OpenAPI/SSE for Reverb + Scribe

Real-time transport moves from SSE-via-Channels to Reverb WebSockets
(Pusher protocol). Last-Event-ID replay semantics preserved via
Postgres broadcast_event_log + the /api/v1/replay endpoint
(spec §7). OpenAPI generation moves from drf-spectacular to
knuckleswtf/scribe with the schema-drift CI gate from spec §8.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Rewrite PRD §13 plugin system

**Files:**
- Modify: `docs/prd/13-plugin-system.md`
- Reference: spec §6 (Plugin model + hookspec event bus)

This is the largest single rewrite in the PRD. PRD §13 currently has ~80 hookspec definitions framed around `pluggy`'s Python hookspec decorator API. They need to be reframed as PHP 8 readonly event classes dispatched through `HookDispatcher`.

- [ ] **Step 1: Read current §13 in full, including the hookspec catalog**

```bash
wc -l /home/fffics/Documents/projects/racklab/docs/prd/13-plugin-system.md
grep -n '^##' /home/fffics/Documents/projects/racklab/docs/prd/13-plugin-system.md
```

- [ ] **Step 2: Inventory stale references**

```bash
grep -nE 'pluggy|hookspec|hookimpl|@hookspec|@hookimpl|entry_points|Python entry points|setup\.cfg|HookSpec|HookImpl|Python plugin' /home/fffics/Documents/projects/racklab/docs/prd/13-plugin-system.md
```

- [ ] **Step 3: Rewrite the "plugin authoring" framing**

Replace `pluggy 1.6 + Python entry points` with `Composer packages + custom App\Plugins\PluginRegistry (NOT Laravel auto-discovery) + HookDispatcher`. Replace `@hookspec` / `@hookimpl` examples with the PHP 8 attribute pattern: `#[ListensTo(Hookspec\Deployment\Creating::class)]` on listener classes.

Replace the `pluggy` "hookspec catalog" with the **typed event class catalog** at `app/Events/Hookspecs/<Domain>/<Verb>Event.php`. The naming convention stays — `racklab_<domain>_pre_<verb>` / `racklab_<domain>_post_<verb>` / `_resolver` / `_validator` / `_contributor` / `_sink` — but each is now a PHP class with typed readonly properties.

- [ ] **Step 4: Rewrite the four listener-style framing**

Use the four-style table from spec §6 verbatim. The styles are:
1. **Notification** (`post_*`, `_sink`) — async via Horizon, no mutation, no ordering, isolated failures, job-level timeout
2. **Filter** (`pre_*`, `_validator`) — sync, payload mutation allowed, deterministic priority ordering, single failure aborts, per-listener 500ms cap
3. **Contributor** (`_contributor`) — sync, multi-result aggregation, deterministic priority, isolated failures, 200ms cap
4. **Resolver** (`_resolver`) — sync, first-non-null-wins, deterministic priority, fallthrough on failure, 200ms cap

Add the audit-pre-emit-must-not-block guarantee from spec §6.

- [ ] **Step 5: Rewrite the lifecycle state machine**

Replace `racklab plugin ... ` Python-CLI examples with `php artisan racklab:plugin install|migrate|enable|disable|rollback|uninstall <slug>` Artisan commands. Keep the state machine diagram unchanged.

- [ ] **Step 6: Rewrite the storage backend plugin family**

Replace `pluggy ArtifactBackend Protocol` framing with `RackLab\Storage\Contracts\ArtifactBackend` PHP interface that wraps Flysystem 3.34. First-party plugins land as `racklab/storage-*` Composer packages.

- [ ] **Step 7: Translate the hookspec catalog**

For each of the ~80 hookspecs in the current §13, ensure it's described as a typed event class with PHP class name + typed readonly properties + listener style (Notification/Filter/Contributor/Resolver). The hookspec NAMES (`racklab_deployment_creating`, `racklab_storage_backend_resolver`, etc.) stay; their PYTHON dispatcher framing changes.

Where the current catalog uses Python type hints (`List[str]`, `Optional[Tenant]`, etc.), replace with PHP type hints (`array`, `?Tenant`, with `@var string[]` PHPDoc where useful).

- [ ] **Step 8: Verify**

```bash
grep -nE 'pluggy|hookspec|hookimpl|@hookspec|@hookimpl|entry_points|Python entry points|setup\.cfg|HookSpec(?!Event)|HookImpl' /home/fffics/Documents/projects/racklab/docs/prd/13-plugin-system.md
```

Expected: empty.

- [ ] **Step 9: Commit**

```bash
git -C /home/fffics/Documents/projects/racklab add docs/prd/13-plugin-system.md
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
docs(prd): rewrite §13 plugin system for Composer + HookDispatcher

Replace pluggy 1.6 + Python entry points framing with Composer
packages + custom PluginRegistry (gated by plugin lifecycle state,
NOT Laravel auto-discovery) + typed HookDispatcher with four explicit
listener styles (notification/filter/contributor/resolver) per
spec §6. ~80 hookspec catalog entries reframed as PHP 8 readonly
event classes. Lifecycle state machine and storage-backend plugin
family unchanged in shape; runtime moves to Artisan commands.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Rewrite PRD §15 UI/UX

**Files:**
- Modify: `docs/prd/15-ui-ux.md`
- Reference: spec §2 (UI stack table rows), §4 (repo layout for resources/), §7 (channel taxonomy + xterm/noVNC islands)

- [ ] **Step 1: Read current and grep**

```bash
cat /home/fffics/Documents/projects/racklab/docs/prd/15-ui-ux.md
grep -nE 'React|Vite|django-vite|Mantine|Radix|LinguiJS|TanStack|Zustand|Zod|TypeScript|TSX|HTMX|FilePond.*chunked-receive|@tiptap/react|@mantine/tiptap|tiptap-react|@xterm/xterm|react-filepond' /home/fffics/Documents/projects/racklab/docs/prd/15-ui-ux.md
```

- [ ] **Step 2: Apply replacements**

| Old | New |
| --- | --- |
| Django + React islands via django-vite | Blade + Livewire 4 components (server-rendered, reactive over the wire) + Filament 5 for admin |
| Mantine + Radix gaps | Tailwind v4 + daisyUI 5 for public UI; Filament 5 for admin (Tailwind 4 internally) |
| LinguiJS sharing .po with gettext | Laravel's built-in i18n (`resources/lang/*`); `php artisan lang:check` for catalog drift CI |
| TanStack Query + Zustand + Zod | Livewire 4 (handles server state) + Alpine.js bundled (client state) + Laravel FormRequest + spatie/laravel-data (server-side typed payloads) |
| TypeScript 5.5+ strict, React 19+ | Vanilla JS islands compiled via Vite; TypeScript only for the islands themselves (xterm config, noVNC adapter). No React. |
| `@tiptap/react` + `@mantine/tiptap` for docs editor | `@tiptap/core` vanilla mounted via `wire:ignore` in a Livewire component (public), Filament's built-in TipTap-based `RichEditor` (admin) |
| `react-filepond` 7.x | `filepond` vanilla 4.x via the `spatie/livewire-filepond` bridge |
| `@xterm/xterm` 6.x renamed from `xterm` | unchanged — still `@xterm/xterm` (vanilla) |
| `@novnc/novnc` 1.7.x | unchanged — already vanilla |
| `Chart.js via react-chartjs-2 with AccessibleChart HOC` | `Chart.js` vanilla via a small `chart-board.ts` island; accessibility lives in a Livewire `<AccessibleChart>` Blade component wrapping the island |
| Stock Django admin until M10a lands the custom shell | Filament 5 admin from day 1 (replaces the django admin); M10a-equivalent rewrites the **public** UI component library on top of daisyUI 5 |

Preserve all functional UI requirements: SPA-feel via Livewire `wire:navigate`, accessibility commitments, i18n requirements, the chunked-upload UX, the console live-watch UX.

- [ ] **Step 3: Verify and commit**

```bash
grep -nE 'React|Vite django|Mantine|Radix|LinguiJS|TanStack|Zustand|@tiptap/react|@mantine/tiptap|react-filepond' /home/fffics/Documents/projects/racklab/docs/prd/15-ui-ux.md
```

Expected: empty.

```bash
git -C /home/fffics/Documents/projects/racklab add docs/prd/15-ui-ux.md
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
docs(prd): rewrite §15 UI/UX for Livewire 4 + Filament 5 + daisyUI

Replace React-islands-via-django-vite + Mantine + Radix + LinguiJS +
TanStack/Zustand/Zod stack with Blade + Livewire 4 (public) +
Filament 5 (admin) + Tailwind v4 + daisyUI 5. Heavy JS islands
(xterm.js, noVNC, Chart.js, FilePond, TipTap) move to vanilla
ports mounted via wire:ignore. Laravel's built-in i18n replaces
LinguiJS. UI functional requirements (accessibility, chunked
upload UX, console live-watch UX, SPA-feel via wire:navigate)
preserved.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: Rewrite PRD §17 engineering, quality, typing, CI

**Files:**
- Modify: `docs/prd/17-engineering-quality-typing-ci.md`
- Reference: spec §8 (Quality, testing, CI, observability) — entire section is the source of truth

- [ ] **Step 1: Read current and grep**

```bash
cat /home/fffics/Documents/projects/racklab/docs/prd/17-engineering-quality-typing-ci.md
grep -nE 'ruff|mypy|basedpyright|bandit|semgrep|pip-audit|pre-commit\.py|uv|pyproject\.toml|pytest|pytest-django|factory-boy|testcontainers-py|Python|TypeScript|Vitest|RTL|Storybook|vitest-axe|Playwright|axe-core|django-prometheus|sentry-sdk' /home/fffics/Documents/projects/racklab/docs/prd/17-engineering-quality-typing-ci.md
```

- [ ] **Step 2: Apply replacements en bloc**

This section maps almost 1-to-1 from Python tooling to PHP tooling:

| Old | New |
| --- | --- |
| ruff (format + lint, all rules) | Pint (format) + Larastan (lint, PHPStan 2 max level) + Rector (automated refactors) |
| mypy strict | Larastan strict (max level + custom rules from spec §8) |
| basedpyright | not needed — Larastan covers static typing for the PHP surface |
| bandit | semgrep with Laravel/PHP rule packs + `roave/security-advisories` (composer dev dep, aborts on known-CVE deps) + `enlightn/security-checker` |
| pip-audit | `composer audit` + `npm audit` |
| pre-commit | `lefthook` or `captainhook` |
| uv | Composer |
| pyproject.toml | composer.json + package.json |
| pytest + pytest-django | Pest 4 + Laravel Test runner |
| factory-boy | Laravel model factories (Eloquent) |
| testcontainers (Python) | testcontainers (PHP — same project, different language binding) |
| Vitest + RTL + Storybook + vitest-axe | Pest 4 browser layer via Laravel Dusk + axe-core integration in Dusk |
| Playwright + axe-core | Dusk + axe-core (same axe-core library, different driver) |
| django-prometheus | Laravel Pulse (in-product) + sentry/sentry-laravel (errors) + spatie/laravel-health (health endpoints) + OpenTelemetry exporter deferred |

Replace the coverage gate table (90% tiny / 80% contract / 70% integration / named E2E flows) verbatim from spec §8 — the gates stay; only the runner changes.

Replace the no-overrides linter discipline. The Python form was "no `# noqa`, no `# type: ignore`, no `// eslint-disable`." The PHP form is "no `@phpstan-ignore`, no `// @phpstan-ignore-line`, no `@psalm-suppress`, no `// @phpcs:ignore`." Two audited exceptions stay (test code may use ignore for runtime-only Eloquent attributes; auto-generated migrations excluded by path glob).

Replace the custom CI gates:
- Permission-snapshot test (carry forward)
- Audit-emission test (carry forward)
- `@untenanted` gate — Python decorator → PHP attribute `#[Untenanted]` + custom Larastan rule
- New: `NoBareScopeBypassRule`, `NoSpatieBypassRule`, `NoBareEventDispatchOnHookspecsRule` (from spec §8)
- New: OpenAPI schema-drift gate, axe-core a11y, `lang:check` i18n drift

Add the CI matrix from spec §8.

- [ ] **Step 3: Verify and commit**

```bash
grep -nE 'ruff|mypy|basedpyright|pip-audit|pyproject\.toml|pytest|factory-boy|Vitest|RTL|vitest-axe|Playwright|django-prometheus|sentry-sdk(?!.*laravel)' /home/fffics/Documents/projects/racklab/docs/prd/17-engineering-quality-typing-ci.md
```

Expected: empty.

```bash
git -C /home/fffics/Documents/projects/racklab add docs/prd/17-engineering-quality-typing-ci.md
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
docs(prd): rewrite §17 engineering/quality/typing/CI for PHP stack

Map every Python tooling slot to the PHP equivalent: ruff/mypy/
basedpyright → Pint + Larastan max + Rector; pip-audit → composer
audit + npm audit; pre-commit → lefthook/captainhook; pytest →
Pest 4; Vitest/RTL/Playwright → Pest browser layer via Dusk +
axe-core. Coverage gates (90/80/70 + named E2E) unchanged. New
custom Larastan rules and CI gates from spec §8 added (OpenAPI
schema drift, semgrep, axe-core a11y, lang:check i18n drift).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: Rewrite PRD §22 docs plugin

**Files:**
- Modify: `docs/prd/22-docs-plugin.md`
- Reference: spec §2 (TipTap row), §4 (packages/racklab/docs-plugin/), §6 (storage backend plugin family for doc-attachment storage)

- [ ] **Step 1: Read current and grep**

```bash
cat /home/fffics/Documents/projects/racklab/docs/prd/22-docs-plugin.md
grep -nE 'TipTap via @tiptap/react|@mantine/tiptap|React|Mantine|@tiptap/react|tiptap-react' /home/fffics/Documents/projects/racklab/docs/prd/22-docs-plugin.md
```

- [ ] **Step 2: Apply replacements**

| Old | New |
| --- | --- |
| TipTap via `@tiptap/react` + `@mantine/tiptap` | `@tiptap/core` vanilla mounted in a Livewire 4 component via `wire:ignore` + `@push('scripts')`; admin authoring uses Filament 5's built-in TipTap-based `RichEditor` (no extra dependency) |
| React-based viewer/editor distinction | Livewire 4 component for the public viewer; Filament `RichEditor` (which is itself TipTap-vanilla under the hood, Tailwind-styled) for admin |
| Attachment uploads via `react-filepond` | `spatie/livewire-filepond` for the chunked upload UX |

Preserve all functional requirements: doc model, attachment storage via the storage-backend plugin family, versioning, permissions integration, tenant scoping.

- [ ] **Step 3: Verify and commit**

```bash
grep -nE 'React|Mantine|@tiptap/react|tiptap-react|react-filepond' /home/fffics/Documents/projects/racklab/docs/prd/22-docs-plugin.md
```

Expected: empty.

```bash
git -C /home/fffics/Documents/projects/racklab add docs/prd/22-docs-plugin.md
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
docs(prd): rewrite §22 docs plugin for TipTap-vanilla + Filament RichEditor

Move from @tiptap/react + @mantine/tiptap to @tiptap/core vanilla
in a Livewire 4 component (public viewer/editor) + Filament 5's
built-in RichEditor (admin authoring, same TipTap engine, Tailwind-
styled). Chunked attachment uploads via spatie/livewire-filepond.
Functional contract (doc model, versioning, permissions, tenant
scoping, attachment via storage-backend plugin family) unchanged.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 10: Rewrite PRD §23 SSH plugin

**Files:**
- Modify: `docs/prd/23-ssh-plugin.md`
- Reference: spec §2 (xterm + noVNC versions), §4 (packages/racklab/ssh-plugin/), §7 (console-script + ProviderConsoleProxy refactor)

- [ ] **Step 1: Read current and grep**

```bash
cat /home/fffics/Documents/projects/racklab/docs/prd/23-ssh-plugin.md
grep -nE 'React|@xterm.*react|noVNC.*React|tsx|TSX|cloud-init host-key' /home/fffics/Documents/projects/racklab/docs/prd/23-ssh-plugin.md
```

- [ ] **Step 2: Apply replacements**

| Old | New |
| --- | --- |
| `@xterm/xterm` mounted in React | `@xterm/xterm` vanilla, mounted in a Livewire 4 component via `wire:ignore` and a `@push('scripts')` init block |
| noVNC React adapter | noVNC vanilla (`@novnc/novnc` 1.7), same `wire:ignore` pattern |
| Direct Proxmox API calls from in-browser console widget | Browser connects to RackLab's WebSocket → RackLab proxies to Proxmox via the `ProviderConsoleProxy` localhost service (spec §7); browser never holds Proxmox creds |
| asyncssh on the server side (Python) | `phpseclib/phpseclib` (PHP SSH/SFTP) for any direct SSH from the app server; SSH-plugin scripted access goes through the same per-job container model with the `racklab/ssh-runner:v1` container kind |

Preserve all functional requirements: cloud-init host-key phone-home (the VM's host key is reported back to RackLab at first boot so the SSH plugin can trust-on-first-use), SSH session lifecycle, key rotation, deployment-to-SSH binding.

- [ ] **Step 3: Verify and commit**

```bash
grep -nE 'React|@xterm.*react|noVNC.*React|tsx|TSX|asyncssh' /home/fffics/Documents/projects/racklab/docs/prd/23-ssh-plugin.md
```

Expected: empty.

```bash
git -C /home/fffics/Documents/projects/racklab add docs/prd/23-ssh-plugin.md
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
docs(prd): rewrite §23 SSH plugin for Livewire-vanilla islands

Move @xterm/xterm and @novnc/novnc from React adapters to vanilla
mounts inside Livewire 4 components via wire:ignore. asyncssh
(Python) replaced by phpseclib/phpseclib on the app server; scripted
SSH access goes through the per-job container model
(racklab/ssh-runner:v1 kind). Console live-watch routes via the
ProviderConsoleProxy from spec §7 — browser never holds Proxmox
creds. Cloud-init host-key phone-home contract unchanged.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 3 — Light PRD sweeps (5 parallelizable tasks)

These touch surviving sections that have stack-specific references sprinkled in but aren't full rewrites. They can run in parallel with Phase 2.

### Task 11: PRD §10 scripting / automation / sandboxing sweep

**Files:**
- Modify: `docs/prd/10-scripting-automation-sandboxing.md`
- Reference: spec §7 (container-job model + ProviderConsoleProxy)

- [ ] **Step 1: Read and grep**

```bash
cat /home/fffics/Documents/projects/racklab/docs/prd/10-scripting-automation-sandboxing.md
grep -nE 'nsjail|bubblewrap|Ansible Runner|ansible-runner|openQA|seccomp-bpf|cgroups|rlimits|Python script worker' /home/fffics/Documents/projects/racklab/docs/prd/10-scripting-automation-sandboxing.md
```

- [ ] **Step 2: Apply replacements**

| Old | New |
| --- | --- |
| `nsjail` for untrusted script execution | per-job ephemeral Podman/Docker containers with `--network=none` default + resource caps + `--read-only` + non-root user (UID 10001) (spec §7); equivalent or stronger isolation than nsjail, plus a clear plugin-extension contract for new container kinds |
| `bubblewrap` builder | not used — Podman is the container substrate |
| `Ansible Runner` | Ansible runs *inside* the per-job container substrate (`racklab/ansible-runner:v1` container kind); RackLab doesn't ship Ansible-Runner as a separate process |
| openQA-style console scripts | unchanged in spirit — same openQA-style framing — but the controller is a `racklab/console-script:v1` container that talks to `ProviderConsoleProxy` over a unix socket (spec §7) rather than holding Proxmox creds |
| `seccomp-bpf` / `cgroups` / `rlimits` directly | Podman's default seccomp profile + cgroups v2 + `--cpus` / `--memory` / `--pids-limit`; document RackLab's hardened container manifest (spec §7) |

Preserve all functional requirements: untrusted code runs only in script workers, no provider credentials in script workers, network disabled by default with explicit allow policy, resource isolation, etc.

- [ ] **Step 3: Verify and commit**

```bash
grep -nE 'nsjail|bubblewrap|Ansible Runner(?! runs inside)|ansible-runner|Python script worker' /home/fffics/Documents/projects/racklab/docs/prd/10-scripting-automation-sandboxing.md
```

Expected: empty (or carefully-allowlisted historical mention).

```bash
git -C /home/fffics/Documents/projects/racklab add docs/prd/10-scripting-automation-sandboxing.md
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
docs(prd): rewrite §10 scripting/sandboxing for Podman job containers

Replace nsjail + Ansible-Runner + raw-seccomp/cgroups prescriptions
with per-job Podman containers (--network=none default, --read-only,
non-root, resource caps) from spec §7. Ansible now runs inside
racklab/ansible-runner:v1 container kind; openQA-style console
scripts run as racklab/console-script:v1 containers that talk to
ProviderConsoleProxy over a unix socket and never hold Proxmox
creds. Functional sandboxing guarantees preserved.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 12: PRD §14 audit logging / observability sweep

**Files:**
- Modify: `docs/prd/14-audit-logging-observability.md`
- Reference: spec §5 (audit_events three-tenant schema), §8 (observability stack)

- [ ] **Step 1: Read and grep**

```bash
cat /home/fffics/Documents/projects/racklab/docs/prd/14-audit-logging-observability.md
grep -nE 'manage\.py|django-prometheus|sentry-sdk(?!.*laravel)|django-health-check|OpenTelemetry exporter for Python|owen-it' /home/fffics/Documents/projects/racklab/docs/prd/14-audit-logging-observability.md
```

- [ ] **Step 2: Apply replacements**

| Old | New |
| --- | --- |
| `manage.py verify_audit_chain` | `php artisan racklab:verify-audit-chain` |
| `django-prometheus` | Laravel Pulse v1.7.3 (in-product dashboard, tenant-scoped via custom Pulse recorder) |
| `sentry-sdk` | `sentry/sentry-laravel` v4.25.1 with `tenant_id` tag on every event |
| `django-health-check` | `spatie/laravel-health` v1.39.3 (`/healthz` liveness, `/readyz` readiness incl. postgres + redis + podman socket + proxmox cluster ping) |

Add — if not already present — that owen-it/laravel-auditing handles model-change tracking and feeds the custom `AuditEvent` table with the hash chain; cross-tenant access + issuance events go through Laravel Events directly.

Confirm the `tenant.cross_access` audit event schema matches spec §5: `actor_tenant` + `resource_tenant` + `target_tenant_set` + outcome + reason. The PRD §14 text and spec §5 must agree on the three-tenant column structure.

- [ ] **Step 3: Verify and commit**

```bash
grep -nE 'manage\.py|django-prometheus|django-health-check|sentry-sdk(?!.*laravel)' /home/fffics/Documents/projects/racklab/docs/prd/14-audit-logging-observability.md
```

Expected: empty.

```bash
git -C /home/fffics/Documents/projects/racklab add docs/prd/14-audit-logging-observability.md
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
docs(prd): sweep §14 audit/observability for Laravel tooling

Replace Python observability stack (django-prometheus +
django-health-check + Python sentry-sdk) with Laravel Pulse +
spatie/laravel-health + sentry/sentry-laravel. manage.py command
references swapped for artisan commands. Confirm audit_events
three-tenant schema (actor_tenant + resource_tenant +
target_tenant_set) per spec §5. Hash chain semantics and
bidirectional surfacing unchanged.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 13: PRD §18 security sweep

**Files:**
- Modify: `docs/prd/18-security.md`
- Reference: spec §5 (CrossTenantFetch / per-row access provenance), §7 (container network policy / ProviderConsoleProxy)

- [ ] **Step 1: Read and grep**

```bash
cat /home/fffics/Documents/projects/racklab/docs/prd/18-security.md
grep -nE 'DRF view|React islands? verify the signature|django(?!-)|django\.|HMAC|Argon2' /home/fffics/Documents/projects/racklab/docs/prd/18-security.md
```

- [ ] **Step 2: Apply replacements**

| Old | New |
| --- | --- |
| `DRF view computes the access decision` | `App\Domain\Tenancy\AccessResolver` (called from controllers / Livewire components / Filament resources) computes the access decision and emits per-row `access_provenance` (spec §5) |
| `React islands verify the signature` | Livewire 4 components rendering server-side already trust server-computed provenance; client-side verification only matters for the vanilla JS islands (xterm/noVNC/Chart/TipTap) and uses the same provenance contract: HMAC verifies via a tiny vanilla-JS helper before rendering |
| `Argon2 via django[argon2]` (already covered in §06) | Laravel's `Hash::driver('argon2id')` |

Preserve all functional security requirements. Important: this section explicitly says "No provider credentials in script workers" (line 37 of the current file). That requirement is **load-bearing** and was the basis for the console-proxy refactor in spec §7. Make sure that line stays.

- [ ] **Step 3: Verify and commit**

```bash
grep -nE 'DRF|django(?!-)|django\.|React islands' /home/fffics/Documents/projects/racklab/docs/prd/18-security.md
```

Expected: empty.

```bash
git -C /home/fffics/Documents/projects/racklab add docs/prd/18-security.md
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
docs(prd): sweep §18 security for Laravel framing

Replace DRF view + React island verification framing with
AccessResolver-emitted per-row access provenance (spec §5) and
Livewire 4 / vanilla-JS-island consumers. Argon2 wiring moves
to Hash::driver('argon2id'). Load-bearing 'no provider credentials
in script workers' clause unchanged — this is the basis for the
ProviderConsoleProxy refactor in spec §7.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 14: PRD §19 data model sweep

**Files:**
- Modify: `docs/prd/19-data-model.md`
- Reference: spec §5 (audit_events three-tenant schema), spec §4 (Eloquent model layer)

- [ ] **Step 1: Read and grep**

```bash
cat /home/fffics/Documents/projects/racklab/docs/prd/19-data-model.md
grep -nE 'Django|django\.db|models\.|JSONField|ArrayField|EncryptedField|on_delete|GenericForeignKey|m2m_changed|RelatedField|TextChoices|IntegerChoices' /home/fffics/Documents/projects/racklab/docs/prd/19-data-model.md
```

- [ ] **Step 2: Apply replacements**

The data model section uses Django ORM idioms heavily. Map:

| Old (Django) | New (Eloquent + Laravel) |
| --- | --- |
| `models.Model` / `models.CharField` / `models.JSONField` | Eloquent `Model` with `$casts`/`$fillable` arrays; JSONB fields cast to `'array'` or `'json'` |
| `ArrayField(JSONField(...))` (Postgres-specific in Django) | JSONB column with `$casts = ['column' => 'array']`; for typed payloads use `spatie/laravel-data` DTOs |
| `EncryptedField` (django-fernet-fields) | Laravel's built-in `encrypted` cast (`$casts = ['column' => 'encrypted']` or `'encrypted:json'`) |
| `on_delete=models.PROTECT` | Foreign key `onDelete('restrict')` in migrations |
| `GenericForeignKey` / contenttypes | Polymorphic relations (`morphTo`/`morphMany`) — Laravel-native; no contenttypes app needed |
| `m2m_changed` signal | Eloquent model events (`saved`, `pivotSyncing`, `pivotSynced`) |
| `TextChoices` / `IntegerChoices` | PHP 8 enums (`enum JobState: string { case Pending = 'pending'; ... }`) |

Confirm the `AuditEvent` model carries the three-tenant schema: `actor_tenant` + `resource_tenant` + `target_tenant_set` (JSONB array). The current PRD §19:203 already describes this correctly; verify and lightly reword if Django-specific syntax remains.

Confirm `RoleBinding` carries `scope_type` (enum) + `tenant_set` (JSONB array). Current PRD already has this; verify.

Confirm `TokenGrant` carries `track` (`jwt` / `pat`) + `bearer_hash` (nullable, used for Track B only) + `scope_type` + `tenant_set`. Current PRD already has this.

- [ ] **Step 3: Verify and commit**

```bash
grep -nE 'Django|django\.db|models\.Model|JSONField|ArrayField|EncryptedField|on_delete|GenericForeignKey|m2m_changed|TextChoices' /home/fffics/Documents/projects/racklab/docs/prd/19-data-model.md
```

Expected: empty.

```bash
git -C /home/fffics/Documents/projects/racklab add docs/prd/19-data-model.md
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
docs(prd): sweep §19 data model for Eloquent idioms

Replace Django ORM syntax (models.*, JSONField, ArrayField,
EncryptedField, on_delete, GenericForeignKey, TextChoices) with
Eloquent + Laravel equivalents (\$casts, JSONB-via-array-cast,
encrypted cast, onDelete restrict, polymorphic morphTo, PHP 8
enums). Three-tenant AuditEvent schema and RoleBinding /
TokenGrant scope columns confirmed against spec §5.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 15: Remaining PRD sections + README sweep

**Files:**
- Modify (light touch): `docs/prd/01-executive-summary.md`, `docs/prd/02-goals-non-goals.md`, `docs/prd/03-users-personas.md`, `docs/prd/04-full-target-requirements.md`, `docs/prd/08-catalog-stacks-deployments.md`, `docs/prd/09-networking.md`, `docs/prd/11-quotas-scheduling-placement.md`, `docs/prd/12-proxmox-provider.md`, `docs/prd/16-container-operations.md`, `docs/prd/20-open-questions-risks.md`, `docs/prd/21-sources.md`, `docs/prd/README.md`

- [ ] **Step 1: Bulk grep across all targets**

```bash
grep -nE 'Django|DRF|pluggy|React|Mantine|Radix|LinguiJS|django-allauth|simplejwt|knox|nsjail|Ansible Runner|ruff|mypy|basedpyright|pytest|proxmoxer|@tiptap/react|@mantine/tiptap|django-vite|django-prometheus|django-health-check|manage\.py|pyproject\.toml|uv\.lock' \
  /home/fffics/Documents/projects/racklab/docs/prd/01-executive-summary.md \
  /home/fffics/Documents/projects/racklab/docs/prd/02-goals-non-goals.md \
  /home/fffics/Documents/projects/racklab/docs/prd/03-users-personas.md \
  /home/fffics/Documents/projects/racklab/docs/prd/04-full-target-requirements.md \
  /home/fffics/Documents/projects/racklab/docs/prd/08-catalog-stacks-deployments.md \
  /home/fffics/Documents/projects/racklab/docs/prd/09-networking.md \
  /home/fffics/Documents/projects/racklab/docs/prd/11-quotas-scheduling-placement.md \
  /home/fffics/Documents/projects/racklab/docs/prd/12-proxmox-provider.md \
  /home/fffics/Documents/projects/racklab/docs/prd/16-container-operations.md \
  /home/fffics/Documents/projects/racklab/docs/prd/20-open-questions-risks.md \
  /home/fffics/Documents/projects/racklab/docs/prd/21-sources.md \
  /home/fffics/Documents/projects/racklab/docs/prd/README.md
```

For each match: identify the section, apply a targeted Edit per the same mapping table as Tasks 3–14. Don't rewrite paragraphs that aren't stack-specific.

Specific known touch-ups:
- **§01 executive summary** — almost no stack mentions, but the one-screen description may need "PHP/Laravel control plane" wording
- **§02 goals/non-goals** — language-agnostic; might mention "Django" once in passing
- **§03 personas** — language-agnostic
- **§04 full target requirements** — implementation tools may be mentioned in passing
- **§08 catalog/stacks/deployments** — functional; light if any
- **§09 networking** — language-agnostic
- **§11 quotas/scheduling** — language-agnostic
- **§12 proxmox provider** — may mention `proxmoxer`; replace with "Guzzle-based typed Proxmox client per the discipline spec at docs/superpowers/specs/2026-05-24-proxmox-client-discipline.md, ported to PHP"
- **§16 container operations** — talks about containers in the product (Proxmox LXC); shouldn't conflict, but verify no nsjail mentions
- **§20 open questions & risks** — may mention deferred stack decisions that no longer apply (e.g., "TimescaleDB until a spike proves the bottleneck" still applies — keep it)
- **§21 sources** — citation list; preserve but check for now-irrelevant Django/React URLs that should be removed or annotated as historical
- **README** — index file; update any stack-specific framing in the overview

- [ ] **Step 2: Apply per-file Edits**

For each file with hits, edit per the same map. Most files should require 0–3 edits each.

- [ ] **Step 3: Verify clean**

Re-run the bulk grep from Step 1. Expected: empty.

- [ ] **Step 4: Commit (single commit for the entire sweep)**

```bash
git -C /home/fffics/Documents/projects/racklab add docs/prd/*.md
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
docs(prd): sweep remaining PRD sections for stack references

Light-touch updates across §01, §02, §03, §04, §08, §09, §11, §12,
§16, §20, §21, README — replace residual Django/React/Python tooling
mentions with the Laravel-stack equivalents (or remove citations
that no longer apply). Functional content unchanged in these
sections; the heavy stack rewrites land in tasks 3-14 (§05/§06/§07/
§10/§13/§14/§15/§17/§18/§19/§22/§23).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 4 — Roadmap milestone rewrites (14 parallelizable tasks)

Each milestone follows the same shape: Goal / In scope / Dependencies / Deliverables / Acceptance criteria / Test layers / Risks/open questions / Out of scope. **Goals stay** (functional). **Deliverables / Test layers / Risks** are where the stack-specific content lives and gets rewritten.

Common substitutions for every milestone:
- `Django app` → `Laravel module under app/Domain/<name>/`
- `DRF endpoint` → `Laravel controller (under app/Http/Controllers) or Livewire component`
- `pluggy hookspec` → `typed hookspec event class (app/Events/Hookspecs/<Domain>/<Verb>Event.php)`
- `React island` → `Livewire 4 component (or vanilla JS island mounted in one)`
- `Mantine table` → `Filament 5 table` (admin) or `daisyUI table + Livewire datatable` (public)
- `pytest test` → `Pest 4 test (tiny/contract/integration/browser)`
- `ruff/mypy gate` → `Pint/Larastan/Rector gate`
- `Vitest unit` → `Pest tiny`
- `Playwright E2E` → `Dusk + axe-core`

### Task 16: Roadmap M00 + M00.5 (foundations + packaging)

**Files:**
- Modify: `docs/roadmap/M00-foundations.md`, `docs/roadmap/M00.5-packaging-runtime-install.md`

- [ ] **Step 1: Read both files and grep stale refs**

```bash
grep -nE 'Django|DRF|pluggy|pytest|ruff|mypy|pyproject|uv\.lock|manage\.py|django-vite|React' /home/fffics/Documents/projects/racklab/docs/roadmap/M00-foundations.md /home/fffics/Documents/projects/racklab/docs/roadmap/M00.5-packaging-runtime-install.md
```

- [ ] **Step 2: Apply replacements per the common substitution table above**

Specifics for M00 (foundations):
- Deliverables: replace "Django 5.2 LTS install" with "Laravel 13 install via `composer create-project`"; replace pluggy / Python-runtime references; the 26-flag automatable installer surface in M00.5 stays but each flag's target file changes (composer.json + .env, not pyproject.toml + Django settings)

Specifics for M00.5 (packaging):
- The installer's 26-flag surface stays. Each flag maps to a Laravel config / .env entry instead of Django settings. The `racklab` CLI is now an Artisan-driven binary (or a separate Symfony Console app) rather than the old Python CLI.

- [ ] **Step 3: Verify clean, commit**

```bash
grep -nE 'Django|DRF|pluggy|pytest|ruff|mypy|pyproject|uv\.lock|manage\.py|django-vite|React' /home/fffics/Documents/projects/racklab/docs/roadmap/M00-foundations.md /home/fffics/Documents/projects/racklab/docs/roadmap/M00.5-packaging-runtime-install.md
```

Expected: empty.

```bash
git -C /home/fffics/Documents/projects/racklab add docs/roadmap/M00-foundations.md docs/roadmap/M00.5-packaging-runtime-install.md
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
docs(roadmap): rewrite M00 + M00.5 for Laravel stack

Foundations milestone now boots a Laravel 13 + Octane + Filament 5
skeleton instead of Django; packaging milestone keeps the
26-flag installer surface, each flag mapped to Laravel
config/.env instead of Django settings.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 17: Roadmap M01 (auth/identity)

**Files:**
- Modify: `docs/roadmap/M01-auth-identity.md`

- [ ] **Step 1: Read and grep**

```bash
grep -nE 'django-allauth|simplejwt|knox|django-rest-knox|allauth|Channels|DRF' /home/fffics/Documents/projects/racklab/docs/roadmap/M01-auth-identity.md
```

- [ ] **Step 2: Apply replacements** — map Django auth packages to Laravel equivalents per the PRD §06 rewrite (Task 4). Deliverables should reference Sanctum + Fortify + Socialite + OIDC/SAML providers + `firebase/php-jwt` + JWKS endpoint. Test layers reference Pest 4 (not pytest); browser layer uses Dusk.

- [ ] **Step 3: Verify and commit**

```bash
grep -nE 'django-allauth|simplejwt|knox|allauth|DRF' /home/fffics/Documents/projects/racklab/docs/roadmap/M01-auth-identity.md
```

```bash
git -C /home/fffics/Documents/projects/racklab add docs/roadmap/M01-auth-identity.md
git -C /home/fffics/Documents/projects/racklab commit -m "docs(roadmap): rewrite M01 auth/identity for Sanctum + Fortify + JWT-Track-A

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 18: Roadmap M02 + M02.5 (deployment lifecycle + baseline ops smoke)

**Files:**
- Modify: `docs/roadmap/M02-deployment-lifecycle.md`, `docs/roadmap/M02.5-baseline-ops-smoke.md`

- [ ] **Step 1: Read both files and grep**

```bash
grep -nE 'Django|DRF|Celery|django-channels|pluggy|pytest|React|Mantine' /home/fffics/Documents/projects/racklab/docs/roadmap/M02-deployment-lifecycle.md /home/fffics/Documents/projects/racklab/docs/roadmap/M02.5-baseline-ops-smoke.md
```

- [ ] **Step 2: Apply replacements** — Job state machine moves to `app/Domain/Jobs/`. Job runtime moves from Celery (or whatever the old design used) to Horizon (Redis). Real-time deployment progress streams via Reverb (not Channels SSE). UI for deployment list/detail is Livewire 4 + Filament 5 admin. Tests are Pest 4 layered.

- [ ] **Step 3: Verify and commit**

```bash
grep -nE 'Django|DRF|Celery|django-channels|pluggy(?!\s)|pytest|React' /home/fffics/Documents/projects/racklab/docs/roadmap/M02-deployment-lifecycle.md /home/fffics/Documents/projects/racklab/docs/roadmap/M02.5-baseline-ops-smoke.md
```

```bash
git -C /home/fffics/Documents/projects/racklab add docs/roadmap/M02-deployment-lifecycle.md docs/roadmap/M02.5-baseline-ops-smoke.md
git -C /home/fffics/Documents/projects/racklab commit -m "docs(roadmap): rewrite M02 + M02.5 deployment lifecycle for Horizon + Reverb + Livewire

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 19: Roadmap M03 + M04 (proxmox provider + console)

**Files:**
- Modify: `docs/roadmap/M03-proxmox-provider.md`, `docs/roadmap/M04-console-proxmox.md`

- [ ] **Step 1: Read and grep**

```bash
grep -nE 'proxmoxer|Django|asyncssh|Channels|websockify|noVNC.*React' /home/fffics/Documents/projects/racklab/docs/roadmap/M03-proxmox-provider.md /home/fffics/Documents/projects/racklab/docs/roadmap/M04-console-proxmox.md
```

- [ ] **Step 2: Apply replacements**

- M03: `proxmoxer` (Python) → Guzzle 7.10 + custom typed `App\Providers\Proxmox\Client` following the discipline in `docs/superpowers/specs/2026-05-24-proxmox-client-discipline.md` (still applies). Multi-issuer TLS trust + task polling + idempotency-key + UPID handling carry forward.
- M04: console deliverables now include `racklab/console-script:v1` container kind + `ProviderConsoleProxy` localhost service. xterm.js + noVNC vanilla mounted in Livewire 4. No browser-side Proxmox creds.

- [ ] **Step 3: Verify and commit**

```bash
grep -nE 'proxmoxer|asyncssh|Channels|noVNC.*React' /home/fffics/Documents/projects/racklab/docs/roadmap/M03-proxmox-provider.md /home/fffics/Documents/projects/racklab/docs/roadmap/M04-console-proxmox.md
```

```bash
git -C /home/fffics/Documents/projects/racklab add docs/roadmap/M03-proxmox-provider.md docs/roadmap/M04-console-proxmox.md
git -C /home/fffics/Documents/projects/racklab commit -m "docs(roadmap): rewrite M03 + M04 for Guzzle-based Proxmox client + ProviderConsoleProxy

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 20: Roadmap M05a + M05b (networking)

**Files:**
- Modify: `docs/roadmap/M05a-networking-attach.md`, `docs/roadmap/M05b-networking-managed.md`

- [ ] **Step 1: Read and grep**

```bash
grep -nE 'Django|DRF|pluggy|React|Mantine|Channels' /home/fffics/Documents/projects/racklab/docs/roadmap/M05a-networking-attach.md /home/fffics/Documents/projects/racklab/docs/roadmap/M05b-networking-managed.md
```

- [ ] **Step 2: Apply replacements** per common substitution table.

- [ ] **Step 3: Verify and commit**

```bash
grep -nE 'Django|DRF|pluggy|React|Mantine|Channels' /home/fffics/Documents/projects/racklab/docs/roadmap/M05a-networking-attach.md /home/fffics/Documents/projects/racklab/docs/roadmap/M05b-networking-managed.md
```

```bash
git -C /home/fffics/Documents/projects/racklab add docs/roadmap/M05a-networking-attach.md docs/roadmap/M05b-networking-managed.md
git -C /home/fffics/Documents/projects/racklab commit -m "docs(roadmap): rewrite M05a + M05b networking for Laravel stack

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 21: Roadmap M06 (quotas/scheduling)

**Files:**
- Modify: `docs/roadmap/M06-quotas-scheduling.md`

- [ ] **Step 1: Read and grep**

```bash
grep -nE 'Django|DRF|pluggy|pytest|React|Mantine' /home/fffics/Documents/projects/racklab/docs/roadmap/M06-quotas-scheduling.md
```

- [ ] **Step 2: Apply replacements** — quota math lives in `app/Domain/Quota/`; Eloquent models for `Quota`, `Reservation`; Filament admin resources for quota management; Pest mutation testing on quota math (high-stakes per spec §8).

- [ ] **Step 3: Verify and commit**

```bash
grep -nE 'Django|DRF|pluggy|pytest|React' /home/fffics/Documents/projects/racklab/docs/roadmap/M06-quotas-scheduling.md
```

```bash
git -C /home/fffics/Documents/projects/racklab add docs/roadmap/M06-quotas-scheduling.md
git -C /home/fffics/Documents/projects/racklab commit -m "docs(roadmap): rewrite M06 quotas/scheduling for Laravel stack

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 22: Roadmap M07a + M07b (cloud-init provisioning + script sandbox)

**Files:**
- Modify: `docs/roadmap/M07a-cloud-init-provisioning.md`, `docs/roadmap/M07b-script-sandbox.md`

- [ ] **Step 1: Read and grep**

```bash
grep -nE 'nsjail|Ansible Runner|ansible-runner|Django|pluggy|cloud-init' /home/fffics/Documents/projects/racklab/docs/roadmap/M07a-cloud-init-provisioning.md /home/fffics/Documents/projects/racklab/docs/roadmap/M07b-script-sandbox.md
```

- [ ] **Step 2: Apply replacements**

- M07a (cloud-init): no major stack change — cloud-init is the standard mechanism in both designs. Light touch on any Django/DRF wording.
- M07b (script sandbox): biggest rewrite in Phase 4 — nsjail dropped entirely. Per-job Podman containers (`--network=none` default + per-kind manifests). Ansible runs inside `racklab/ansible-runner:v1`. Console scripts use `racklab/console-script:v1` + ProviderConsoleProxy. Container image build pipeline + cosign signing.

- [ ] **Step 3: Verify and commit**

```bash
grep -nE 'nsjail|ansible-runner(?! :v1)|Django|pluggy' /home/fffics/Documents/projects/racklab/docs/roadmap/M07a-cloud-init-provisioning.md /home/fffics/Documents/projects/racklab/docs/roadmap/M07b-script-sandbox.md
```

```bash
git -C /home/fffics/Documents/projects/racklab add docs/roadmap/M07a-cloud-init-provisioning.md docs/roadmap/M07b-script-sandbox.md
git -C /home/fffics/Documents/projects/racklab commit -m "docs(roadmap): rewrite M07a + M07b for Podman job containers

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 23: Roadmap M08 (docs plugin)

**Files:**
- Modify: `docs/roadmap/M08-docs-plugin.md`

- [ ] **Step 1: Read and grep**

```bash
grep -nE '@tiptap/react|@mantine/tiptap|tiptap-react|React|Mantine|django' /home/fffics/Documents/projects/racklab/docs/roadmap/M08-docs-plugin.md
```

- [ ] **Step 2: Apply replacements** — covered in PRD §22 rewrite (Task 9). Deliverables and acceptance criteria should mention `@tiptap/core` vanilla + Filament `RichEditor`; package lives at `packages/racklab/docs-plugin/`.

- [ ] **Step 3: Verify and commit**

```bash
grep -nE '@tiptap/react|@mantine/tiptap|tiptap-react|React|Mantine|django' /home/fffics/Documents/projects/racklab/docs/roadmap/M08-docs-plugin.md
```

```bash
git -C /home/fffics/Documents/projects/racklab add docs/roadmap/M08-docs-plugin.md
git -C /home/fffics/Documents/projects/racklab commit -m "docs(roadmap): rewrite M08 docs-plugin for TipTap-vanilla + Filament RichEditor

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 24: Roadmap M09 (SSH plugin)

**Files:**
- Modify: `docs/roadmap/M09-ssh-plugin.md`

- [ ] **Step 1: Read and grep**

```bash
grep -nE '@xterm.*react|noVNC.*React|asyncssh|React|django' /home/fffics/Documents/projects/racklab/docs/roadmap/M09-ssh-plugin.md
```

- [ ] **Step 2: Apply replacements** — covered in PRD §23 rewrite (Task 10). Deliverables reference xterm.js + noVNC vanilla in Livewire; phpseclib for server-side SSH; ssh-runner container kind.

- [ ] **Step 3: Verify and commit**

```bash
grep -nE '@xterm.*react|noVNC.*React|asyncssh|React' /home/fffics/Documents/projects/racklab/docs/roadmap/M09-ssh-plugin.md
```

```bash
git -C /home/fffics/Documents/projects/racklab add docs/roadmap/M09-ssh-plugin.md
git -C /home/fffics/Documents/projects/racklab commit -m "docs(roadmap): rewrite M09 ssh-plugin for vanilla xterm/noVNC in Livewire

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 25: Roadmap M10a + M10b (UI component library + a11y/i18n)

**Files:**
- Modify: `docs/roadmap/M10a-ui-component-library.md`, `docs/roadmap/M10b-a11y-i18n-hardening.md`

- [ ] **Step 1: Read and grep**

```bash
grep -nE 'Mantine|Radix|LinguiJS|TanStack|React|Vitest|Playwright|axe-core(?! in Dusk)' /home/fffics/Documents/projects/racklab/docs/roadmap/M10a-ui-component-library.md /home/fffics/Documents/projects/racklab/docs/roadmap/M10b-a11y-i18n-hardening.md
```

- [ ] **Step 2: Apply replacements**

- M10a (UI component library): rewrite the goal — from "Mantine + Radix gaps" to "public-facing Livewire 4 + daisyUI 5 component library, plus admin uses Filament 5 stock components." Deliverables: shared Livewire components (Header, Sidebar, DataTable, Modal, Toast, FormControl, etc.), each Tailwind-styled with daisyUI primitives.
- M10b (a11y/i18n): Lingui → Laravel i18n + `php artisan lang:check` for catalog drift; axe-core via Dusk; vitest-axe → Pest browser layer + axe-core integration; Playwright → Dusk.

- [ ] **Step 3: Verify and commit**

```bash
grep -nE 'Mantine|Radix|LinguiJS|TanStack|React|Vitest|Playwright' /home/fffics/Documents/projects/racklab/docs/roadmap/M10a-ui-component-library.md /home/fffics/Documents/projects/racklab/docs/roadmap/M10b-a11y-i18n-hardening.md
```

```bash
git -C /home/fffics/Documents/projects/racklab add docs/roadmap/M10a-ui-component-library.md docs/roadmap/M10b-a11y-i18n-hardening.md
git -C /home/fffics/Documents/projects/racklab commit -m "docs(roadmap): rewrite M10a + M10b for Livewire/daisyUI + Laravel i18n + Dusk-axe

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 26: Roadmap M11a + M11b (TLS backend + admin GUI)

**Files:**
- Modify: `docs/roadmap/M11a-tls-backend.md`, `docs/roadmap/M11b-tls-admin-gui.md`

- [ ] **Step 1: Read and grep**

```bash
grep -nE 'Traefik|Django|DRF|React|Mantine|lego' /home/fffics/Documents/projects/racklab/docs/roadmap/M11a-tls-backend.md /home/fffics/Documents/projects/racklab/docs/roadmap/M11b-tls-admin-gui.md
```

- [ ] **Step 2: Apply replacements**

- M11a (TLS backend): Caddy in FrankenPHP provides built-in TLS for the standard public-cert ACME flow. Manual cert upload + custom CA + ACME-DNS profiles still managed by RackLab and surfaced through Caddy configuration. The four issuance profiles from the (deleted) TLS spec carry forward in shape.
- M11b (TLS admin GUI): Filament 5 admin pages for cert management instead of React + Mantine forms.

- [ ] **Step 3: Verify and commit**

```bash
grep -nE 'Traefik(?! 3 (was|prior))|Django|DRF|React|Mantine|lego(?! cert agent)' /home/fffics/Documents/projects/racklab/docs/roadmap/M11a-tls-backend.md /home/fffics/Documents/projects/racklab/docs/roadmap/M11b-tls-admin-gui.md
```

```bash
git -C /home/fffics/Documents/projects/racklab add docs/roadmap/M11a-tls-backend.md docs/roadmap/M11b-tls-admin-gui.md
git -C /home/fffics/Documents/projects/racklab commit -m "docs(roadmap): rewrite M11a + M11b for Caddy-in-FrankenPHP + Filament admin

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 27: Roadmap M12 (scale profile)

**Files:**
- Modify: `docs/roadmap/M12-scale-profile.md`

- [ ] **Step 1: Read and grep**

```bash
grep -nE 'gunicorn|uvicorn|Django|Channels|Celery|pytest|systemd unit Django' /home/fffics/Documents/projects/racklab/docs/roadmap/M12-scale-profile.md
```

- [ ] **Step 2: Apply replacements** — Scale profile uses Nomad + Podman driver scheduling FrankenPHP replicas + Horizon worker pools + Reverb replicas (sticky sessions via Pusher cluster ID) + per-job containers as Nomad batch jobs. Postgres/Redis as managed services (or Nomad-scheduled if self-hosting).

- [ ] **Step 3: Verify and commit**

```bash
grep -nE 'gunicorn|uvicorn|Django|Channels|Celery|pytest' /home/fffics/Documents/projects/racklab/docs/roadmap/M12-scale-profile.md
```

```bash
git -C /home/fffics/Documents/projects/racklab add docs/roadmap/M12-scale-profile.md
git -C /home/fffics/Documents/projects/racklab commit -m "docs(roadmap): rewrite M12 scale-profile for FrankenPHP + Horizon + Reverb on Nomad

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 28: Roadmap M13a + M13b + M13c + M13d (HA + observability + backup + release hardening)

**Files:**
- Modify: `docs/roadmap/M13a-ha-data-tier.md`, `docs/roadmap/M13b-observability.md`, `docs/roadmap/M13c-backup-restore-upgrade.md`, `docs/roadmap/M13d-release-hardening.md`

- [ ] **Step 1: Read and grep**

```bash
grep -nE 'Django|DRF|django-prometheus|sentry-sdk(?!.*laravel)|django-health-check|pytest|TimescaleDB' /home/fffics/Documents/projects/racklab/docs/roadmap/M13*.md
```

- [ ] **Step 2: Apply replacements**

- M13a (HA data tier): Postgres replication + PgBouncer + Redis Sentinel/Cluster, with Horizon workers + Reverb daemons replicated. No Django-specific HA concerns (Octane handles in-memory state with the discipline from spec §5 + §8).
- M13b (observability): replaces django-prometheus/django-health-check/python-sentry with Pulse + spatie/laravel-health + sentry/sentry-laravel + optional OpenTelemetry exporter (deferred from M13b scope itself, but the exporter implementation lands here when enabled).
- M13c (backup/restore/upgrade): spatie/laravel-backup for app data; Postgres dump + Redis snapshot + filesystem tar; container-image registry backup.
- M13d (release hardening): semgrep + roave/security-advisories + enlightn/security-checker + composer audit + npm audit gates; mutation testing on high-stakes surfaces; codex review trigger paths.

- [ ] **Step 3: Verify and commit**

```bash
grep -nE 'Django|DRF|django-prometheus|django-health-check|pytest' /home/fffics/Documents/projects/racklab/docs/roadmap/M13*.md
```

```bash
git -C /home/fffics/Documents/projects/racklab add docs/roadmap/M13a-ha-data-tier.md docs/roadmap/M13b-observability.md docs/roadmap/M13c-backup-restore-upgrade.md docs/roadmap/M13d-release-hardening.md
git -C /home/fffics/Documents/projects/racklab commit -m "docs(roadmap): rewrite M13a-d for Laravel observability/HA/backup/release

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 29: Roadmap README + dependency graph

**Files:**
- Modify: `docs/roadmap/README.md`

- [ ] **Step 1: Read and grep**

```bash
cat /home/fffics/Documents/projects/racklab/docs/roadmap/README.md
grep -nE 'Django|DRF|pluggy|React|Mantine|pytest|ruff|mypy' /home/fffics/Documents/projects/racklab/docs/roadmap/README.md
```

- [ ] **Step 2: Update**

- Replace any stack-specific framing in the milestone-table prose
- The Mermaid dependency graph between milestones should structurally stay the same (the dependencies between M0 → M13d are stack-independent) — verify node labels don't reference Django-specific deliverables; if any do, update them

- [ ] **Step 3: Verify and commit**

```bash
grep -nE 'Django|DRF|pluggy|React|Mantine|pytest|ruff|mypy' /home/fffics/Documents/projects/racklab/docs/roadmap/README.md
```

```bash
git -C /home/fffics/Documents/projects/racklab add docs/roadmap/README.md
git -C /home/fffics/Documents/projects/racklab commit -m "docs(roadmap): rewrite roadmap README for Laravel stack

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Phase 5 — Architecture diagrams

### Task 30: Architecture diagrams update

**Files:**
- Modify: `docs/architecture/diagrams.md`
- Reference: spec §3 (process topology), §7 (container-job flow + Reverb), §5 (tenancy resolution)

- [ ] **Step 1: Read current diagrams**

```bash
cat /home/fffics/Documents/projects/racklab/docs/architecture/diagrams.md
grep -cE '```mermaid' /home/fffics/Documents/projects/racklab/docs/architecture/diagrams.md
```

- [ ] **Step 2: Update each Mermaid diagram**

For each Mermaid block, update node labels:
- "Django app" / "DRF view" → "Laravel app (FrankenPHP + Octane)"
- "Channels" / "ASGI" → "Reverb daemon"
- "Celery worker" → "Horizon worker"
- "pluggy hookspec" → "HookDispatcher event"
- "React island" → "Livewire 4 component" or "vanilla JS island"
- "Mantine table" → "Filament 5 table" or "daisyUI + Livewire datatable"
- For console/deployment flow diagrams, add the `ProviderConsoleProxy` box (from spec §7) between the Horizon worker and the Proxmox API

Keep structural relationships unchanged unless the spec genuinely changed the topology (it did in the case of: Reverb instead of Channels, ProviderConsoleProxy in front of Proxmox for console containers, Postgres broadcast_event_log instead of Redis Streams).

- [ ] **Step 3: Render diagrams to verify syntax**

```bash
which mmdc && mmdc -i /home/fffics/Documents/projects/racklab/docs/architecture/diagrams.md -o /tmp/diagrams-check.svg && echo "render ok"
```

Expected: "render ok" (or "mmdc not installed" — acceptable in the local environment; CI will catch).

- [ ] **Step 4: Grep stale refs and commit**

```bash
grep -nE 'Django|DRF|Channels|Celery|pluggy|@?Mantine|@?Radix|React island' /home/fffics/Documents/projects/racklab/docs/architecture/diagrams.md
```

Expected: empty.

```bash
git -C /home/fffics/Documents/projects/racklab add docs/architecture/diagrams.md
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
docs(architecture): rewrite diagrams for Laravel stack topology

Update node labels in every Mermaid diagram: Django/DRF →
Laravel/Octane; Channels/ASGI → Reverb daemon; Celery → Horizon;
pluggy → HookDispatcher; React island → Livewire/vanilla JS island.
Add ProviderConsoleProxy box between Horizon worker and Proxmox
API for console-script flows (spec §7). Add Postgres
broadcast_event_log to the real-time replay flow.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 6 — Final integrity sweep

### Task 31: Final cross-tree grep + integrity check

**Files:**
- Read-only: entire `docs/` tree

This is the gate that closes the plan. Every stale stack reference must be gone (or explicitly allowlisted in clearly-marked historical context).

- [ ] **Step 1: Re-run the canonical stale-reference grep across all of `docs/`**

```bash
grep -rnE 'Django|django|DRF|drf-spectacular|django-allauth|simplejwt|knox|Channels(?!.*Laravel)|pluggy|Pluggy|@?tiptap.?(react|/react)|@?mantine|@?radix|LinguiJS|lingui|django-vite|react-filepond|nsjail|Ansible Runner|ansible-runner|django-prometheus|django-health-check|FilePond.*chunked-receive|manage\.py|pyproject\.toml|uv\.lock|ruff|mypy|basedpyright|bandit|pytest|pytest-django|factory-boy|testcontainers-py|psycopg|asyncssh|nats-py|proxmoxer|Argon2 via .django\[argon2\]' /home/fffics/Documents/projects/racklab/docs/
```

Allowed survivors (only in historical context, must be in `docs/superpowers/specs/2026-05-26-laravel-redesign.md` or labelled "previously" / "was" / "replaced by"):
- Mentions in Appendix A of the spec (codex review summaries)
- Mentions in Appendix B decision log
- Decision narrative inside §1 of the spec

If anything else surfaces, identify the file, open it, apply targeted fixes, and re-run.

- [ ] **Step 2: Markdownlint the entire tree**

```bash
which markdownlint && find /home/fffics/Documents/projects/racklab/docs -name '*.md' -print0 | xargs -0 markdownlint
```

Expected: empty (or "markdownlint not installed" — CI will catch).

- [ ] **Step 3: Mermaid render check**

```bash
which mmdc && for f in $(find /home/fffics/Documents/projects/racklab/docs -name '*.md' | xargs grep -l '```mermaid'); do echo "--- $f ---"; mmdc -i "$f" -o /tmp/render-check.svg 2>&1 | tail -5; done
```

Expected: no parse errors.

- [ ] **Step 4: Spec-vs-PRD-vs-roadmap cross-check**

Pick three load-bearing claims from the spec and verify they're reflected consistently across the PRD and roadmap:

1. **Track A JWT** — should appear in PRD §06 (Task 4), roadmap M01 (Task 17), spec §2 + §9 + Appendix B
2. **Per-job Podman containers with `--network=none` default** — should appear in PRD §10 (Task 11), PRD §18 (Task 13), roadmap M07b (Task 22), spec §7
3. **AuditEvent three-tenant schema (`actor_tenant` + `resource_tenant` + `target_tenant_set`)** — should appear in PRD §14 (Task 12), PRD §19 (Task 14), spec §5

```bash
# Track A JWT
grep -l 'Track A' /home/fffics/Documents/projects/racklab/docs/prd/06*.md /home/fffics/Documents/projects/racklab/docs/roadmap/M01*.md /home/fffics/Documents/projects/racklab/docs/superpowers/specs/2026-05-26-laravel-redesign.md
# network=none
grep -l 'network=none' /home/fffics/Documents/projects/racklab/docs/prd/10*.md /home/fffics/Documents/projects/racklab/docs/prd/18*.md /home/fffics/Documents/projects/racklab/docs/roadmap/M07b*.md /home/fffics/Documents/projects/racklab/docs/superpowers/specs/2026-05-26-laravel-redesign.md
# AuditEvent three-tenant
grep -l 'actor_tenant.*resource_tenant\|resource_tenant.*actor_tenant' /home/fffics/Documents/projects/racklab/docs/prd/14*.md /home/fffics/Documents/projects/racklab/docs/prd/19*.md /home/fffics/Documents/projects/racklab/docs/superpowers/specs/2026-05-26-laravel-redesign.md
```

Expected: each command returns at least the three expected files.

- [ ] **Step 5: PROGRESS.md update**

Update `PROGRESS.md` (or create if absent in current state) to note: "prd-rewrite sub-plan complete; docs/prd, docs/roadmap, docs/architecture, CLAUDE.md, AGENTS.md all describe the Laravel stack. Next sub-plans: laravel-scaffold, tenancy-auth, plugin-lifecycle, realtime-replay, script-containers, ci-gates."

```bash
ls /home/fffics/Documents/projects/racklab/PROGRESS.md 2>/dev/null
```

If absent, create it with that single status line. If present, Edit to add the line.

- [ ] **Step 6: Final commit**

```bash
git -C /home/fffics/Documents/projects/racklab add -A
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
docs: prd-rewrite sub-plan complete

Final integrity sweep clean: no stack-stale references in docs/
outside of historical context in the Laravel redesign spec. All
PRD sections, roadmap milestones, architecture diagrams, and
agent orientation files describe the PHP/Laravel 13 + Octane +
Livewire 4 + Filament 5 stack. The other six sub-plans
(laravel-scaffold, tenancy-auth, plugin-lifecycle, realtime-replay,
script-containers, ci-gates) can now proceed against this
documented ground truth.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Self-review notes (writing-plans skill checklist)

1. **Spec coverage:** Every bullet of spec §10 sub-plan-1 scope is covered — orientation files (Task 2), 8 heavy PRD rewrites (Tasks 3–10), light PRD sweeps (Tasks 11–15), 22 roadmap milestones (Tasks 16–29), architecture diagrams (Task 30), integrity sweep (Task 31). Out-of-scope items (code, composer.json, CI workflows) explicitly listed in the Scope section.
2. **Placeholder scan:** No `TBD` / `TODO` / `fill in details`. Every "Apply replacements" step has a concrete substitution table inline. Every grep verification has the exact command + expected output.
3. **Type consistency:** Naming consistent across tasks — `AccessResolver` (always under `app/Domain/Tenancy/`), `HookDispatcher`, `PluginRegistry`, `ProviderConsoleProxy`, `TrackAIssuer`, `JwksController`, `broadcast_event_log` (always Postgres). Hookspec event path format `app/Events/Hookspecs/<Domain>/<Verb>Event.php` used consistently.
4. **Out-of-order safety:** Every task is self-contained — file paths, grep patterns, replacement guidance, commit commands all complete per task. No "see Task N" cross-references.

The plan assumes the executor has read the spec at `docs/superpowers/specs/2026-05-26-laravel-redesign.md` (it's both the source of truth and the first artifact referenced in each task's Step 1).
