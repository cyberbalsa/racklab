# Executive Summary Research

## Baseline RLES Pattern

The RLES slide deck describes a lightweight educational VM request flow: log in, browse available virtual machines, request a deployment, wait for progress notification, then open the deployment page to power the VM and use a remote console.

RackLab preserves that simple path but expands it into a self-service platform with catalog stacks, quotas, RBAC, audit, plugins, and async deployment workers.

Source:

- RLES slide deck: https://www.se.rit.edu/~swen-440/slides/instructor-specific/Kiser/RLES.pdf

## Comparable Cloud And Lab Systems

OpenNebula has mature concepts for user/group quotas, virtual networks, and virtual data center style isolation. Its quota docs show that quotas should cover compute, storage, network IP usage, images, and group/user scopes. This supports RackLab's quota design across users, projects, courses, provider networks, catalog items, and leases.

Sources:

- OpenNebula quotas: https://docs.opennebula.io/7.0/product/cloud_system_administration/capacity_planning/quotas/
- OpenNebula virtual networks: https://docs.opennebula.io/7.0/product/cluster_configuration/networking_system/manage_vnets/

CloudStack projects provide a useful comparison for project-scoped resource ownership, project administrators, invitations, and project-level resource limits. RackLab should use that cloud control-plane pattern while keeping the implementation Django-centered.

Sources:

- CloudStack projects: https://docs.cloudstack.apache.org/en/latest/adminguide/projects.html
- CloudStack quota plugin: https://docs.cloudstack.apache.org/en/4.20.2.0/plugins/quota.html

Ravada VDI is an example of an open-source VDI/lab style product focused on user access to virtual desktops. It reinforces that educational/lab users need simple access and role/group concepts, but RackLab needs broader stack, network, script, and provider-plugin capabilities.

Source:

- Ravada VDI documentation: https://ravada.readthedocs.io/en/latest/index.html

## Design Impact

- Keep the student workflow simple even if the backend is advanced.
- Treat project/course ownership and quotas as core product objects.
- Avoid making RackLab a thin Proxmox UI; it needs its own policy, audit, and lifecycle model.
- Avoid making RackLab a full OpenStack replacement; it borrows useful cloud concepts without inheriting OpenStack operational weight.
