# M4 — Console (noVNC + xterm.js) implementation design

**Date:** 2026-05-28
**Status:** Draft — awaiting user review (autonomous /loop iteration #1 of M4)
**Authors:** Forrest Fuqua + Claude (auto-agent)
**Roadmap:** [`docs/roadmap/M04-console-proxmox.md`](../../roadmap/M04-console-proxmox.md)
**Estimated effort:** 2–3 weeks total, split across ~6 commits / loop iterations.

## Why now

The blocker-audit slice (2026-05-28) cleared the path: Horizon is wired, supply chain hardened, runner registration ready. M4 is the next un-shipped roadmap milestone in dependency order (M2 + M3 are shipped; M5/M6/M7 already shipped; M4 was deferred because it pulls in browser-side noVNC/xterm wiring). It unblocks M9 (SSH plugin reuses the same console pane) and M7b (script automation via `console-script` containers shares the `ProviderConsoleProxy` socket).

## Goal (load-bearing)

A user with `deployment.console` permission can open a console pane on a deployment-detail page. Clicking *Connect* issues a `ConsoleAccessGrant` (short-lived Track A JWT, 5-minute default TTL, scoped to one deployment + one console kind) and:

- For KVM VMs: opens noVNC 1.7.0 mounted via `wire:ignore` in a Livewire 4 component, with the graphical console rendered in-page.
- For LXC containers + KVM serial consoles: opens xterm.js 6.x mounted via `wire:ignore`, with the terminal feed rendered in-page.

**Browser never holds Proxmox credentials.** The `ProviderConsoleProxy` localhost unix-socket service inside the Horizon `runner` worker pool is the only RackLab-side holder of Proxmox API credentials. Browser→proxy traffic carries the narrow Track A JWT; proxy→Proxmox traffic carries the API credentials the proxy alone holds.

## Stack at a glance

| Slot | Pick | Notes |
|------|------|-------|
| Token | `App\Auth\Jwt\TrackAIssuer` (existing) wrapped by new `ConsoleAccessGrantIssuer` | composition over extension; new issuer carries deployment + console-kind scope |
| Token TTL | 5 minutes default, ≤ Proxmox VNC ticket TTL (~30 min) | configurable via `racklab.console.grant_ttl_seconds` |
| Proxy | `App\Console\ProviderConsoleProxy` long-running service inside the Horizon runner pool | localhost unix socket; holds Proxmox creds; only sanctioned process holding them |
| Container kind | `racklab/console-script:v1` | per-job ephemeral, network `via-console-proxy`, mounts `console-proxy.sock` read-only |
| Browser — KVM | `@novnc/novnc@1.7.0` (MPL-2.0, already in package.json) | mounted via `wire:ignore` + `@push('scripts')`; SRI pinned |
| Browser — LXC + serial | `@xterm/xterm@6.0.0` + `@xterm/addon-attach` (already in package.json) | same mount pattern |
| Vanilla JS islands | `resources/js/islands/novnc-viewer.ts` + `xterm-console.ts` (shared with M9 SSH plugin) | TS strict; ESLint passes |
| Permission | new `deployment.console.connect` — sub-permission of existing `deployment.console` | granular: see + connect separated |
| Audit | `console.session.start` + `console.session.end` events | hash-chained per existing AuditEventWriter |
| Provider plugin | new `packages/racklab/console-proxmox/` | declares capability `console:proxmox:v1`; ships in monorepo |

## Sub-slices (one per loop iteration)

Each sub-slice is independently testable, mergeable, and gates green before moving on. TDD per PRD §17.

### Sub-slice 1: `ConsoleAccessGrant` token model (no UI, no Proxmox)

**Goal:** Pure backend — issue + verify a JWT scoped to `(tenant, deployment_id, console_kind, expiry)`.

**Files:**
- Create: `app/Domain/Console/ConsoleAccessGrant.php` (readonly DTO — grant_id, tenant_id, deployment_id, console_kind, expires_at, jti)
- Create: `app/Domain/Console/ConsoleKind.php` (enum: `Vnc`, `Terminal`)
- Create: `app/Auth/Jwt/ConsoleAccessGrantIssuer.php` — wraps `TrackAIssuer`; injects the console-scope claims
- Create: `app/Auth/Jwt/ConsoleAccessGrantVerifier.php` — wraps `TrackAJwtVerifier`; checks the console-scope claims
- Modify: `app/Auth/Jwt/TrackAJwtClaims.php` (or sibling) — add `console_kind` + `deployment_id` claim names if not present
- Permission catalog: add `deployment.console.connect` to admin + support + instructor (instructors need console for student VMs). Snapshot updated.

**Tests (Tiny):**
- `tests/Tiny/Console/ConsoleAccessGrantIssuerTest.php` — issues a grant for a deployment, asserts JWT claims include `tenant_id`, `deployment_id`, `console_kind`, `jti`, `exp` ≤ 5 min in the future.
- `tests/Tiny/Console/ConsoleAccessGrantVerifierTest.php` — round-trip; fails on expired JWT; fails on wrong-issuer; fails on wrong-tenant; fails on revoked `jti`.
- `tests/Tiny/Console/ConsoleKindEnumTest.php` — `vnc` ↔ `Vnc` mapping; case-insensitive parse rejects unknown values.

**Tests (Contract):**
- `tests/Contract/Console/ConsoleAccessGrantPermissionTest.php` — issuer rejects when actor lacks `deployment.console.connect`; emits `console.access.denied` audit.

**Snapshots:** roles.json + audit-events.json gain the new entries.

**Codex review** the spec for this sub-slice before commit.

### Sub-slice 2: `POST /api/v1/deployments/{deployment}/console-grant` endpoint

**Goal:** API endpoint that returns a `ConsoleAccessGrant` for the requested console kind.

**Files:**
- Create: `app/Http/Controllers/Api/DeploymentConsoleGrantController.php` — POST endpoint
- Create: `app/Http/Requests/DeploymentConsoleGrantRequest.php` — validates `console_kind` (`vnc`|`terminal`)
- Modify: `routes/api.php` — register the route under `auth:sanctum`
- Audit: emits `console.session.start` with actor + deployment + console_kind + grant_id

**Tests (Contract):**
- Anonymous → 401
- Authed without `deployment.console.connect` → 403 + `console.access.denied` audit
- Authed with permission → 200 with JWT in body + `console.session.start` audit
- Wrong tenant deployment → 404 (AccessResolver tenant gate)
- Unknown console_kind → 422

**Scribe OpenAPI** drift gate must pass; new endpoint added to `docs/api/openapi.yaml`.

### Sub-slice 3: `ProviderConsoleProxy` interface + fake (no real Proxmox WebSocket yet)

**Goal:** Establish the seam. Real Proxmox WebSocket wiring lands in sub-slice 5.

**Files:**
- Create: `app/Console/ProviderConsoleProxy.php` (the contract)
- Create: `app/Console/InMemoryProviderConsoleProxy.php` — fake for tests + dev
- Create: `app/Providers/Proxmox/ProxmoxConsoleProxy.php` — Proxmox impl skeleton; real WebSocket forwarder added in sub-slice 5
- Container binding: `AppServiceProvider` binds `ProviderConsoleProxy` to `InMemoryProviderConsoleProxy` by default; switches to `ProxmoxConsoleProxy` when `RACKLAB_CONSOLE_PROXY=proxmox`.

**Tests (Tiny + Contract):**
- Interface contract tests: `requestVncTicket(grant, deployment)` returns a ticket; `requestTermProxy(grant, deployment)` returns a terminal-feed handle; both reject on expired grants; both audit `console.proxy.request`.
- Fake-impl tests: deterministic ticket strings; reject when grant doesn't match cached state.

### Sub-slice 4: noVNC + xterm.js Livewire component (UI only, no live connection)

**Goal:** Build the console pane chrome from PRD §15: markdown panels, focus-release shortcut (default `Ctrl-Alt-Shift-Q`), screen-reader-friendly ARIA live region, share controls. Renders the noVNC/xterm container divs with `wire:ignore`.

**Files:**
- Create: `app/Livewire/Console/DeploymentConsolePane.php` — Livewire component
- Create: `resources/views/livewire/console/deployment-console-pane.blade.php`
- Create: `resources/js/islands/novnc-viewer.ts` (TS strict)
- Create: `resources/js/islands/xterm-console.ts` (already partially referenced in PRD §15; create stub)
- Modify: `vite.config.ts` — add the two island entries

**Tests (Tiny + Contract):**
- Component test: renders for an authorized user; hidden for unauthorized; capability flag drives noVNC vs xterm.

**Tests (Browser — deferred to sub-slice 6):**
- Dusk drives Connect → grant → pane render. axe-core clean.

### Sub-slice 5: Real Proxmox WebSocket forwarder + `racklab-console-proxmox` plugin

**Goal:** Wire `ProxmoxConsoleProxy` to issue real `vncproxy`/`termproxy` calls and bridge the WebSocket. Package the plugin.

**Files:**
- Modify: `app/Providers/Proxmox/GuzzleProxmoxClient.php` — add `vncproxy()`, `termproxy()`, `vncwebsocket()` methods; verified against the existing typed Proxmox client discipline.
- Modify: `app/Providers/Proxmox/ProxmoxConsoleProxy.php` — real impl using the new client methods.
- Create: `packages/racklab/console-proxmox/composer.json` + `src/`
- Wire: `app/Providers/Proxmox/Models/ProxmoxVncTicket.php`, `ProxmoxTermProxyTicket.php` — typed DTOs.

**Tests (Contract):**
- HTTP::fake → assert correct Proxmox API calls + headers.
- Plugin lifecycle test: install / enable / disable via existing `racklab plugin` Artisan commands.

**Tests (Integration):**
- Optional: Testcontainers Proxmox mock; skip when unavailable.

### Sub-slice 6: End-to-end browser path + audit hardening

**Goal:** Ship the end-to-end path.

**Files:**
- Browser: `tests/Browser/DeploymentConsoleWorkflowTest.php` — Dusk driving Connect → grant → connected pane → focus-release.
- Modify: deployment-detail dashboard template — wires the console pane in.
- Audit: emits `console.session.end` with session duration + byte counts on disconnect.

**Tests:**
- Dusk + axe-core; the M2/M3 E2E flow gains a console step.

## Risks (carried from M4 roadmap doc, my reading)

1. **Track A JWT TTL vs Proxmox VNC ticket TTL** — RackLab 5 min default ≤ Proxmox ~30 min. Documented.
2. **Focus-release `Ctrl-Alt-Shift-Q` may conflict** with OS shortcuts. Make configurable per-user in a follow-up; M4 ships the default.
3. **Octane state-leak for `ProviderConsoleProxy`** — runs in Horizon workers (long-lived), NOT Octane request workers; risk only if creds leak across job invocations. `BindTenantContext` Spatie fix from the Horizon slice already addresses the tenant side; ProxmoxConsoleProxy must clear cached Guzzle clients between job invocations.
4. **SPICE deferred** — separate plugin if needed.

## Out of scope (deferred)

- SSH browser console (M9).
- Session recording (asciinema; M9).
- Console sharing via guest-link beyond what share-link primitive already supports.
- Console automation (openQA-style) — M7b script runner family.

## Rollout (across loop iterations)

| Iter | Sub-slice | Estimated lines | Codex round |
|------|-----------|-----------------|-------------|
| 1 (this) | Design spec + commit | this file | spec self-review only |
| 2 | Sub-slice 1: token model + Tiny/Contract tests | ~400 LOC | spec round if substantive |
| 3 | Sub-slice 2: API endpoint + Contract tests | ~250 LOC | post-implementation |
| 4 | Sub-slice 3: Proxy seam + fakes | ~350 LOC | post-implementation |
| 5 | Sub-slice 4: Livewire component + JS islands | ~500 LOC | post-implementation |
| 6 | Sub-slice 5: Real Proxmox + plugin packaging | ~400 LOC | post-implementation + codex |
| 7 | Sub-slice 6: Browser E2E + audit hardening | ~250 LOC | branch-level codex |

Each iteration commits a single Conventional Commit prefixed `feat(console):`, `feat(api):`, etc.

## Acceptance criteria mapping (from roadmap)

| Roadmap acceptance | Sub-slice |
|--------------------|-----------|
| Issue `ConsoleAccessGrant` Track A JWT | 1 |
| noVNC connects to a KVM | 5 + 6 |
| xterm.js connects to LXC + serial fallback | 5 + 6 |
| Browser never holds Proxmox credentials | 3 + 5 |
| 403 + audit on missing permission | 2 |
| Grant expiry rejects further use | 1 + 3 |
| `jti` revocation invalidates within 1 request | 1 (existing TrackAJwtRevoker) |
| Screen-reader announcements | 4 + 6 |
| axe-core clean on console pane | 6 |

## References

- M4 roadmap: `docs/roadmap/M04-console-proxmox.md`
- PRD §12 Proxmox provider: `docs/prd/12-proxmox-provider.md:67`
- PRD §15 UI/UX (console chrome + accessibility): `docs/prd/15-ui-ux.md`
- PRD §06 auth/tokens: `docs/prd/06-auth-rbac-sharing-tokens.md:155` (Track A JWT for console)
- Existing `TrackAIssuer`: `app/Auth/Jwt/TrackAIssuer.php`
- Horizon slice spec (sets the precedent for the codex-iterative pattern): `docs/superpowers/specs/2026-05-28-horizon-and-supply-chain-design.md`
