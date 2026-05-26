# Full Target Requirements Research

## Why Full Target Matters

RackLab spans several domains: identity, RBAC, compute deployment, network modeling, scripting, quotas, audit, APIs, and provider plugins. Capturing the full target up front prevents early decisions from closing off provider replacement, script isolation, or project-level sharing later.

## Cross-Domain Requirements

Research across Django, Proxmox, NATS, Neutron, OpenNebula, CloudStack, openQA, and container tooling supports the PRD's full target requirements:

- Django can provide a coherent product and auth model.
- NATS JetStream can support durable async work and replayable events.
- Proxmox exposes enough provider surface for VM lifecycle, networking, cloud-init, token auth, and console access.
- Neutron offers a proven vocabulary for networks, subnets, ports, routers, floating IPs, and security groups.
- OpenNebula and CloudStack validate quota and project patterns.
- openQA validates console-driven automation when network access is unavailable.
- Docker/Podman Compose validate small and medium operational models.

Sources:

- Django auth: https://docs.djangoproject.com/en/5.2/topics/auth/customizing/
- NATS JetStream: https://docs.nats.io/nats-concepts/jetstream
- Proxmox docs: https://pve.proxmox.com/pve-docs/
- Neutron docs: https://docs.openstack.org/neutron/latest/admin/intro-os-networking.html
- OpenNebula quotas: https://docs.opennebula.io/7.0/product/cloud_system_administration/capacity_planning/quotas/
- CloudStack projects: https://docs.cloudstack.apache.org/en/latest/adminguide/projects.html
- openQA docs: https://open.qa/docs/
- Docker Compose services: https://docs.docker.com/reference/compose-file/services/
- Podman Compose: https://docs.podman.io/en/stable/markdown/podman-compose.1.html

## Design Impact

- The PRD should stay full target, but implementation planning should later phase risk-heavy areas.
- Early data models must include audit, quota reservation, plugin capability, and network intent even if early UI is simple.
- The API should be public from the start so automation does not become a later retrofit.
