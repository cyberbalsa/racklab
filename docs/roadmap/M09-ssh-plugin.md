# M9 — SSH Plugin

**Status:** Not started.
**Estimated effort:** 3–4 weeks.
**Depends on:** M4 (xterm.js + console pane chrome), **M5a** (`NetworkOffering.reachability` capability + NIC attach), **M7a** (cloud-init host-key phone-home + ProjectSSHKey injection + gateway-service-key injection delivered as part of the cloud-init script plugin).

## Pinned versions

- `phpseclib/phpseclib` 3.x (Composer; PHP ≥8.2; MIT). Covers SSH2 client with key auth, password auth, keyboard-interactive, and host-key pinning.
- `@xterm/xterm` 6.x and `@xterm/addon-attach` (shared vendored from M4 as a vanilla JS island; no React dependency).

## Goal

The `racklab-console-ssh` plugin lands: a browser SSH terminal to any deployment whose network offering makes SSH reachable from the management plane. The console-worker container is the gateway; RackLab brokers browser SSH sessions using a deployment-scoped gateway service key injected via cloud-init. ProjectSSHKey public keys support direct user SSH from external clients when networks allow it. Sessions optionally record to asciinema v2 with pattern-based redaction and abort-on-failure. Host-key verification uses TOFU + cloud-init phone-home capture (no MITM exposure).

## In scope

- PRD §23 SSH plugin — every section.
- PRD §19 `ConsoleSession` SSH subtype and `SSHCredentialBinding`; consumes core `ProjectSSHKey`.
- The integration with `NetworkOffering.reachability` from M5a.
- The integration with the cloud-init script plugin from M7a.

## Dependencies

- M4 console-worker pool + Laravel Reverb + xterm.js + `ConsoleAccessGrant` flow.
- M5a `NetworkOffering.reachability` — the SSH plugin defers to this to decide whether to offer SSH on a deployment.
- M7a cloud-init script plugin — for host-key phone-home capture, ProjectSSHKey injection, and gateway-service-key injection.
- M0 universal `Artifact` model — for asciinema recordings.

## Deliverables

- `racklab-console-ssh` plugin package on Packagist (`packages/racklab/ssh-plugin/`): registers a Laravel ServiceProvider, capability `console:ssh:v1`, contributes Eloquent models (`ConsoleSession` SSH subtype + `SSHCredentialBinding`) and Laravel migrations, consumes the core `ProjectSSHKey` model, declares the console SSH permissions, registers translation catalogs.
- Laravel Reverb WebSocket handler in the `console-worker` Horizon pool: terminates the browser WebSocket, validates the `ConsoleAccessGrant`, reads `DeploymentResource.reachability` + the resolved target address (guest IP or NAT gateway port-forward), opens an outbound `phpseclib/phpseclib` SSH connection from the Horizon worker.
- Host-key verification implementation:
  - Cloud-init phone-home flow added to the `racklab-script-cloudinit` plugin in M7a: VM reports its `/etc/ssh/ssh_host_*.pub` fingerprints to a RackLab callback endpoint at boot time. Fingerprints are persisted on the `DeploymentResource` row.
  - `phpseclib` is initialized with a host-key validation callback that checks against the pinned key set; any mismatch aborts the connection, the `console.ssh.host_key_mismatch` audit event fires, admin alert.
  - Admin-approved "re-capture host key" flow for legitimate rotation (snapshot restore, manual rekey).
  - Cloud-init-less deployments are flagged `requires_host_key_capture`; SSH is refused until manual capture.
- Credential model implementation (v1 paths only per the codex-corrected spec):
  - ProjectSSHKey public-key management for direct user SSH: project-scoped keys list creator, store public material only, and can be selected for cloud-init `authorized_keys` injection.
  - Browser SSH default: RackLab gateway service key via cloud-init (catalog template hook contributed by the SSH plugin to render the gateway-key injection; key generated per-deployment, Ed25519, encrypted at rest in the secret backend, never leaves console-worker).
  - Password passthrough (catalog-gated, recording forced off, prompted in-browser, never persisted).
  - User private-key upload/proxying explicitly NOT in v1; user-attributed browser SSH is deferred to the SSH-CA path in v1.1.
- Session recording implementation:
  - Default OFF.
  - `recording_policy: optional | required | forbidden` field on the catalog template.
  - Password-passthrough: recording forbidden, enforced.
  - Pattern-based redaction pipeline with `[REDACTED]` substitution; default patterns ship with the plugin; per-catalog extensible.
  - Abort-on-redaction-failure: malformed/unframed sequences that defeat pattern matching abort the recording but keep the session live.
  - Recordings land in `Artifact(kind=console_recording)` with the `recording_with_consent` legal-flag set.
  - Replay UI in the deployment-detail page, RBAC-gated by `deployment.console.replay`; surfaces `[REDACTED]` markers visibly.
