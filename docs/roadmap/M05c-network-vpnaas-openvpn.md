# M5c — Networking: OpenVPN VPNaaS For Isolated Networks

**Status:** Not started.
**Estimated effort:** 2–3 weeks.
**Depends on:** M5b, M6.
**Unblocks:** direct user access to isolated Stack networks without weakening management-plane isolation.

## Goal

Stacks can include a first-party VPNaaS component that gives authorized users direct Layer 2 access to an isolated project network through OpenVPN TAP. Each user receives their own client configuration, so group-project access is attributable and individually revocable. RackLab allocates the endpoint's public/service IP and random UDP port per deployed Stack network, audits all lifecycle events, and leaves browser SSH governed by `NetworkOffering.reachability`.

## In scope

- PRD §09 VPNaaS for isolated networks.
- PRD §19 `NetworkVpnEndpoint`, `NetworkVpnEndpointBinding`, `VpnClientProfile`, and `VpnSession` plugin models.
- PRD §08 Stack component integration for network-service components.
- PRD §06 RBAC permissions for VPN endpoint and profile lifecycle.

## Dependencies

- M5b managed networking — self-service networks, public/service IP pools, security groups, provider drift handling.
- M6 quotas — quota dimensions for VPN endpoint public/service IPs, UDP ports, and client profiles.
- M0 plugin lifecycle, audit, secret backend, and universal job ledger.
- M2 Stack deployment lifecycle — Stack components can request network-service provisioning.

## Deliverables

- `racklab-network-vpnaas-openvpn` plugin package in `packages/racklab/network-vpnaas-openvpn/`, with capability `network:vpnaas:openvpn:v1`.
- Stack component schema for "VPN access to this isolated network" with catalog validation against provider capability, quota, and allowed network offerings.
- `NetworkVpnEndpoint` lifecycle: create, start, stop, rotate, revoke, and release, tied to one deployed Stack network.
- `NetworkVpnEndpointBinding` realization: creates one provider binding per participating hypervisor node unless the provider advertises cluster-level L2 bridging.
- Public/service IP and port allocator: selects a dedicated per-hypervisor-node public/service IP from an admin pool and a random unused UDP port for each endpoint binding.
- OpenVPN TAP/bridge realization through the provider plugin, with firewall rules that expose only the allocated endpoint port and bridge only the selected isolated network.
- Per-user `VpnClientProfile` issuance: unique client cert/key material, owner attribution, expiry, revocation, audited owner-only download, and no shared group-project profiles. Project admins can rotate/revoke another user's profile but cannot download that user's private client key material.
- Automatic profile revocation when Project membership, share/guest grants, or RBAC bindings no longer authorize VPN access.
- `VpnSession` connection ledger for connect/disconnect events and troubleshooting metadata, gated by `network.vpnaas.session.read` plus audit-view permissions.
- Livewire UI: deployment detail VPN panel listing endpoint state, each user's own profile, download/revoke actions, and clear warnings that the profile gives direct network access.
- Filament admin UI: VPN public/service IP pools, endpoint health, stuck endpoint cleanup, profile revocation, and audit search filters.
- Audit events: endpoint create/start/stop/delete/rotate, port allocation, profile issue/download/revoke, session connect/disconnect, failed auth, and provider drift.

## Acceptance criteria

- [ ] A catalog author adds VPNaaS to a Stack's isolated network; deployment creates a running OpenVPN endpoint attached only to that network.
- [ ] RackLab allocates a random unused UDP port on a dedicated per-hypervisor-node public/service IP for each endpoint binding; a second Stack on the same node gets a different port and cannot collide.
- [ ] Two users in the same group Project each receive their own VPN client configuration for the same Stack. Revoking one user's profile disconnects and blocks only that user; the other user's profile continues to work. A Project admin can revoke either profile but cannot download either user's private client key material.
- [ ] Removing a user from the Project or revoking their share/guest grant automatically revokes that user's active VPN profiles for the Stack.
- [ ] A user connected through VPN can reach the isolated network at Layer 2, including ARP/broadcast traffic where the backend supports it, but cannot reach other Project networks or RackLab management services through that endpoint.
- [ ] A Stack Package export strips VPN profiles, public/service IP assignments, UDP ports, and generated certificate material; import requires fresh endpoint/profile provisioning.
- [ ] A deployment using `isolated_no_ingress` plus VPNaaS still shows browser SSH as unavailable unless it also includes a management-reachable jump host.
- [ ] Endpoint and profile lifecycle events are audit-visible from both the Project and admin audit views.

## Test layers

- **Tiny / unit**: port allocation collision avoidance; endpoint binding placement; profile revocation state machine; Stack component validation; audit payload shape.
- **Contract**: fake provider VPN endpoint realization; OpenVPN endpoint Protocol; quota checks for endpoint IP/port/profile dimensions.
- **Integration**: deploy isolated Stack with VPNaaS against Pest 4 + Testcontainers (Postgres + Redis) and an OpenVPN container fixture; issue two profiles; connect/disconnect; revoke one profile.
- **E2E**: group Project deploys Stack, two users download separate configs, one connects and reaches a VM, owner revokes the other profile, UI and audit reflect the state.

## Risks / open questions

- **Layer 2 VPN blast radius**: TAP exposes broadcast domains and is intentionally powerful. Default catalog policy should require instructor/admin approval before publishing VPNaaS-enabled Stacks.
- **Client support**: not every OpenVPN client supports TAP equally on every OS. Document supported clients during implementation and surface compatibility notes in the download UI.
- **Endpoint placement**: multi-node isolated networks require one endpoint binding per provider node unless the provider plugin declares cluster-level L2 bridging. Tests must cover both the per-node binding path and provider-capability rejection when neither mode is available.
- **Secret handling**: client configs contain private key material. Store encrypted config material through the secret backend, audit every download, and support forced rotation.

## Out of scope

- WireGuard or routed Layer 3 VPN variants — future VPNaaS plugin families.
- Making RackLab browser SSH use the user's VPN path — M9 remains management-plane only.
- Site-to-site VPNs between institutions.
- VPN access to provider-direct or campus networks by default; those require a separate admin-approved policy.
