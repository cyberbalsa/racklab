# Catalog, Stacks, And Deployments Research

## Template And Clone Model

Proxmox cloud-init guidance recommends converting prepared cloud-init images into VM templates, then using linked clones for fast rollout. RackLab's catalog should model template sources, provider capability requirements, cloud-init compatibility, and clone/restore behavior.

Sources:

- Proxmox cloud-init support: https://pve.proxmox.com/wiki/Cloud-Init_Support
- Proxmox VE admin docs: https://pve.proxmox.com/pve-docs/

## Cloud-Init Inputs

Proxmox cloud-init supports user, network, meta, and vendor custom snippets through `cicustom`, and supports options such as SSH keys and passwords. Proxmox notes password injection is less safe than SSH key based access. RackLab's cloud-init wizard should guide users toward safer defaults.

Source:

- Proxmox cloud-init support: https://pve.proxmox.com/wiki/Cloud-Init_Support

## Image Build Pipelines

Packer has a Proxmox builder, which is useful for image/template pipelines. RackLab does not need to own image building initially, but catalog documentation should allow admins to reference templates built outside RackLab.

Source:

- Packer Proxmox ISO builder: https://developer.hashicorp.com/packer/integrations/hashicorp/proxmox/latest/components/builder/iso

## Deployment State

Provider actions may complete asynchronously and can fail partially. Proxmox tasks have task identifiers, and NATS JetStream is at-least-once in realistic failure modes. RackLab deployments need explicit state machines, idempotency keys, retry policy, and reconciliation.

Sources:

- NATS JetStream concepts: https://docs.nats.io/nats-concepts/jetstream
- Proxmox user management/API docs: https://pve.proxmox.com/pve-docs/chapter-pveum.html

## Design Impact

- Catalog versions should be immutable once published.
- Stack deployment plans should be previewable before execution.
- Deployment records should be created before provider jobs begin.
- Every provider task id should be persisted for audit and reconciliation.
- Console markdown panels should be part of catalog version content, not ad hoc VM metadata.
