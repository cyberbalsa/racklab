# SSH Plugin

The `racklab-console-ssh` plugin gives users a browser-based SSH terminal to lab VMs. RackLab acts as the SSH gateway from the management plane; the browser connects to RackLab over WebSocket, and RackLab opens an outbound SSH session to the target VM.

It is the SSH parallel of the `racklab-console-proxmox` plugin: same xterm.js front-end, same `ConsoleAccessGrant` flow, same audit and recording shape, same lifetime inside the existing `console-worker` pool. SSH is its own plugin because the credential and reachability models are different from Proxmox's `termproxy` / `vncproxy` ticket flow.

## Goals

- A browser SSH terminal for any deployment a user can already access in the RackLab UI, where the deployment's network offering makes SSH reachable from the management plane.
- Single source of truth for who SSHed to what and when, via RackLab audit.
- Server-side host-key verification (the user never accepts a fingerprint by hand).
- Optional, redaction-aware session recording.
- Plugin-contract conforming: a real RackLab plugin, not an iframed third-party app.

## Non-Goals

- SSH access to VMs on networks whose offering does not declare reachability from the management plane (`isolated_no_ingress` per PRD §09).
- Per-user SSH keys delivered to the proxy in v1 (see Credential model — only the RackLab-managed service key path ships in v1; per-user key delivery is the SSH-CA path in v1.1).
- SFTP / file transfer in v1.
- Multi-viewer same session.
- Replacing institutional SSH bastions at the institution level.

## Reachability — the SSH plugin defers to the network offering

The hard problem isn't "open a WebSocket-to-SSH bridge" — it's "what makes a VM reachable from the console-worker container at all." The previous draft of this plugin implicitly assumed `console-worker` could reach `private-isolated` and `private-nat` VMs, which is false without an explicit network topology.

**RackLab solves this in the networking layer, not in the SSH plugin.** PRD §09 was extended (this commit) to make every `NetworkOffering` advertise a reachability capability:

| `NetworkOffering.reachability` | SSH plugin behavior |
|---|---|
| `routable_from_management` | SSH straight to the guest IP. Default for `provider-direct` and for `private-nat` / `double-nat` offerings whose routers expose routes to the management network. |
| `nat_from_management` | SSH to the network's router at a stable port-forwarded address. The router is provisioned by the network plugin to expose the SSH port-forward; the SSH plugin connects to `<router-fip>:<port>`. |
| `isolated_no_ingress` | **SSH not offered.** The catalog UI greys out SSH for deployments using this offering. Catalog templates that need SSH against an isolated network must include an instructor-controlled jump host inside the network; the SSH plugin then targets the jump host. |

Plumbing:

- The catalog template declares which network offering each VM uses. At deploy time, RackLab resolves the resulting reachability capability per VM and persists it on the `DeploymentResource` row.
- The SSH plugin reads the persisted reachability when issuing a `ConsoleAccessGrant`. If the VM is `isolated_no_ingress`, grant issuance is refused with a clear error.
- For `nat_from_management`, the `ConsoleAccessGrant` carries the gateway address; for `routable_from_management`, it carries the guest IP.

This pushes the "how do I actually reach the VM" question onto the network layer where it belongs, and the SSH plugin becomes a thin gateway that speaks IP plus credentials. Per-tenant-network bastions are not deployed — the console-worker pool is the gateway for all tenant networks that declare reachability, and `isolated_no_ingress` deployments don't get SSH at all.

## Editor / Front-End

Browser front-end is **`@xterm/xterm`** 6.0.0 (the official package, renamed from the legacy `xterm` package) + **`@xterm/addon-attach`** for WebSocket binding + **`@xterm/addon-fit`** for terminal resize, mounted inside a React component (Mantine-styled console pane) via `useRef` + `useEffect`. Cleanup in the effect's return calls `terminal.dispose()` and closes the WebSocket. Same accessibility chrome, focus-release shortcut, and screen-reader announcements as the `racklab-console-proxmox` LXC/serial console paths. The console pane reuses the established React layout: markdown instructions panel above/beside/below the terminal per PRD §15.

The plugin ships no editor — SSH is the editor.

## Back-End

Python implementation in **Django Channels** + **asyncssh**:

- The WebSocket consumer (Channels) terminates the browser-side connection.
- It validates the `ConsoleAccessGrant` token (same primitive as Proxmox console — see Proxmox client spec §7).
- It reads the `DeploymentResource.reachability` capability and the resolved target address (guest IP or gateway port-forward) from the grant.
- It opens an outbound SSH connection from the `console-worker` Podman container using `asyncssh`.
- It pipes bytes bidirectionally between WebSocket and SSH session.
- It runs the redaction pipeline (see Session Recording below) and conditionally streams the result to artifact storage.

