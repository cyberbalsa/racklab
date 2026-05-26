# M7b — Script Sandbox: nsjail + Ansible + openQA

**Status:** Not started.
**Estimated effort:** 3–4 weeks.
**Depends on:** M7a, M4 (the openQA runner uses the Proxmox console proxy under the hood).
**Unblocks:** complete post-deployment automation surface.

## Goal

The advanced script runners — nsjail-isolated user code, Ansible Runner for network-reachable VMs, openQA-style console automation for non-networked VMs — land on top of M7a's script-worker pool scaffolding. Approval workflow covers all three new runners with the script-permission strings from PRD §10.

## In scope

- PRD §10 — the runners and approval workflow not delivered in M7a.
- PRD §19 — `RunnerProfile` rows for advanced, Ansible, and openQA runners.
- Initial plugins: `racklab-script-console-openqa`, `racklab-script-ansible`, plus an internal "advanced code" runner exposed as a runner profile (no separate plugin — it's part of the script-worker core).

## Dependencies

- M7a — `script-worker` pool scaffolding, base data model, cloud-init runner profile.
- M4 — Proxmox console proxy + xterm.js (openQA runner targets via the console).

## Deliverables

- **nsjail runner profiles** checked into `deploy/sandbox/profiles/`: immutable rootfs, read-only base FS, per-job writable scratch directory, dropped Linux capabilities, restricted `/proc` and `/dev`, CPU + memory + process-count + file-size + wall-clock limits, network-disabled-by-default, explicit network-allow policy only where the runner type requires it (Ansible runner needs SSH to reach the VM, for example).
- **Advanced code runner**: invokes nsjail with the right profile; user-authored Python / shell / PowerShell runs inside the sandbox; outputs go to artifact storage; secrets are not mounted into the sandbox.
- `racklab-script-console-openqa` plugin: implements the openQA primitives per PRD §10 (`send_key`, `type_string`, `wait_screen`, `assert_screen`, `wait_serial`, `script_run`, screenshot capture, serial log capture). Console session targeting uses the `racklab-console-proxmox` plugin internally.
- `racklab-script-ansible` plugin: Ansible Runner integration. Pinned `ansible-runner` 2.4.3 + a controlled `ansible-core` execution-environment container image with a documented Ansible-collection set. CI tests against the pinned EE image.
- Full approval workflow per PRD §10 with the script-permission strings: `script.openqa.create`, `script.cloudinit.create`, `script.network.create`, `script.advanced_code.create`, `script.run_unapproved`, `script.approve`, `script.publish`.
- Approval scopes per PRD §10: one deployment / one project / one course / one catalog item version / reusable catalog script.
- Approval invalidation on executable payload edits per PRD §10.
- Per-runner audit events: runner-profile / digest / exit-status / log / artifacts / screenshots / serial-output.

## Acceptance criteria

- [ ] An instructor writes a console-openqa script that types a username at a login prompt and waits for a shell prompt; running it against an LXC container succeeds; screenshots and serial captures land in artifact storage.
- [ ] An instructor writes an Ansible playbook script and runs it against a reachable Linux VM; the playbook executes; result artifacts captured.
- [ ] A student attempts to create an advanced-code (Python) script without `script.advanced_code.create` permission; the create endpoint returns 403 with the audit-logged denial.
- [ ] A script run is enforced inside nsjail with the documented profile — verified by deliberate-misbehavior tests: (a) the script can't reach the control-plane host's filesystem outside its scratch dir, (b) it can't reach provider credentials, (c) network is disabled unless the runner type allows it, (d) wall-clock limit kills runaway scripts, (e) memory limit kills memory-hogs, (f) process-count limit prevents fork bombs.
- [ ] Editing a script's executable payload invalidates a prior approval; the change is audit-logged with the diff summary.
- [ ] An admin approves a script for a specific catalog-item version; the same script in another course requires its own approval.

## Test layers

- **Tiny / unit**: nsjail profile loader; openQA primitive parser; the approval state machine + executable-vs-metadata edit detector.
- **Contract**: the script-runner Protocol for each new runner kind against fake nsjail / fake Ansible-Runner / fake Proxmox console.
- **Integration**: nsjail-isolated script runs against testcontainers, verifying every isolation invariant from PRD §10; openQA primitives against a real Proxmox console mock; Ansible playbook against a real SSH target.
- **E2E**: an instructor approves a console-openqa script and a student runs it against a deployment; an instructor runs an Ansible playbook against a deployment they own; a deliberate-misbehavior advanced-code script is killed by the wall-clock limit.

## Required spike before this milestone

**nsjail profile escape tests** (per codex's roadmap-review spike list). Before M7b commits to the documented isolation invariants, run a focused spike with a malicious-script corpus that tries to: break out of the rootfs, signal control-plane processes, read provider credentials, exhaust the host's process table, allocate beyond the cgroup limit, escape the seccomp filter, perform timing-based information leaks, exploit known nsjail CVE classes. The spike result determines whether the documented profile is sufficient or needs tightening before M7b implementation begins.

## Risks / open questions

- **nsjail availability**: nsjail is Linux-only. CI's Linux runners support it. macOS dev environments use a smaller fake-sandbox runner per PRD §17 but cannot run real script-isolation tests. Document the dev-environment limitation.
- **Ansible's collection ecosystem moves fast**: pin a known-good Ansible + Runner version + execution environment image; document the upgrade policy explicitly. Don't allow operators to install collections at runtime without admin approval (turn off `ansible-galaxy` invocation inside the sandbox).
- **Console-openqa screenshots are large**: ten screenshots per script run × thousands of runs blows up artifact storage. Retention defaults: screenshots 30 days, full logs 90 days, then prune per the M0 retention sweep.
- **Advanced-code runners** carry the highest risk; default policy is approval-required even for instructors; only admins disable per catalog item, audit-logged.

## Out of scope (deferred)

- A full openQA deployment as the v1 console-automation runtime — PRD §10 explicitly says "openQA-inspired, not necessarily dependent." A future plugin can wrap real openQA.
- WinRM credential management beyond storage in the secret backend — basic password + Kerberos lands; advanced policy is post-v1.
- Cross-deployment script orchestration — post-v1 plugin extension.
- Script marketplace — beyond what the share-link primitive supports.
