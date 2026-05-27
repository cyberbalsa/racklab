# M5a — Networking: Attach + Reachability

**Status:** Not started.
**Estimated effort:** 2–3 weeks.
**Depends on:** M3.
**Unblocks:** M5b, M6, M9.

## Goal

RackLab can attach deployments to admin-published provider networks and persist the reachability information later consumed by the SSH and UI surfaces. M5a deliberately avoids writable tenant networking: administrators define the provider-backed offerings, catalog authors select them, and deployment resources record the resolved NIC binding and management reachability.

## In scope

- PRD §09 networking — `NetworkOffering`, provider network mappings, network attach/detach, and the reachability capability primitive.
- PRD §12 Proxmox provider — NIC attach against admin-existing bridges, VLANs, VNets, and SDN zones.
- PRD §19 data model — `ProviderNetwork`, `NetworkOffering`, deployment network binding rows, and the read-mostly subset of `Network` / `Subnet` needed to represent admin-published networks.
- The reachability contract required by M9: `routable_from_management`, `nat_from_management`, `isolated_no_ingress`.

## Dependencies

- M3 Proxmox provider — exposes the typed provider facade and capability discovery.
- M2 deployment lifecycle — `DeploymentResource` exists and can be extended with network bindings.
- M0 audit + RBAC primitives — network-offering actions are permissioned and audited.

## Deliverables

- `racklab/networking` Laravel module with the M5a model subset: `ProviderNetwork`, `NetworkOffering`, admin-published `Network` / `Subnet`, and `DeploymentNetworkBinding`.
- `NetworkOffering.reachability` field with values `routable_from_management`, `nat_from_management`, `isolated_no_ingress`. This is the single source of truth for SSH availability.
- Provider capability flags for network attach: bridge, VLAN tag, VNet, SDN zone, NAT gateway metadata, and management reachability.
- `Provider.network_attach()` and `Provider.network_detach()` operations on the provider Protocol; Proxmox implementation hooks VM NICs into the resolved provider network.
- Catalog validation: a catalog item referencing an unsupported offering is rejected before deployment with a clear capability error.
- Deployment flow extension: catalog templates declare required network offerings; deployment creation resolves them into `DeploymentNetworkBinding` rows and persists reachability details.
- Admin UI: network-offering management page for creating offerings, selecting reachability, and mapping to provider networks discovered from Proxmox.
- Student/instructor UI: deployment detail shows attached networks and whether management SSH is available, NAT-routed, or blocked.
- Audit events for offering create/update/delete, provider-network discovery, attach/detach, validation denial, and reachability resolution.

## Acceptance criteria

- [ ] An admin defines a `private-isolated` offering with reachability `isolated_no_ingress`; a student deploying with that offering sees "SSH not available" on the deployment-detail page (preview for M9).
- [ ] An admin defines a `provider-direct` offering with reachability `routable_from_management`; a deployment using it has its Proxmox NIC attached to the expected bridge/VLAN/VNet.
- [ ] An admin defines a `private-nat` offering with reachability `nat_from_management`; deployment binding records the NAT gateway address + port metadata that M9 will consume.
- [ ] Catalog validation rejects an offering whose mapping is unsupported by the selected provider cluster capability flags.
- [ ] Network attach and detach are idempotent and reconciliation-safe: rerunning attach does not create duplicate NICs, and detach removes only the NIC RackLab owns.
- [ ] A user without network-offering administration permission receives a 403 when trying to create or update an offering; the denial is audit-logged.

## Test layers

- **Tiny / unit**: reachability-capability resolution; catalog validation predicate; provider-network mapping normalization.
- **Contract**: network provider Protocol against fake provider + Proxmox plugin mock; attach/detach idempotency contract.
- **Integration**: deployment create → resolve offering → attach NIC → detach NIC against Pest 4 + Testcontainers (real Postgres + Redis) with Proxmox API mock covering bridge/VLAN/VNet endpoints.
- **E2E**: admin publishes an offering, student deploys a VM attached to it, deployment detail shows network bindings and the SSH availability preview.

## Risks / open questions

- **Provider-direct exposure risk**: student workloads on campus networks can affect real traffic. Default policy should require instructor/admin approval for provider-direct offerings.
- **Proxmox SDN version drift**: PVE 8.x and 9.x SDN behavior differs; capability flags must gate features instead of assuming a uniform cluster.
- **NAT metadata ownership**: M5a records NAT reachability for admin-published NAT gateways. M5b owns creating and reconciling RackLab-managed NAT gateways.
- **IPAM scope**: M5a avoids full tenant IPAM. Self-service `SubnetPool` allocation lands in M5b.

## Out of scope (deferred)

- Self-service tenant network creation — M5b.
- RackLab-created routers, floating IPs, security groups, and writable Proxmox SDN objects — M5b.
- Provider-drift repair/adoption for networking objects — M5b.
- Physical switch management — explicit PRD non-goal.
- KubeVirt + Kube-OVN networking — future provider plugin, not v1 core.
