# Goals And Non-Goals

## Goals

- Replace the RLES user workflow with a modern self-service lab platform.
- Support singleton VMs and versioned multi-VM stacks.
- Let students self-service deployments within quota and RBAC policy.
- Let instructors publish stacks, deploy stacks for rosters, and manage student deployments created in a course context.
- Support project isolation, sharing, and self-service RBAC.
- Support strong audit logging for all meaningful user, system, provider, token, network, script, and admin actions.
- Use Laravel, PostgreSQL, NATS JetStream, Proxmox, and Composer.
- Make Proxmox the first provider while keeping the provider model replaceable.
- Support multiple Proxmox servers or clusters and spread load across them.
- Support Neutron-inspired networking concepts without requiring OpenStack.
- Support cloud-init, openQA-style console automation, network automation, and advanced scripts.
- Run untrusted student automation in isolated script worker pools.
- Provide a public API that can automate everything the UI can do.
- Provide SSE for live deployment, worker, script, quota, and provider status.
- Use strong typing, strong linting, strict CI, and Laravel-aware static analysis.
- Make Docker/Podman Compose the first-class operational model.

## Non-Goals

- RackLab is not an OpenStack distribution.
- RackLab does not need to expose the OpenStack Neutron API.
- RackLab does not need Kubernetes for normal operation.
- RackLab should not require microservices for small installs.
- RackLab should not require a SPA frontend.
- RackLab should not manage physical switches in the first design unless a provider/network plugin explicitly supports it.
- RackLab should not rely on provider logs alone for auditability.
