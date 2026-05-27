# Catalog, Stacks, And Deployments

## Catalog

The catalog is RackLab's store of deployable Stacks. It contains searchable, versioned Stack templates that users can deploy into Projects when RBAC, quota, and provider capability checks allow it. A "single VM" catalog item is a Stack template with one VM component.

Catalog item metadata:

- Name, description, owner, visibility, version, and lifecycle state.
- Provider capability requirements.
- Template/image source.
- Default sizing and allowed sizing ranges.
- Required network offerings.
- Quota cost.
- Lease policy.
- Default RBAC grants.
- Allowed script modes.
- Required secrets.
- Restore modes.
- Console markdown panels.
- Health checks.
- Approval requirements.

Catalog publishing requires RBAC permission and may require approval. Published catalog versions are immutable; edits create a new version.

## Core Model

A **Stack** is the unit RackLab deploys to a Project. A Stack may contain one VM or many VMs, plus its networks, cloud-init inputs, scripts, console instructions, lease policy, quota cost, and provider capability requirements. RackLab does not have a separate standalone VM deployment type; quick VM launches are one-component Stacks.

There are two Stack forms:

- `StackDefinition` — a reusable blueprint. It can be catalog-published or project-local.
- `Deployment` — a deployed Stack instance in a Project. The UI can call this a Stack; the data model uses `Deployment` for the durable lifecycle and provider-task records.

On first login or registration, RackLab creates a personal Project in the user's primary tenant and grants them Project Owner. Each Project has a reserved project-local **Default** StackDefinition. When a user chooses **New VM** without selecting an existing Stack, RackLab applies an `add_vm` operation to the Project's active Default Stack Deployment. If no active Default Stack Deployment exists, RackLab creates one from the reserved Default StackDefinition first.

## Stacks

A `StackDefinition` defines:

- VM roles.
- VM template or image source.
- CPU, memory, disk, firmware, and device settings.
- Boot order and startup dependencies.
- Logical networks and VM ports.
- Optional network-service components such as VPNaaS endpoints for isolated networks.
- Cloud-init inputs.
- Post-deployment actions.
- Health checks.
- Console instructions.
- Share defaults.
- Quota cost.
- Provider capability requirements.

Logical network names, such as `student-lan`, `wan`, `dmz`, and `attacker-net`, are mapped to site-specific network offerings or provider networks during deployment.

## Stack Packages And Export

Stacks are exportable as RackLab Stack Packages: OVA-style archives that bundle a Stack manifest, VM metadata, disk artifacts, checksums, provenance, and RackLab-specific policy metadata.

The package target is **OVA-style**, not a blanket promise of full OVF compatibility in v1. The export format is a single archive like an OVA, and when practical it includes an OVF descriptor for VM topology and hardware settings. The RackLab manifest is authoritative for features OVF does not model cleanly, including project-local network offerings, RBAC defaults, cloud-init variables, script references, console instruction panels, and plugin-contributed metadata.

A Stack Package contains:

- `racklab-stack.json` or `racklab-stack.yaml` manifest with format version, StackDefinition, components, provider requirements, source metadata, and import hints.
- Optional `.ovf` descriptor for virtualization tools that can consume standard VM topology.
- Disk artifacts for each VM, normalized to a configured export disk format when the provider supports conversion.
- Cloud-init snippets, scripts, console instruction markdown, and docs references when the exporter has permission to include them.
- Manifest/checksum file and optional signature.

Secrets are never exported as raw values. Secret references are exported as unresolved placeholders that must be rebound at import time. Provider-specific identifiers, MAC addresses, IP addresses, public/service IP assignments, UDP ports, and storage paths are stripped or converted to import hints unless explicitly marked portable. ProjectSSHKey references, VPN client profiles, principal references, share defaults, RBAC bindings, guest links, and plugin-contributed access metadata are exported only as unresolved rebinding requirements; import never silently maps them to local users, keys, or VPN credentials.

Exporting disk artifacts requires `catalog.stack_package.export` plus read access to every VM/disk artifact included in the package. Importing requires `catalog.stack_package.import` and creates a project-local StackDefinition by default. Publishing the imported Stack to the catalog is a separate approval-gated action. Import validates package schema, checksums, provider capability requirements, disk format support, network mappings, quota impact, required secret bindings, ProjectSSHKey rebinding, and access-metadata rebinding before the Stack can deploy. Export, import, failed validation, and rebinding decisions are audited.

## Project-Local Stacks And Stack Changes

Users with permission can create project-local Stack definitions without publishing them to the catalog. Project-local Stacks are visible only inside the owning Project unless shared through the normal project-sharing path.

Deployed Stacks are mutable through explicit operations:

- Add a VM to the Stack.
- Remove a VM from the Stack.
- Rebuild a single VM from its template, image, or snapshot.
- Rebuild the full Stack.
- Update allowed sizing, network attachment, script, and lease settings when policy allows.

Every Stack change runs through the same RBAC, quota, scheduler, provider-task, audit, and replay-event paths as initial deployment. Catalog-published `CatalogVersion` rows remain immutable; mutating a deployed Stack creates a deployment operation against the Stack instance, not a silent edit to the source catalog version.

## Stack Wizard

The stack wizard supports instructors and allowed students.

Wizard responsibilities:

- Compose Stacks from templates, VMs, networks, network-service components, scripts, SSH keys, and policies.
- Validate quota impact.
- Validate provider compatibility.
- Validate network mappings.
- Validate required secrets.
- Validate script approval state.
- Preview deployment plan and cost.
- Save drafts.
- Publish versions when permitted.

## Deployment Lifecycle

1. User selects a catalog Stack, project-local Stack, or **New VM**. **New VM** creates a `DeploymentOperation(add_vm)` against the Project's active Default Stack Deployment, creating that Deployment first if needed.
2. RackLab validates RBAC, quota, provider capability, network policy, script approval, and lease policy.
3. Scheduler reserves quota and provider capacity.
4. Deployment, job, and audit records are persisted.
5. Job is dispatched to the Horizon queue (Redis-backed) after the database transaction commits.
6. Provider worker creates, clones, restores, or updates resources.
7. Script workers render cloud-init, run console automation, or run network automation.
8. Progress events are persisted and broadcast.
9. Deployment reaches ready, failed, partially ready, waiting for approval, or waiting for manual action.
10. Cleanup and reconciliation workers handle expiration, deletion, stuck jobs, and provider drift.

## Console Instructions

Stacks and VMs can attach markdown files for:

- Top console panel.
- Side console panel.
- Bottom console panel.

Markdown is sanitized before display. Instructions can be versioned with catalog items.
