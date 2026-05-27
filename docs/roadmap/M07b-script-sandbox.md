# M7b — Script Sandbox: Podman Containers + Ansible + openQA

**Status:** Not started.
**Estimated effort:** 3–4 weeks.
**Depends on:** M7a, M4 (the openQA runner uses the Proxmox console proxy under the hood).
**Unblocks:** complete post-deployment automation surface.

## Goal

The advanced script runners — per-job Podman containers for user code, `racklab/ansible-runner:v1` for network-reachable VMs, openQA-style console automation via `racklab/console-script:v1` + ProviderConsoleProxy for non-networked VMs — land on top of M7a's script-worker pool scaffolding. Approval workflow covers all three new runners with the script-permission strings from PRD §10.

## In scope

- PRD §10 — the runners and approval workflow not delivered in M7a.
- PRD §19 — `RunnerProfile` rows for advanced, Ansible, and openQA runners.
- Initial plugins: `racklab-script-console-openqa`, `racklab-script-ansible`, plus an internal "advanced code" runner exposed as a runner profile (no separate plugin — it's part of the script-worker core).
- Container image build pipeline: Dockerfiles for `racklab/ansible-runner:v1`, `racklab/user-script:v1`, `racklab/console-script:v1`; CI builds and cosign-signs on tag; operators verify at pull time via `podman pull --signature-policy`.

## Dependencies

- M7a — `script-worker` pool scaffolding, base data model, cloud-init runner profile.
- M4 — Proxmox console proxy + xterm.js (openQA runner targets via the console).

## Deliverables

- **Per-job container profiles** (container manifests living next to each Horizon job class in `app/Jobs/`): resource caps (`--cpus=2 --memory=4g --pids-limit=512`), read-only rootfs (`--read-only --tmpfs=/tmp`), unprivileged user (`--user=10001:10001`), network policy per the four container network modes defined in the spec (`none` / `via-console-proxy` / `egress-via-proxy` / `isolated-net`), artifact-volume mount read-only, console-proxy unix socket mount for console-script only.
- **Advanced code runner** (`App\Jobs\RunUserScript`): shells out to `podman run … racklab/user-script:v1`; user-authored Python / shell / PowerShell runs inside the container with `--network=none`; stdout/stderr streamed back to the Horizon job; outputs written to artifact storage; secrets are not mounted into the container.
- `racklab-script-console-openqa` plugin: implements the openQA primitives per PRD §10 (`send_key`, `type_string`, `wait_screen`, `assert_screen`, `wait_serial`, `script_run`, screenshot capture, serial log capture). Console session targeting uses `App\Jobs\RunConsoleScript` with `racklab/console-script:v1`; the container holds a narrow Track A JWT (scoped to a single `(tenant, deployment_resource, op_set, expiry)` tuple) and communicates with the VM only through the `ProviderConsoleProxy` unix socket. No Proxmox credentials ever enter the container.
- `racklab-script-ansible` plugin: Ansible automation via `App\Jobs\RunAnsiblePlaybook` + `racklab/ansible-runner:v1`. The runner image pins a controlled `ansible-core` + Ansible-collection set; network mode is `egress-via-proxy` with the Ansible target in the per-job allow-list. CI tests against the pinned runner image.
- Full approval workflow per PRD §10 with the script-permission strings: `script.openqa.create`, `script.cloudinit.create`, `script.network.create`, `script.advanced_code.create`, `script.run_unapproved`, `script.approve`, `script.publish`.
- Approval scopes per PRD §10: one deployment / one project / one course / one catalog item version / reusable catalog script.
- Approval invalidation on executable payload edits per PRD §10.
- Container lifecycle hardening: Horizon job timeout → `podman kill` + cleanup (custom shutdown handler); reaper sidecar on `cleanup` queue sweeps stale containers older than max-job-age (container name encodes `RACKLAB_JOB_ID` for correlation); script-running jobs are NOT auto-retried (explicit re-dispatch only, partial side-effects are dangerous).
- Per-runner audit events: runner-profile / image digest / exit-status / log / artifacts / screenshots / serial-output.

## Acceptance criteria

- [ ] An instructor writes a console-openqa script that types a username at a login prompt and waits for a shell prompt; running it against an LXC container succeeds; screenshots and serial captures land in artifact storage.
- [ ] An instructor writes an Ansible playbook script and runs it against a reachable Linux VM; the playbook executes inside `racklab/ansible-runner:v1`; result artifacts captured.
- [ ] A student attempts to create an advanced-code (Python) script without `script.advanced_code.create` permission; the create endpoint returns 403 with the audit-logged denial.
- [ ] A script run is enforced inside a Podman container with `--network=none` and the documented caps — verified by deliberate-misbehavior tests: (a) the script can't read the host filesystem outside its artifact mount, (b) it can't reach provider credentials, (c) network is disabled by default, (d) wall-clock limit (Horizon job timeout + `podman kill`) kills runaway scripts, (e) `--memory` cap kills memory-hogs, (f) `--pids-limit` prevents fork bombs.
- [ ] A console-script container successfully communicates with the ProviderConsoleProxy over the unix socket to send a keypress; no Proxmox credential is present inside the container.
- [ ] Editing a script's executable payload invalidates a prior approval; the change is audit-logged with the diff summary.
- [ ] An admin approves a script for a specific catalog-item version; the same script in another course requires its own approval.
- [ ] All `racklab/*` runner images are pulled with cosign-verified manifests; pulling an unsigned or tampered image is rejected.

## Test layers

- **Tiny / unit**: container manifest loader; openQA primitive parser; the approval state machine + executable-vs-metadata edit detector.
- **Contract**: the script-runner Protocol for each new runner kind against fake container runtime / fake ProviderConsoleProxy / fake Proxmox console.
- **Integration**: Podman-sandboxed script runs against testcontainers, verifying every isolation invariant from PRD §10; openQA primitives against a real Proxmox console mock; Ansible playbook against a real SSH target inside `racklab/ansible-runner:v1`.
- **E2E**: an instructor approves a console-openqa script and a student runs it against a deployment; an instructor runs an Ansible playbook against a deployment they own; a deliberate-misbehavior advanced-code script is killed by the wall-clock limit.

## Required spike before this milestone

**Container isolation escape tests** (per codex's roadmap-review spike list). Before M7b commits to the documented isolation invariants, run a focused spike with a malicious-script corpus that tries to: break out of the container's read-only rootfs, signal Horizon worker processes, read provider credentials from host paths, exhaust the host's process table (pids-limit test), allocate beyond the cgroup memory limit, escape the container's seccomp/AppArmor profile, perform timing-based information leaks, exploit known Podman rootless CVE classes. The spike result determines whether the documented manifest flags are sufficient or need tightening before M7b implementation begins.

## Risks / open questions

- **Rootless Podman availability**: rootless Podman is Linux-only. CI's Linux runners support it. macOS dev environments use a smaller fake-container runner but cannot run real isolation tests. Document the dev-environment limitation.
- **Ansible's collection ecosystem moves fast**: pin a known-good `ansible-core` version + collection set inside `racklab/ansible-runner:v1`; document the upgrade policy explicitly. Disallow runtime `ansible-galaxy` invocations inside the container (no network egress to collection servers unless explicitly added to the per-job allow-list; default is blocked).
- **Console-openqa screenshots are large**: ten screenshots per script run × thousands of runs blows up artifact storage. Retention defaults: screenshots 30 days, full logs 90 days, then prune per the M0 retention sweep.
- **Advanced-code runners** carry the highest risk; default policy is approval-required even for instructors; only admins disable per catalog item, audit-logged.
- **ProviderConsoleProxy trust boundary**: the proxy is the only thing that holds Proxmox API credentials. Its unix socket must have 0600 permissions owned by the Horizon worker user; the container runs as uid 10001 which must not be the same uid as the worker process.

## Out of scope (deferred)

- A full openQA deployment as the v1 console-automation runtime — PRD §10 explicitly says "openQA-inspired, not necessarily dependent." A future plugin can wrap real openQA.
- WinRM credential management beyond storage in the secret backend — basic password + Kerberos lands; advanced policy is post-v1.
- Cross-deployment script orchestration — post-v1 plugin extension.
- Script marketplace — beyond what the share-link primitive supports.