- Reachability-aware grant issuance: SSH grants are refused for deployments on `isolated_no_ingress` network offerings; the deployment-detail page greys out the SSH button for those.

## Acceptance criteria

- [ ] A student creates a ProjectSSHKey, deploys a VM from a Stack using a `routable_from_management` network offering, and RackLab injects the selected public key into the guest with creator attribution visible in the Project SSH Keys list.
- [ ] The same student clicks "SSH" on the deployment-detail page; an xterm.js session opens directly to the VM's guest IP using the cloud-init-injected gateway service key; the user types and the terminal responds.
- [ ] A student deploys a VM from a Stack using a `nat_from_management` offering; clicks "SSH"; the session opens to the network's NAT gateway port-forward; same UX.
- [ ] A student deploys a VM from a Stack using an `isolated_no_ingress` offering; the SSH button is greyed out with a "SSH not available for this network" tooltip; attempting to issue a grant via the API returns a 422 with a clear reason.
- [ ] Host-key MITM test: a test that swaps the target VM's host key mid-deployment fires `console.ssh.host_key_mismatch`, terminates the session, and prevents reconnect until the admin re-captures.
- [ ] Recording-redaction test: a script in the recorded session emits a stream containing a base64-encoded JWT-shaped string broken across chunks in a way that defeats the redactor; the redactor aborts the recording, the session stays alive, the abort is audit-logged.
- [ ] Password-passthrough session: catalog enables it; user enters password in browser; session opens; recording is refused even if the catalog template tries to enable it (defensive enforcement).
- [ ] axe-core finds no new violations on the deployment-detail page with the SSH pane; screen-reader testing confirms the SSH session announces state changes.

## Test layers

- **Tiny / unit**: `[[kind:id]]`-style cross-link doesn't apply here; the unit tests focus on the redaction pattern matcher, the SSH credential resolution flow (which credential mode applies given catalog + RBAC + reachability), and the host-key pinning + comparison logic.
- **Contract**: `phpseclib`-backed Reverb WebSocket handler against a fake SSH target; redaction pipeline with malicious byte sequences; reachability-aware grant Protocol.
- **Integration**: cloud-init phone-home host-key capture round-trip; full grant → connect → use → record → end against Testcontainers (PHP binding) + a real OpenSSH daemon; deliberate MITM scenario aborts cleanly; redaction-defeating-byte-sequence aborts recording but not session.
- **E2E**: student deploys, SSHes, runs commands, disconnects, replays the (redacted) recording; admin re-captures a host key after a deliberate rotation.

## Risks / open questions

- **Cloud-init phone-home callback endpoint**: needs to be reachable from inside tenant networks at boot time. For `routable_from_management` and `nat_from_management` this is fine; for newly-provisioned VMs whose network is just coming up, the cloud-init-host-key-capture step happens after network configuration. Test the timing.
- **Cloud-init-less images**: PRD §23 already documents the `requires_host_key_capture` admin flow. Make sure the UX is friendly — admins shouldn't have to dig into shell to provide a fingerprint.
- **`phpseclib` keepalive vs browser tab backgrounding**: keepalive interval is set lower than typical browser tab-suspend behavior per the spec. Verify timing in real browser testing.
- **Recording-redaction false negatives**: pattern matching is best-effort. Document explicitly that recordings are NOT a substitute for not pasting secrets; user-side consent prompt reinforces this.
- **Laravel Reverb under sustained binary WebSocket streams** (PRD §23 v1 verification list): benchmark with a 50-concurrent-session load test before promoting M9.
- **SSH-CA design for v1.1**: the durable answer for user-attributed browser SSH. Start the design spec for this during M9 implementation so it's ready when M9 ships.

## Out of scope (deferred)

- Uploading or proxying user private keys — rejected. User-attributed browser SSH uses the v1.1 SSH-CA design.
- SFTP / file transfer — v1.1.
- Multi-viewer same session — v2.
- Signed short-lived SSH user certificates (RackLab as SSH CA) — v1.1, replaces the service-key default once shipped.
- Apache Guacamole — out of scope; future `racklab-console-guacamole` plugin if RDP/VNC-via-Guacamole becomes a real ask.
- SSH to `isolated_no_ingress` deployments via jump host — catalog templates can include a jump host as part of the stack; M9 supports SSHing to the jump host, but not to isolated VMs directly.
