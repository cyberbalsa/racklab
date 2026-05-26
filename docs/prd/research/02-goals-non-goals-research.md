# Goals And Non-Goals Research

## Django As Product Control Plane

Django provides built-in authentication, permissions, sessions, admin, model validation, migrations, and security features. This supports a single coherent control plane for small installs while still allowing multiple worker processes and container replicas for larger deployments.

Sources:

- Django auth customization: https://docs.djangoproject.com/en/5.2/topics/auth/customizing/
- Django auth reference: https://docs.djangoproject.com/en/5.2/ref/contrib/auth/
- Django security overview: https://docs.djangoproject.com/en/5.2/topics/security/

## NATS JetStream For Async Work

NATS JetStream persists messages and supports replay, retention policies, pull consumers, acknowledgments, and clustering. Pull consumers can be shared by multiple workers, which fits RackLab's worker pool model for provider, script, console, cleanup, and notification jobs.

Sources:

- NATS JetStream concepts: https://docs.nats.io/nats-concepts/jetstream
- NATS consumers: https://docs.nats.io/nats-concepts/jetstream/consumers
- NATS streams and work queue retention: https://docs.nats.io/nats-concepts/jetstream/streams

## Proxmox First, Not Proxmox Only

Proxmox has APIs, SDN, cloud-init support, API tokens, RBAC, clusters, templates, snapshots, and noVNC console integration. It is a practical first provider. The PRD's plugin-first goal prevents Proxmox-specific assumptions from leaking into the catalog, quota, API, and RBAC model.

Sources:

- Proxmox VE admin guide: https://pve.proxmox.com/pve-docs/
- Proxmox SDN: https://pve.proxmox.com/pve-docs/chapter-pvesdn.html
- Proxmox cloud-init support: https://pve.proxmox.com/wiki/Cloud-Init_Support
- Proxmox user management and API tokens: https://pve.proxmox.com/pve-docs/chapter-pveum.html

## Non-Goal Validation

Neutron and OpenStack provide strong cloud abstractions, but OpenStack is operationally much heavier than the requested small-install target. RackLab should use Neutron-inspired concepts without implementing the Neutron API unless a later integration requires it.

Sources:

- Neutron overview: https://docs.openstack.org/neutron/latest/admin/intro-os-networking.html
- Networking API v2: https://docs.openstack.org/api-ref/network/v2/

## Design Impact

- Django monolith plus async workers is the right control-plane shape.
- NATS is a durable work/event backbone, not the system of record.
- Proxmox is implemented as a provider plugin from the beginning.
- RackLab's "cloud" ideas should improve lab usability, not force cloud-platform complexity.
