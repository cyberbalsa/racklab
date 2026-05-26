# M4 — Console (noVNC + xterm.js)

**Status:** Not started.
**Estimated effort:** 2–3 weeks.
**Depends on:** M2 (for the empty `console-worker` Channels scaffold delivered in M0/M2), M3 (for the Proxmox client + provider plugin that issues the upstream tickets).
**Unblocks:** M7b, M9.

## Goal

Users can open a console pane to any deployment they have access to: noVNC for KVM graphical consoles, xterm.js for LXC and serial consoles. Both paths share the `ConsoleAccessGrant` flow (short-lived, RBAC-scoped, audit-logged). The console pane chrome (markdown instructions above/beside/below the terminal, keyboard focus-release shortcut, screen-reader announcements) is the canonical layout the SSH plugin reuses in M9.

## In scope

- PRD §12 Proxmox provider "Console" section (the v1 console backend, both noVNC and xterm.js paths).
- PRD §15 UI/UX (the console-pane layout, accessibility shortcuts, share controls).
- PRD §18 security (console proxy must not grant provider-console access beyond RackLab authorization).
- The `racklab-console-proxmox` plugin per PRD §13.
- The `ConsoleAccessGrant` data flow from the Proxmox client spec §7.

## Dependencies

- M3 Proxmox client + provider plugin (the source of the underlying VNC/term tickets).
- M2 `console-worker` pool scaffolded; M4 actually exercises it as a Channels app for the WebSocket proxy.
- M0 Channels in the baseline stack.

## Deliverables

- `racklab-console-proxmox` plugin: registers capability `console:proxmox:v1`, declares the `ConsoleSession` `Job` subtype for Proxmox console kinds, implements the `racklab_console_*` hookspec.
- `console-worker` pool gains Channels consumers wired in: one for the noVNC websocket proxy, one for the xterm.js terminal websocket proxy. Both proxy Proxmox's `vncwebsocket` / `termproxy` endpoints.
- The `ConsoleAccessGrant` token model: short-lived JWT (5-minute default TTL, configurable), scoped to a single deployment + a single console kind, signed by the RackLab signing key per PRD §06. Revocation via `jti`.
- Deployment-detail page in the UI gains a console pane:
  - **noVNC 1.7.0** (current upstream release) for KVM via the vendored noVNC vanilla JS library, pinned with SRI hash.
  - **xterm.js 6.x** (package `@xterm/xterm`, not the legacy `xterm` package — xterm.js migrated to the `@xterm/*` scope) via `@xterm/addon-attach` for LXC and serial console, pinned with SRI hash.
  - The chrome described in PRD §15: markdown instruction panels above/beside/below, focus-release keyboard shortcut (default `Ctrl-Alt-Shift-Q`), screen-reader announcement of session state changes, share controls.
- DRF endpoint `/api/v1/deployments/{id}/console-grant` issues a `ConsoleAccessGrant` token for the requested console kind, after RBAC check + capability flag check.
- Console-session audit emission per PRD §14: session start with actor + target + scope + kind, session end with start+end timestamps + byte counts.

## Acceptance criteria

- [ ] A user with `deployment.console` permission opens the deployment-detail page and sees a console pane; clicking "Connect" issues a `ConsoleAccessGrant` and opens the appropriate renderer.
- [ ] noVNC connects to a KVM VM running on Proxmox and displays the graphical console; mouse and keyboard input work; the focus-release shortcut releases keyboard focus from the console back to the page.
- [ ] xterm.js connects to an LXC container and displays the terminal; keyboard input works; serial-console fallback works when invoked on a KVM VM that doesn't have a graphical console.
- [ ] A user without `deployment.console` permission gets a 403 from the console-grant endpoint with an audit-logged denial.
- [ ] A `ConsoleAccessGrant` expires; the WebSocket connection terminates; reconnecting requires a fresh grant.
- [ ] A grant revoked by `jti` invalidates within one request cycle even if the token's `exp` has not yet passed.
- [ ] Screen-reader testing (manual NVDA + VoiceOver pass) confirms: the console pane has a proper landmark, the connect button has an accessible name, session-state changes are announced via an ARIA live region, the focus-release shortcut is documented in the page.
- [ ] axe-core finds no new violations on the deployment-detail page with the console pane open.

## Test layers

- **Tiny / unit**: `ConsoleAccessGrant` JWT issuance + validation; protocol-enum dispatch (`vnc` vs `terminal`); focus-release keyboard handler logic.
- **Contract**: the `ConsoleBackend` Protocol against the Proxmox concrete implementation; the WebSocket consumer against a fake upstream Proxmox WebSocket; RBAC predicate on the grant endpoint.
- **Integration**: full grant → connect → proxy → disconnect against testcontainers Postgres + the Proxmox API mock with WebSocket support; revocation by `jti` mid-session.
- **E2E**: the M2/M3 E2E flow gains a console step — user opens the deployment, clicks Connect on the console pane, sees the console render, releases focus with the keyboard shortcut, releases the deployment. axe-core on every page; manual screen-reader pass once before promotion.

## Risks / open questions

- **vendoring for `@novnc/novnc` and `@xterm/xterm`**: PRD §15 (post-React-pivot) keeps both as vanilla JS dependencies mounted inside React components via `useRef` + `useEffect`. They install via npm into the Vite build and end up vendored in the bundle anyway — no CDN. The remaining choice is just version-pin policy + Renovate cadence. Note the `@xterm/xterm` package rename from the legacy `xterm` package; pin to a known-good 6.x patch level.
- **Proxmox VNC ticket TTL**: Proxmox VNC tickets expire on their own schedule (~30 minutes). RackLab's `ConsoleAccessGrant` TTL must be ≤ Proxmox's, or proxy connections break mid-session. The grant TTL is configurable but documented as "limited by upstream ticket TTL."
- **Focus-release shortcut conflict**: the default `Ctrl-Alt-Shift-Q` may conflict with a user's OS or browser shortcuts. Make it configurable per-user.
- **LXC vs KVM detection**: the provider plugin reports kind per VM; the console pane picks the right renderer. What if a future provider reports both? The capability flag is `console:vnc` or `console:terminal`; pick the user's preference if both are available.
- **SPICE deprioritized but not entirely dead**: PRD §12 deprioritizes SPICE since Proxmox is moving away and KubeVirt dropped it. M4 does not implement SPICE; the spec records this. If a deployment actually needs SPICE, a separate plugin can ship later.

## Out of scope (deferred)

- SSH console (browser SSH terminal for VMs that are reachable) — M9 (the `racklab-console-ssh` plugin).
- Session recording — comes in M9 with the SSH plugin (asciinema cast format); KVM noVNC and LXC xterm.js recording is a v1.1 feature, not M4.
- Console sharing via guest-link beyond what the share-link primitive already supports — works in M4 because of M1, no new feature.
- Console automation (openQA-style) — M7b with the script runner plugin family.