`asyncssh` is asyncio-native (clean fit with Channels), supports key auth + FIDO2/U2F + X.509 certs + password + keyboard-interactive, and is dual-licensed EPL-2.0 / GPL-2.0-or-later.

## Host-Key Verification

The previous draft of this spec was silent on host-key verification. Without it, an attacker who controls the network between `console-worker` and a target VM can MITM the SSH session. Required behavior:

- **TOFU + cloud-init capture is the v1 default.** When RackLab provisions a VM via cloud-init, the cloud-init `phone_home` (or a similar callback the provisioning template invokes) reports the VM's freshly-generated SSH host-key fingerprints (`/etc/ssh/ssh_host_ed25519_key.pub`, RSA, etc.) back to RackLab. The fingerprints are persisted on the `DeploymentResource` row as the pinned known-hosts entries.
- On every SSH connect, `asyncssh` is configured with `known_hosts=<the pinned set>`. Any mismatch raises `asyncssh.HostKeyNotVerifiable`, the session aborts, an audit event fires (`console.ssh.host_key_mismatch`), and the operator sees a clear "host key changed — possible MITM" alert.
- **Re-keying** (legitimate host-key rotation, e.g., after a snapshot restore) goes through an explicit admin-approved "re-capture host key" action that re-runs the cloud-init phone-home flow or accepts a manually-pasted fingerprint with audit + reason.
- **Cloud-init-less deployments** (legacy images, restored snapshots without fresh host keys): the deployment is marked `requires_host_key_capture`. SSH is refused until the operator either re-images, runs a one-time fingerprint capture via the provider's serial console, or pastes the fingerprint into the admin UI.
- v1.1: SSHFP records via DNSSEC for deployments with public DNS, and signed host certificates with RackLab as the host-CA. Documented as the durable answer; out of scope for v1.

## Credential Model

The v1 credential set is **deliberately narrow** to ship safely. The previous draft listed a per-user-key path in v1 but deferred private-key delivery; that combination wasn't implementable as v1.

### Default — RackLab service key via cloud-init

1. The catalog template includes a `racklab-script-cloudinit` rendering that injects a deployment-specific public key into a dedicated `racklab-console` user with sudoers as the template specifies.
2. The private key is generated by RackLab at deploy time (Ed25519), stored encrypted-at-rest in the secret backend (PRD §13 plugin family), and never leaves the console-worker.
3. When a user requests an SSH session, the console-worker fetches the key, opens the SSH session, and audits the bind.
4. The user never holds an SSH key. RackLab's RBAC is the trust anchor; the SSH credential is invisible to them.

This covers the educational lab default. v1 supports it.

### Last-resort — password passthrough

For legacy lab images that cannot run cloud-init:

1. The catalog template marks the deployment as `password-passthrough = true`.
2. When the user opens an SSH session, RackLab prompts for the SSH password in-browser.
3. The password is sent over the WebSocket-TLS-protected channel to the console-worker, which passes it to asyncssh's `password` auth.
4. The password is never persisted.
5. **Session recording is forced off** for password-passthrough sessions (see Session Recording).

Gated behind an explicit catalog opt-in and an admin permission. Audit emits a `console.ssh.password_used` event so this is visible operationally.

### Deferred to v1.1 — RackLab as SSH CA

The right answer for per-user SSH is **short-lived, RackLab-signed SSH user certificates**, not per-user-key upload + private-key-in-the-browser. The shape:

- RackLab runs an internal SSH CA. Its host CA + user CA public keys are distributed via cloud-init to a TrustedUserCAKeys file on each managed VM.
- A user requesting SSH receives a 5-minute SSH certificate signed by RackLab's user CA, with their identity in the principal set and the target VM(s) in the `valid-principals` extension.
- The cert never sees the browser; the console-worker holds the user's short-lived cert and uses it for the SSH session, scoped to that single target.
- No per-user private keys at rest, no need to ship keys to VMs.

This is the durable answer. It is **not** in v1 because the CA lifecycle (root rotation, revocation, distributing the CA key via cloud-init, dealing with non-cloud-init images) is a real design effort that deserves its own spec. v1.1 will replace the service-key default with the SSH CA path; v1's RackLab-managed service key is the bridge until then.

## Session Recording

