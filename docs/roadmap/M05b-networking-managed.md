# M5b — Networking: Managed Routers / FIPs / Security Groups

**Status:** Not started.
**Estimated effort:** 3–4 weeks.
**Depends on:** M5a, M6 (quota dimensions for routers/FIPs/SG-rules are checked at create time).
**Unblocks:** complete Neutron-shaped tenant networking.

## Goal

Students and instructors create their own networks, routers, floating IPs, and security groups within approved policy. RackLab realizes these as the corresponding Proxmox SDN constructs (SDN routers, NAT gateways, firewall rules) or as RackLab-managed gateway VMs where SDN doesn't suffice. Provider drift in networking objects is detected by the reconciler and either repaired or surfaced as an admin alert.

## In scope

- PRD §09 — the writable half of Network Offerings (self-service network creation), `Router`, `FloatingIP`, `SecurityGroup` + `SecurityGroupRule`, `SubnetPool` for self-service.
- PRD §12 Proxmox provider — SDN object creation, firewall realization.
- PRD §19 — the remaining networking tables not delivered in M5a.

## Dependencies

- M5a — read-mostly networking + reachability + NIC attach.
- M6 — quota dimensions for routers / floating IPs / security-group rules / provider-direct NICs.

## Deliverables

- Self-service network creation: students/instructors create `Network` + `Subnet` rows from approved `SubnetPool` ranges with RBAC `network.create_private` / `network.create_nat`.
- `Router` realization: RackLab creates Proxmox SDN routers (where the cluster supports it) or RackLab-managed gateway VMs (where it doesn't). The provider plugin's capability flags decide.
- `FloatingIP` allocation: from an admin-configured pool; mapped to a `Port`; observable in the UI.
- `SecurityGroup` + `SecurityGroupRule` realization to Proxmox firewall rules per VM/network/cluster; unsupported security-group features are rejected at catalog-validation time with the right capability-flag error.
- Provider-drift detection for networking objects: scheduler-reconciler periodically diffs RackLab's expected state against Proxmox reality; surfaces `provider_drift` audit events with the drift detail.
- Provider-drift repair flow: admin GUI shows drift, offers a "repair" action that re-asserts RackLab's intent, or a "mark as authoritative" action that adopts the Proxmox-side state into RackLab.
- Quota enforcement: every networking action checks the relevant quota dimension before execution (extends M6).
- Audit events: network/subnet/port/router/floating-IP/SG/SG-rule create/update/delete, provider drift detected/repaired/adopted.

## Acceptance criteria

- [ ] A student creates a `Network` + `Subnet` from a `private-nat` offering they're allowed to use; quota deducts; the network is realized on Proxmox; the student can attach a deployment to it.
- [ ] A student attempts to create a `Network` requiring `network.create_private` without that permission; the create endpoint returns 403 with the audit-logged denial.
- [ ] An instructor creates a `Router` with two attached networks; RackLab provisions the SDN router (or gateway VM); inter-network traffic works as configured.
- [ ] A `FloatingIP` allocates from the pool; mapping it to a port makes the VM reachable from the external network the FIP belongs to; releasing the FIP returns it to the pool.
- [ ] A `SecurityGroup` with five rules realizes as five Proxmox firewall rules on the affected VMs/networks; modifying the SG updates the firewall rules within one reconciliation cycle.
- [ ] An admin manually changes a Proxmox SDN object outside RackLab; the reconciler detects the drift within the configured interval; an audit event fires; the admin GUI surfaces the drift with the repair/adopt actions.
- [ ] Hitting the quota for floating IPs returns a clear quota-limit error; the audit event references the limit.

## Test layers

- **Tiny / unit**: subnet allocation from a `SubnetPool`; security-group rule normalization; provider-drift diff algorithm.
- **Contract**: the writable network Protocol methods against the fake provider + Proxmox plugin; drift detection against fake "modified externally" provider state.
- **Integration**: full self-service create → realize → modify → drift-detect → repair against Pest + RefreshDatabase with Proxmox API mock covering SDN + firewall endpoints.
- **E2E**: instructor creates a project network, attaches a VM, modifies the security group, sees the firewall update; admin reviews a drift case and adopts the external change.

## Risks / open questions

- **EVPN configuration is hard and high-blast-radius**: a wrong EVPN config in a lab can break the upstream campus network. M5b ships read-mostly EVPN (consume admin-configured EVPN networks); plugin-managed EVPN object creation stays out of scope.
- **Provider drift adoption can mask security issues**: if an admin's "adopt drift" overrides a security-group rule that an attacker added directly on the Proxmox cluster, RackLab's audit trail records the adoption but the original direct-modification has no RackLab audit. Document that out-of-band Proxmox changes must be audited at the Proxmox level too.
- **IPAM scope**: PRD §20 lists IPAM as an open question (fully internal for v1, delegate to Proxmox where available, or plugin-based from the start). M5a/M5b assumed fully internal `SubnetPool` model — confirm before M5b implementation.

## Out of scope (deferred entirely)

- KubeVirt + Kube-OVN networking — future provider-plugin work, not core RackLab.
- Plugin-managed EVPN object creation.
- A network-topology visualization widget (Cytoscape.js via `cytoscape` mounted inside a Livewire 4 component per PRD §15, but the UI itself is M10a polish work).
- IPv6 as the default catalog stance — M5b supports IPv6 in the data model; making it the default is a release-hardening decision in M13d or later.
