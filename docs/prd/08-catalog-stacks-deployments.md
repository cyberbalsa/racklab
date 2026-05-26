# Catalog, Stacks, And Deployments

## Catalog

The catalog contains versioned singleton VM templates and multi-VM stack templates.

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

Catalog publishing requires RBAC permission and may require approval.

## Stacks

A stack template defines:

- VM roles.
- VM template or image source.
- CPU, memory, disk, firmware, and device settings.
- Boot order and startup dependencies.
- Logical networks and VM ports.
- Cloud-init inputs.
- Post-deployment actions.
- Health checks.
- Console instructions.
- Share defaults.
- Quota cost.
- Provider capability requirements.

Logical network names, such as `student-lan`, `wan`, `dmz`, and `attacker-net`, are mapped to site-specific network offerings or provider networks during deployment.

## Stack Wizard

The stack wizard supports instructors and allowed students.

Wizard responsibilities:

- Compose stacks from templates, networks, scripts, and policies.
- Validate quota impact.
- Validate provider compatibility.
- Validate network mappings.
- Validate required secrets.
- Validate script approval state.
- Preview deployment plan and cost.
- Save drafts.
- Publish versions when permitted.

## Deployment Lifecycle

1. User selects a catalog item or stack.
2. RackLab validates RBAC, quota, provider capability, network policy, script approval, and lease policy.
3. Scheduler reserves quota and provider capacity.
4. Deployment, job, and audit records are persisted.
5. Job is published to NATS JetStream.
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
