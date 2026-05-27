# M7a — Cloud-Init Provisioning

**Status:** Not started.
**Estimated effort:** 2–3 weeks.
**Depends on:** M2.
**Unblocks:** M7b, M9.

## Goal

Provision-time automation works through a constrained cloud-init plugin before the broader script-sandbox surface lands. M7a creates the base script data model, a `script-worker` scaffold, cloud-init rendering, host-key phone-home, ProjectSSHKey injection, and gateway-service-key injection required by the SSH plugin.

## In scope

- PRD §10 scripting and automation — cloud-init runner, script catalog references, low-risk provisioning flow.
- PRD §19 data model — base `Script`, `ScriptVersion`, `ScriptRun`, and cloud-init `RunnerProfile` subset.
- PRD §23 SSH plugin prerequisites — host-key capture, ProjectSSHKey injection, and gateway-service-key injection during provisioning.
- The `racklab-script-cloudinit` first-party plugin.

## Dependencies

- M2 deployment lifecycle — script runs are universal `Job` rows and publish through the same worker flow.
- M3 Proxmox provider is not required for the data model, but real cloud-init injection is exercised against the Proxmox provider once M3 exists.
- M0 plugin framework, audit subsystem, artifact storage, and secret-backend abstraction.

## Deliverables

- `racklab/scripts` Laravel module with base Eloquent models: `Script`, `ScriptVersion`, `ScriptRun`, `RunnerProfile`, and catalog-version references for provision-time scripts.
- `script-worker` pool scaffold: receives script jobs, loads runner plugins, emits `ScriptRun` state transitions, writes logs/artifacts through the M0 artifact API. Only the cloud-init runner is active in M7a.
- `racklab-script-cloudinit` plugin:
  - wizard fields for users, ProjectSSHKey selection, password policy, packages, files, commands, network hints, template variables, and secret references.
  - raw YAML editor with schema validation and safe rendering.
  - rendered output passed to the provider's cloud-init slot at deployment time.
- Host-key phone-home endpoint: cloud-init posts generated host public keys back to RackLab, bound to the deployment resource and audited.
- Project SSH key injection: RackLab injects selected `ProjectSSHKey` public keys into the configured guest accounts and records which user created each key.
- Gateway-service-key injection: RackLab provisions a scoped SSH gateway public key into the guest so M9 can open browser SSH sessions without user password relay by default.
- Secret-reference rendering: persisted scripts store references, not secret values; rendered cloud-init is redacted in logs and audit events.
- Audit events for script create/edit/publish, cloud-init render, ProjectSSHKey injection, host-key phone-home success/failure, gateway-service-key injection, and provisioning result.
- Basic UI surfaces: cloud-init editor, catalog-version provisioning script selector, script-run result panel on deployment detail.

## Acceptance criteria

- [ ] A student writes a cloud-init script via the wizard, selects a ProjectSSHKey, references it in an instructor-authored catalog item, deploys, and the VM boots with the expected account, public key, and package installs.
- [ ] A cloud-init raw YAML script with invalid schema is rejected before catalog publication with field-level errors.
- [ ] The guest posts its SSH host key during first boot; RackLab persists the key against the deployment resource and emits the host-key audit event.
- [ ] RackLab injects selected ProjectSSHKey public keys and the scoped gateway service public key during provisioning; M9 can later consume the recorded gateway-key metadata.
- [ ] Secret references render only at provision time; rendered secret values do not appear in `ScriptVersion`, `ScriptRun`, audit payloads, or captured logs.
- [ ] Editing executable cloud-init content creates a new `ScriptVersion`; older catalog versions keep referencing the old immutable version.

## Test layers

- **Tiny / unit**: cloud-init wizard → YAML transformer; schema validation; secret-reference redaction; host-key payload validator.
- **Contract**: cloud-init runner Protocol against fake provider injection; phone-home endpoint contract against signed/unsigned payloads.
- **Integration**: cloud-init injection through fake provider and Proxmox API mock; ProjectSSHKey injection; host-key phone-home round trip; gateway-service-key injection metadata persisted.
- **E2E**: student deploys a VM with cloud-init that writes a sentinel file; deployment-detail page shows cloud-init completion and the captured host key.

## Risks / open questions

- **Phone-home authenticity**: the guest must prove the phone-home belongs to the deployment. Use a short-lived, deployment-scoped token rendered into cloud-init and expire it after first successful use.
- **Cloud-init secret leakage**: provider APIs and guest logs can echo rendered YAML. Redaction must happen before any RackLab-side logging, but guests may still store the secret in their own logs; document this limitation.
- **Provider timing**: some images regenerate host keys after network comes up. M9 must treat missing host keys as "SSH unavailable until captured" rather than falling back to trust-on-first-use.
- **Windows provisioning**: cloudbase-init support is useful, but v1 can start with Linux cloud-init and document Windows as future work.

## Out of scope (deferred)

- Podman-sandboxed advanced-code runners — M7b.
- Ansible container runner and WinRM automation — M7b.
- openQA-style console automation — M7b.
- Approval workflow for high-risk scripts — M7b.
- Script marketplace and cross-deployment orchestration — post-v1.
