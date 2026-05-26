# Scripting, Automation, And Sandboxing

> **Note:** Implementation detail for the sandboxing stack (per-job Podman container manifests, network policy modes, image signing, the ProviderConsoleProxy refactor for console-script containers) lives in `docs/superpowers/specs/2026-05-26-laravel-redesign.md` §7. This document captures the functional sandboxing contract — untrusted-only-in-script-workers, no-provider-creds-in-script-workers, network-default-deny, resource isolation, openQA-style console scripts; the spec is the source of truth for the runtime that implements them.

RackLab supports student, instructor, and admin-authored post-deployment automation. Scripts are first-class objects with ownership, versioning, approval, RBAC, logs, artifacts, and immutable execution records.

## Execution Modes

### Cloud-Init

RackLab includes a cloud-init wizard for:

- Users.
- SSH keys.
- Password policy.
- Packages.
- Files.
- Commands.
- Network hints.
- Template variables.
- Secrets references.

Advanced users can edit raw cloud-init YAML with validation and policy checks.

### Console Automation

RackLab supports openQA-inspired console automation for machines where networking is unavailable or intentionally isolated.

Required primitives:

- `send_key`
- `type_string`
- `wait_screen`
- `assert_screen`
- `wait_serial`
- `script_run`
- Screenshot capture.
- Serial log capture.
- Artifact capture.

The product is openQA-inspired in spirit. Console automation runs as a `racklab/console-script:v1` container that communicates with `ProviderConsoleProxy` over a unix socket (spec §7) — the container never holds Proxmox credentials. A future runner plugin may integrate `openqa` or `os-autoinst` directly.

### Network Automation

When a VM is reachable, RackLab supports:

- SSH.
- WinRM.
- Ansible playbooks (run inside the `racklab/ansible-runner:v1` container substrate).
- File upload/download.
- Command execution.
- Structured result capture.

### Advanced Code

Users with explicit permission can run Python, shell, or PowerShell scripts. Advanced code always runs in isolated script workers.

## RBAC And Approval

Script permissions are separated by runner type:

- `script.openqa.create`
- `script.cloudinit.create`
- `script.network.create`
- `script.advanced_code.create`
- `script.run_unapproved`
- `script.approve`
- `script.publish`

Default student policy can allow cloud-init and openQA-style scripts while requiring approval for advanced code. Instructors and admins can grant roles that allow openQA-style and advanced scripts without approval.

Approval scopes:

- One deployment.
- One project.
- One course.
- One catalog item/version.
- Reusable catalog script.

Any executable edit invalidates approval unless it is a non-executable metadata change.

## Script Worker Isolation

Untrusted scripts run on dedicated script worker pools, separate from provider and web workers.

Operators can run script workers on isolated hosts, VMs, or container nodes.

Each untrusted script or automation job runs in a per-job ephemeral Podman container with the following hardened manifest (spec §7):

- `--network=none` by default — network disabled unless the container kind's manifest explicitly declares a permitted network mode.
- Explicit network allow policy only for runner types that require it (e.g. `egress-via-proxy` for Ansible playbooks reaching package mirrors).
- `--read-only` base filesystem.
- `--tmpfs=/tmp` for per-job writable scratch.
- `--user=10001:10001` — non-root, no privilege escalation.
- `--cpus`, `--memory`, and `--pids-limit` resource caps.
- Wall-clock timeout enforced by the worker; container killed on expiry.
- No host secrets mounted.
- Secret redaction in stdout, stderr, logs, and artifacts.

Podman's default seccomp profile, cgroup v2 resource accounting, and the container's dropped Linux capabilities provide equivalent or stronger isolation than a raw namespace/rlimit approach, plus a clear plugin-extension contract for new container kinds (spec §7).

**No provider credentials in script workers** (PRD §18:37). Console-script containers communicate with the `ProviderConsoleProxy` over a bind-mounted unix socket and carry only a narrow-scope Track A JWT. The proxy holds the Proxmox API credentials; containers can never reach Proxmox directly — the network policy forbids it.

Container images (`racklab/ansible-runner:v1`, `racklab/user-script:v1`, `racklab/console-script:v1`) are pulled from a private OCI registry with cosign-signed manifests; `podman pull --signature-policy` enforces verification at pull time.

Plugins may contribute new container kinds by declaring a manifest (base image, resource caps, network policy, mounts, env contract, required JWT scope). The plugin's signing material is surfaced to operators at install time.
