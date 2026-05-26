# Quotas, Scheduling, And Placement Research

## Quota Models

OpenNebula quotas cover users and groups and can limit datastores, compute, networks, images, running resources, and generic VM attributes. This supports RackLab quotas for compute, memory, disk, snapshots, networks, ports, provider-direct NICs, scripts, and concurrent deployments.

Source:

- OpenNebula quotas: https://docs.opennebula.io/7.0/product/cloud_system_administration/capacity_planning/quotas/

CloudStack projects have project-level resource limits for resources such as public IPs, instances, volumes, snapshots, and templates. This supports RackLab project and course quota scopes.

Sources:

- CloudStack projects: https://docs.cloudstack.apache.org/en/latest/adminguide/projects.html
- CloudStack quota plugin: https://docs.cloudstack.apache.org/en/4.20.2.0/plugins/quota.html

## Network Quotas

Neutron has quotas for network resources, and OpenNebula explicitly treats IP usage on networks as quota-relevant. RackLab should quota networks, subnets, ports, routers, floating IPs, provider-direct NICs, and security group rules.

Sources:

- Neutron quotas internals: https://docs.openstack.org/neutron/latest/contributor/internals/quota.html
- OpenNebula quotas: https://docs.opennebula.io/7.0/product/cloud_system_administration/capacity_planning/quotas/

## Reservation Before Work

Because NATS workers are async and horizontally scalable, RackLab must reserve quota and capacity before publishing deployment jobs. Otherwise concurrent requests could exceed quota before workers update usage.

Source:

- NATS JetStream consumers: https://docs.nats.io/nats-concepts/jetstream/consumers

## Placement Signals

Proxmox exposes node, storage, network, and task concepts through its API and admin tooling. RackLab placement should use inventory facts and health snapshots rather than a round-robin-only strategy.

Source:

- Proxmox VE admin docs: https://pve.proxmox.com/pve-docs/

## Design Impact

- Quota reservation is a required state machine, not a UI calculation.
- Scheduling decisions should be stored as audit records with reason data.
- Placement should be plugin-driven but use a common score/explain interface.
- Resource release must be handled by cleanup and reconciliation, not only by happy-path delete.
