# M4 — Console (noVNC + xterm.js)

**Status:** Not started.
**Estimated effort:** 2–3 weeks.
**Depends on:** M2 (for the Horizon worker + Reverb + container-job scaffold), M3 (for the Proxmox client + provider plugin that issues the upstream tickets).
**Unblocks:** M7b, M9.

## Goal

Users can open a console pane to any deployment they have access to: noVNC for KVM graphical consoles, xterm.js for LXC and serial consoles. Both paths share the `ConsoleAccessGrant` flow (short-lived Track A JWT, RBAC-scoped, audit-logged). The browser **never holds Proxmox API credentials**; the `ProviderConsoleProxy` unix-socket service (running in the Horizon worker) is the sole holder of provider creds. For **browser-interactive** console use, the Horizon worker itself holds and uses the `ConsoleAccessGrant` to proxy the noVNC/xterm session; the `racklab/console-script:v1` container kind is the per-job ephemeral path for **scripted** console automation (lands in M7b) and is a separate concern. The console pane chrome (markdown instructions above/beside/below the terminal, keyboard focus-release shortcut, screen-reader announcements) is the canonical layout the SSH plugin reuses in M9.

## In scope

- PRD §12 Proxmox provider "Console" section (the v1 console backend, both noVNC and xterm.js paths).
- PRD §15 UI/UX (the console-pane layout, accessibility shortcuts, share controls).
- PRD §18 security (console proxy must not grant provider-console access beyond RackLab authorization; no Proxmox credentials in the container, per PRD §18:37).
- The `racklab-console-proxmox` plugin per PRD §13.
- The `ConsoleAccessGrant` data flow from the Proxmox client spec §7, ported to the PHP/Laravel stack.

## Dependencies

- M3 Proxmox client + provider plugin (the source of the underlying VNC/term tickets).
- M2 Horizon worker infrastructure; M4 wires in the `ProviderConsoleProxy` service as a long-lived process alongside the provider worker.
- The `racklab/console-script:v1` container kind (for scripted console automation) registers via the `KindResolving` contributor hookspec in M7b. M4 does not depend on it — browser-interactive console in M4 uses the Horizon worker process directly, not a per-job container.

## Deliverables

- `racklab-console-proxmox` plugin (`packages/racklab/console-proxmox`): declares capability `console:proxmox:v1`, declares the `ConsoleSession` `Job` subtype for Proxmox console kinds, implements the `app/Events/Hookspecs/Console/` hookspec events.
- **`ProviderConsoleProxy`** — a localhost unix-socket service running inside the Horizon worker process:
  - Holds the Proxmox API credentials (the *only* process that does, per PRD §18:37).
  - Accepts incoming requests from `racklab/console-script:v1` containers (bound-mounted `/run/racklab/console-proxy.sock`).
  - Authenticates each request by verifying the container's narrow-scope Track A JWT (scoped to a single `(tenant, deployment_resource, op_set, expiry)` tuple) against the JWKS.
  - Makes the actual Proxmox API call (`sendkey`, `vncproxy`, `vncwebsocket`, `termproxy`) on the container's behalf.
  - Proxies the noVNC WebSocket to the browser via the app/Caddy WebSocket proxy path (not Reverb — Reverb is for events only; noVNC uses a direct WebSocket connection proxied through the Horizon worker).
  - Never lets containers reach Proxmox directly; container network policy is `via-console-proxy` (not egress).
- `racklab/console-script:v1` container kind: registered via `KindResolving` contributor; carries only the narrow Track A JWT; communicates exclusively through the bind-mounted `console-proxy.sock`.
- The `ConsoleAccessGrant` token model: short-lived Track A JWT (5-minute default TTL, configurable), RS256, scoped to a single deployment + a single console kind, issued by `App\Auth\Jwt\TrackAIssuer`, revocable via `jti`.
- Deployment-detail page in the UI gains a console pane implemented as a **Livewire 4 component**:
  - **noVNC 1.7.0** for KVM: the `@novnc/novnc@1.7.0` (MPL-2.0) vanilla JS library mounted via `wire:ignore` + `@push('scripts')` in the Livewire component. No React. Pinned with SRI hash.
  - **xterm.js 6.x** for LXC and serial console: `@xterm/xterm@6.0.0` + `@xterm/addon-attach` mounted via `wire:ignore` + `@push('scripts')`. Pinned with SRI hash. Echo subscribes to `private-tenant.{tid}.console.{cid}` and feeds chunks to `xterm.write()` / noVNC `RFB` decoder in order.
  - The chrome described in PRD §15: markdown instruction panels above/beside/below, focus-release keyboard shortcut (default `Ctrl-Alt-Shift-Q`), screen-reader announcement of session state changes via ARIA live region, share controls.
