# Users And Personas Research

## Student Persona

The RLES baseline focuses on the student path: select a catalog VM, deploy it, wait for status, power it on, and connect through a remote console. RackLab extends that same workflow with quota, projects, sharing, stacks, and self-service networking.

Source:

- RLES slide deck: https://www.se.rit.edu/~swen-440/slides/instructor-specific/Kiser/RLES.pdf

## Instructor Persona

Cloud platforms show that project-level administration and resource limits are useful for delegated operation. CloudStack projects can have multiple project administrators and can track usage at project level. RackLab should apply similar delegation to course contexts: instructors publish stacks, deploy for rosters, and manage student deployments for their course.

Source:

- CloudStack projects: https://docs.cloudstack.apache.org/en/latest/adminguide/projects.html

## Administrator Persona

Proxmox and OpenNebula both expose concepts that administrators must manage: users, roles, tokens, clusters/nodes, storage, networking, and quotas. RackLab admins need purpose-built screens for these areas rather than relying only on Django admin.

Sources:

- Proxmox user management: https://pve.proxmox.com/pve-docs/chapter-pveum.html
- OpenNebula quotas: https://docs.opennebula.io/7.0/product/cloud_system_administration/capacity_planning/quotas/

## Guest Persona

Django supports anonymous users in auth backend logic, but RackLab guest access should be represented as explicit signed grants with scope, expiry, revocation, and audit. Guest links should not become anonymous broad permission grants.

Source:

- Django auth customization: https://docs.djangoproject.com/en/5.2/topics/auth/customizing/

## Design Impact

- Each persona needs a distinct dashboard and common service layer.
- Course context is not just a tag; it changes instructor visibility and authority.
- Guest access must be a tokenized grant object, not a special-case bypass.
