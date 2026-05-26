# Data Model Outline Research

## Scope And Identity

Django auth provides users, groups, permissions, and custom auth backends. RackLab needs additional scope models for organizations, courses, projects, memberships, role bindings, invitations, and guest grants.

Source:

- Django auth customization: https://docs.djangoproject.com/en/5.2/topics/auth/customizing/

## Object Permissions

Object-level permissions require durable objects for role bindings or object permission grants. django-guardian supports object permissions, but RackLab should still keep domain-specific `RoleBinding` records so the UI and audit trail can explain why a user has access.

Source:

- django-guardian: https://django-guardian.readthedocs.io/en/latest/

## Network Model

Neutron validates the core data model: networks, subnets, ports, routers, floating IPs, security groups, subnet pools, and quotas. RackLab should use a similar internal model but add provider mapping and network offering objects.

Sources:

- Neutron API v2: https://docs.openstack.org/api-ref/network/v2/
- Neutron overview: https://docs.openstack.org/neutron/latest/admin/intro-os-networking.html

## Quota Model

OpenNebula and CloudStack both separate quota policy from usage. RackLab additionally needs quota reservations because deployment is asynchronous.

Sources:

- OpenNebula quotas: https://docs.opennebula.io/7.0/product/cloud_system_administration/capacity_planning/quotas/
- CloudStack projects: https://docs.cloudstack.apache.org/en/latest/adminguide/projects.html

## Job/Event Model

JetStream message delivery is at-least-once in practical failure cases, so RackLab needs database-backed job, job step, state transition, and event models with idempotency and retry tracking.

Sources:

- NATS JetStream concepts: https://docs.nats.io/nats-concepts/jetstream
- NATS consumers: https://docs.nats.io/nats-concepts/jetstream/consumers

## Design Impact

- Do not model deployment state only as a status string; use state transition/event records.
- Use immutable version objects for catalog and scripts.
- Store provider-specific ids in binding/resource metadata objects.
- Store token grant records server-side even though clients receive JWTs.
- Keep audit events append-oriented and queryable.
