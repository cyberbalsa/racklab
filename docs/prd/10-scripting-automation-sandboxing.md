# Scripting, Automation, And Sandboxing

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

The product should be openQA-inspired, not necessarily dependent on a full openQA deployment for v1. A future runner plugin may integrate `openqa` or `os-autoinst` directly.

### Network Automation

When a VM is reachable, RackLab supports:

- SSH.
- WinRM.
- Ansible Runner.
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

Sandbox requirements:

- `bubblewrap` or `nsjail` runner profiles.
- Immutable rootfs or chroot image.
- Read-only base filesystem.
- Per-job writable scratch directory.
- Dropped Linux capabilities.
- Restricted `/proc`.
- Restricted `/dev`.
- CPU limits.
- Memory limits.
- Process count limits.
- File size limits.
- Wall-clock timeout.
- Network disabled by default.
- Explicit network allow policy only for runner types that require it.
- No host secrets mounted.
- Secret redaction in stdout, stderr, logs, and artifacts.

`nsjail` is a strong fit for untrusted script execution because it combines namespaces, cgroups, rlimits, and seccomp-bpf. `bubblewrap` is useful as a low-level sandbox builder, but RackLab must ship hardened runner profiles rather than rely on ad hoc per-job arguments.
