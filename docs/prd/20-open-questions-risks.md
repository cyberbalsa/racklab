# Open Questions And Risks

## Open Questions

- Which storage backend should be recommended for artifacts in production: filesystem, S3-compatible storage, or both equally?
- Should RackLab ship a real `openqa`/`os-autoinst` runner plugin early, or start with a native openQA-inspired console automation runner?
- Which JWT signing algorithm and key management approach should be the default for small installs?
- How much Proxmox SDN object management should be supported beyond consuming configured networks?
- Should IPAM be fully internal for v1 networking, delegated to Proxmox where available, or plugin-based from the start?
- Should direct provider network attachment require instructor/admin approval even when a student role grants the permission?
- What is the default retention policy for audit records, verbose logs, screenshots, and script artifacts?
- Should service tokens be allowed at global scope by default, or only project/course scope?

## Risks

### Network Safety

Provider-direct networking can expose student VMs to sensitive real networks. This requires conservative defaults, explicit RBAC, quotas, and audit.

### Script Execution

Student-authored scripts are useful but high risk. Dedicated workers, hardened sandbox profiles, approval workflow, and redaction are required.

### RBAC Complexity

Fine-grained object permissions can become difficult to reason about. The product needs clear role presets, explainable denials, and regression tests.

### Provider Drift

Admins may change Proxmox resources outside RackLab. Reconciliation must detect drift and either repair it, mark it, or block unsafe actions.

### Token Delegation

Tokens with delegated RBAC can become powerful. Token grants need expiration, revocation, scoping, audit, and clear UI warnings.

### Plugin Safety

Plugins add power and risk. The core needs stable contracts, health isolation, audit, and controlled extension points.

### Scale And State

NATS provides durable job/event flow, but PostgreSQL remains the source of truth. The design must avoid split-brain state between bus messages and database records.