The previous draft listed asciinema v2 streaming with a consent prompt. Codex flagged that recording captures typed secrets, and consent alone is insufficient. The v1 policy:

- **Default: recording OFF.** Operators opt in per deployment in the catalog template (`recording_policy: optional | required | forbidden`).
- **Password-passthrough sessions: recording forbidden, full stop.** The catalog cannot enable recording on a password-passthrough deployment. The runtime enforces this even if the catalog template tries to set both.
- **Pattern-based redaction is the v1 requirement, not a nice-to-have.** The recorder runs a redaction pipeline against the byte stream as it streams. The pipeline replaces matched substrings with `[REDACTED]` in the recorded cast. Patterns:
  - "Password:" / "password:" / "Passphrase:" prompts and the following input line.
  - "sudo" command followed by password prompt response.
  - Bearer / Token / API-Key headers and common secret-prefix patterns (AWS keys, GitHub PATs, JWT-like base64 strings).
  - Operator-configurable per-catalog additional patterns.
- **Abort-on-redaction-failure**: if the redactor encounters a malformed/unframed byte sequence that defeats pattern matching (e.g., terminal escape sequences fragment a known pattern across chunks beyond the redactor's lookahead window), the recording **aborts** for that session, an audit event fires, and the session continues live. Recording-is-best-effort is the wrong policy for an educational lab where students will paste secrets they shouldn't have. Fail closed.
- **User-side consent at session open.** Before the session starts, the browser prompts: "This session will be recorded. Recording will be redacted for common secret patterns but is not a substitute for not pasting credentials. Press Continue to start, or Cancel." Decline ends the grant without opening the SSH session.
- **Per-deployment opt-out.** Operators can mark specific deployments `recording_policy: forbidden`; the per-catalog default does not override it.
- **Retention** follows the artifact storage retention policy (PRD §14, §19). Default 90 days; configurable.
- **Replay UI** is read-only and gated by `deployment.console.replay`. Replays surface the `[REDACTED]` markers so reviewers see where redaction fired.

Recordings land in the universal `Artifact` store (PRD §19) with `kind = console_recording` and `legal_flags.recording_with_consent = true`. Replay artifact references are `ArtifactReference` rows tied to the `ConsoleSession` (which is a `Job` subtype).

## Data Model

`ConsoleSession` is a `Job` subtype (PRD §19) carrying: id (== Job.id), deployment id, actor, console kind (`ssh`), target address (resolved from network offering), reachability mode used (`routable` / `nat`), start time, end time, byte count, recording artifact reference (nullable), redaction events count, drain reason. Audited at create and at close.

`SSHCredentialBinding` rows store, per deployment, which credential mode the plugin will use: kind (`service-key` / `password-passthrough`), key reference (encrypted at rest via the secret backend), and policy notes. Audited on create, edit, and use.

`UserSSHKey` rows are **not** part of v1 (they were in the previous draft for the per-user-key path which is deferred). They will be added with the SSH CA work in v1.1.

## RBAC and Sharing

Permissions:

- `deployment.console.ssh` — open an SSH session to a deployment whose network offering supports it.
- `deployment.console.replay` — view recorded sessions.
- `deployment.console.ssh.password` — use the password-passthrough path (additional gate beyond `console.ssh`).
- `racklab.console.ssh.admin` — manage SSH credential bindings, view all sessions, prune recordings, force re-capture of pinned host keys.

Sharing reuses the PRD §06 share-link primitive. A guest-link shared SSH session uses a short-lived guest token bound to a single `ConsoleSession` and revocable.

## Plugin Contract Compliance

The plugin demonstrates the contract:

- **Discovery**: registered via a Python entry point in the `racklab.plugins` group.
- **Capability declaration**: `console:ssh:v1`, supported RackLab API range, contributed permissions, migration set, health check (`console-ssh.health` returns ok if Channels is up, asyncssh can be imported, and a probe SSH-connect to a known reachable test host succeeds).
- **Migration shipping**: contributes Django models (`ConsoleSession`-SSH-subtype rows, `SSHCredentialBinding`). Migrations follow the plugin migration lifecycle in PRD §13.
- **RBAC contribution**: the four `console.ssh*` permissions integrated with allauth and the share-link primitive.
- **Audit emission**: emits `console.ssh.session.start`, `.end`, `.bind`, `.password_used`, `.host_key_mismatch`, `.host_key_recaptured`, `.recording.start`, `.recording.aborted_redaction`, `.recording.replay`, `.credential.rotated`. Schema follows PRD §14.
- **Artifact storage integration**: session recordings land in artifact storage as `Artifact(kind=console_recording)`; cleanup is the universal retention sweep.
- **WorkerRuntime user**: co-locates with the existing `console-worker` pool's `WorkerPoolSpec`.
- **Failure isolation**: a plugin-internal failure (asyncssh dependency missing, secret backend unreachable) degrades to "SSH unavailable" with a clear admin alert. It does not break other console types or the rest of RackLab.

## Operational Notes

- **Idle timeout**: configurable per pool, default 15 minutes. The Channels consumer detects browser disconnect and asyncssh's keepalive detects SSH-side drop.
- **Max duration**: configurable per pool, default 4 hours.
- **Per-user concurrency cap**: configurable, default 5 concurrent sessions per user.
- **Browser tab backgrounding**: asyncssh keepalive interval is set lower than typical browser tab-suspend behavior so sessions don't drop on minimized tabs.
- **Session-killed-mid-stream**: on SSH-side drop, the Channels consumer flushes the cast, audits, notifies the browser, and offers reconnect.
- **Daphne vs uvicorn**: console-worker uses Daphne for ASGI termination in v1; performance benchmarking with sustained binary WebSocket streams is on the v1 verification list.

## Deployment

The plugin ships as a Python package on PyPI: `racklab-console-ssh`. Installation follows the standard RackLab plugin lifecycle from PRD §13:

```sh
racklab plugin install racklab-console-ssh
racklab plugin migrate racklab-console-ssh
racklab plugin enable racklab-console-ssh
```

The plugin runs inside the existing `console-worker` Podman container — it adds Channels routes and consumers to the console-worker app. No new top-level service in the orchestration spec. The xterm.js front-end is shared with `racklab-console-proxmox`.

## Out of Scope for v1

- SSH to deployments on `isolated_no_ingress` networks. Use an instructor-controlled jump host inside the network.
- Per-user-key SSH paths. Deferred to v1.1 as the SSH CA design.
- SFTP / file transfer (deferred to v1.1).
- Multi-viewer same session (deferred to v2).
- Custom session recording formats beyond asciinema v2.
- SSH-over-non-WebSocket transports.
- Apache Guacamole as the underlying engine (rejected; a future `racklab-console-guacamole` plugin is a separate spec if RDP/VNC-via-Guacamole becomes a real ask).

## Effort Estimate

Approximately 3-4 engineering weeks for one Django developer — slightly higher than the previous estimate because host-key capture, the redaction-with-abort recording policy, and reachability-aware grant issuance are explicit v1 work:

- Plugin skeleton, capability declaration, models, migrations, RBAC permission strings — ~3 days.
- Channels consumer, asyncssh client wiring with pinned `known_hosts`, `ConsoleAccessGrant` validation, audit emission — ~4 days.
- Cloud-init host-key capture flow + `requires_host_key_capture` handling for cloud-init-less images — ~3 days.
- xterm.js front-end pane (reusing console-proxmox chrome) + service-key cloud-init provisioning + consent prompt — ~2 days.
- Pattern-based redaction pipeline + abort-on-failure + asciinema v2 emission + replay view — ~4 days.
- Idle / duration / concurrency policy, drain semantics, browser-tab keepalive — ~2 days.
- Tests including a deliberate MITM scenario, a redaction-defeating-byte-sequence scenario, and an `isolated_no_ingress` grant-refusal scenario — ~3 days.
- Accessibility audit, packaging, docs — ~2 days.

## Fallback

If team capacity makes the custom build untenable, the recorded fallback is **sshwifty** (Go, AGPL-3.0) as a sibling Podman container reverse-proxied under `/console/ssh/`, with a small Go shim that reads a RackLab-issued session cookie carrying the `ConsoleAccessGrant`. In this fallback, session recording is lost (sshwifty doesn't record), audit is degraded to "user X opened a session to host Y" without keystroke detail, host-key verification is delegated to sshwifty's accept-on-first-connect default (worse than the TOFU + cloud-init capture path here), and the AGPL license stacks. Documented because it's operationally known, not because it's preferred.

## Confidence

**High** on deferring to the network offering's reachability capability — the gnarly question of "how does console-worker actually reach a tenant VM" gets answered in the right place.

**High** on the cloud-init-host-key-capture + TOFU pinning model for v1, with SSH CA as the v1.1 destination.

**High** on the redaction-with-abort recording policy — fail closed when the redactor can't keep up.

**High** on removing per-user keys from v1. The previous draft tried to ship them with a deferred private-key-delivery story; that combination wasn't safe.

**Medium** on the 3-4 week effort estimate.