- Laravel controller endpoint `/api/v1/deployments/{id}/console-grant`: issues a `ConsoleAccessGrant` Track A JWT for the requested console kind, after RBAC check (`deployment.console` permission) + capability flag check. Documented via Scribe.
- Console-session audit emission per PRD §14: session start with actor + target + scope + kind, session end with start+end timestamps + byte counts.

## Acceptance criteria

- [ ] A user with `deployment.console` permission opens the deployment-detail page and sees a console pane; clicking "Connect" issues a `ConsoleAccessGrant` Track A JWT and opens the appropriate renderer (noVNC or xterm.js).
- [ ] noVNC connects to a KVM VM running on Proxmox and displays the graphical console; mouse and keyboard input work; the focus-release shortcut releases keyboard focus from the console back to the page.
- [ ] xterm.js connects to an LXC container and displays the terminal; keyboard input works; serial-console fallback works when invoked on a KVM VM that doesn't have a graphical console.
- [ ] **Browser never holds Proxmox credentials**: the `racklab/console-script:v1` container carries only a narrow Track A JWT; Proxmox API credentials never leave the `ProviderConsoleProxy` process.
- [ ] A user without `deployment.console` permission gets a 403 from the console-grant endpoint with an audit-logged denial.
- [ ] A `ConsoleAccessGrant` expires; the console-proxy rejects further requests; reconnecting requires a fresh grant.
- [ ] A grant revoked by `jti` invalidates within one request cycle even if the token's `exp` has not yet passed.
- [ ] Screen-reader testing (manual NVDA + VoiceOver pass) confirms: the console pane has a proper landmark, the connect button has an accessible name, session-state changes are announced via an ARIA live region, the focus-release shortcut is documented in the page.
- [ ] axe-core (run in Dusk Browser tests) finds no new violations on the deployment-detail page with the console pane open.

## Test layers

- **Tiny / unit** (Pest 4): `ConsoleAccessGrant` Track A JWT issuance + validation; protocol-enum dispatch (`vnc` vs `terminal`); focus-release keyboard handler logic; `ProviderConsoleProxy` JWT verification + request forwarding logic (pure-PHP unit, no socket I/O).
- **Contract** (Pest 4 + `Http::fake()`): the `ConsoleBackend` contract against the Proxmox concrete implementation; the `ProviderConsoleProxy` against a fake upstream Proxmox WebSocket stub; RBAC predicate on the grant endpoint; `jti` revocation logic.
- **Integration** (Pest 4 + Testcontainers): full grant → `console-script:v1` container → `ProviderConsoleProxy` → Proxmox mock WebSocket → browser disconnect flow, against testcontainers Postgres + Redis + Podman socket. Revocation by `jti` mid-session. `ContainerEgressDeniedTest` verifies `console-script:v1` cannot reach Proxmox directly (container network policy `via-console-proxy`; test exec's `curl` in the container and asserts failure).
- **Browser E2E** (Dusk + axe-core): the M2/M3 E2E flow gains a console step — user opens the deployment, clicks Connect on the console pane, sees the console render, releases focus with the keyboard shortcut, releases the deployment. axe-core on every page; manual screen-reader pass once before promotion.

## Risks / open questions

- **Track A JWT TTL vs Proxmox VNC ticket TTL**: Proxmox VNC tickets expire on their own schedule (~30 minutes). The `ConsoleAccessGrant` TTL must be ≤ Proxmox's, or `ProviderConsoleProxy` connections break mid-session. The grant TTL is configurable but documented as "limited by upstream ticket TTL."
- **Focus-release shortcut conflict**: the default `Ctrl-Alt-Shift-Q` may conflict with a user's OS or browser shortcuts. Make it configurable per-user.
- **LXC vs KVM detection**: the provider plugin reports console kind per instance; the console pane picks the right renderer. If a future provider reports both, the capability flag is `console:vnc` or `console:terminal`; pick the user's preference if both are available.
- **Octane state-leak for `ProviderConsoleProxy`**: the proxy holds a Guzzle client with Proxmox credentials. The Octane state-reset discipline from the Laravel redesign spec §5 applies: the proxy must not carry a prior request's credentials into a new Octane worker cycle.
- **SPICE deprioritized**: PRD §12 deprioritizes SPICE since Proxmox is moving away. M4 does not implement SPICE; the spec records this. If a deployment actually needs SPICE, a separate plugin can ship later.

## Out of scope (deferred)

- SSH console (browser SSH terminal for VMs that are reachable) — M9 (the `racklab-console-ssh` plugin).
- Session recording — comes in M9 with the SSH plugin (asciinema cast format); KVM noVNC and LXC xterm.js recording is a v1.1 feature, not M4.
- Console sharing via guest-link beyond what the share-link primitive already supports — works in M4 because of M1, no new feature.
- Console automation (openQA-style) — M7b with the script runner plugin family.
