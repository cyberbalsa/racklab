# Neutron-Inspired Networking

RackLab networking is Neutron-inspired, not Neutron-compatible. It uses cloud networking concepts internally while provider plugins map those concepts to real backend capabilities.

## Core Objects

- `ProviderNetwork`: admin-published physical or backend network, such as a Proxmox bridge, VLAN-aware bridge, SDN VNet, VXLAN, EVPN network, or campus/lab network.
- `Network`: project-scoped L2 network, either created by RackLab or mapped to a provider network.
- `Subnet`: CIDR, gateway, DNS, DHCP/IPAM settings, allocation pool, and route metadata.
- `Port`: VM NIC attachment with MAC, fixed IPs, security groups, and backend binding metadata.
- `Router`: optional L3/NAT object connecting private project networks to provider or external networks.
- `FloatingIP`: optional externally reachable IP or port-forward mapping.
- `SecurityGroup`: optional port-level firewall policy where backend support exists.
- `SubnetPool`: admin-approved address pool for self-service subnets.
- `NetworkOffering`: admin-defined productized network choice available to projects, courses, roles, or catalog items.
- `NetworkVpnEndpoint`: plugin-provided VPN endpoint attached to a project network, used when a Stack explicitly includes VPN access.
- `VpnClientProfile`: per-user VPN client configuration for one endpoint; never shared between group members.

## Network Offerings

Network offerings describe what users can create or attach to.

Required offering types:

- `private-isolated`: project-private L2 network without external connectivity.
- `private-nat`: private subnet with outbound NAT to an approved external/provider network.
- `double-nat`: private subnet behind a RackLab/provider NAT layer that exits through an already-NATed site network.
- `provider-direct`: VM NIC attached directly to an admin-approved campus, lab, or main network.
- `template-defined`: catalog or stack template creates required networks automatically from policy.

`provider-direct` is more privileged than private/NAT networks because it can expose arbitrary student VMs to important real networks.

## Reachability Capability

Each `NetworkOffering` advertises a **reachability capability** that determines what RackLab management-plane services (notably the SSH console plugin) can do with VMs on that network:

- `routable_from_management` — RackLab's management hosts can reach guest IPs directly. Default for `provider-direct` and for `private-nat` / `double-nat` offerings whose router has a documented route from the management network. The SSH console plugin can SSH straight to the guest IP.
- `nat_from_management` — RackLab's management hosts cannot reach guest IPs directly, but the network's router exposes an SSH-port-forward at a stable address. The SSH console plugin connects to the router's exposed port. Used for default `private-nat` deployments where exposing routes from management would violate isolation.
- `isolated_no_ingress` — no path from the management plane to any VM on this network. SSH console is not offered for deployments using this offering. Catalog templates that need both this offering and browser SSH must spin up an instructor-controlled jump host inside the network as part of the stack; the SSH console plugin then targets that jump host, not arbitrary VMs. A Stack may separately include VPNaaS for user-side direct access, but that does not change management-plane reachability.

The reachability capability is admin-configured on each `NetworkOffering` and is the single source of truth for which deployments expose the SSH console plugin. The capability is published into the catalog so catalog authors and deployment-time UI can show "SSH supported / not supported" before the user commits.

## VPNaaS For Isolated Networks

Isolated-network VPN access is delivered by a first-party plugin, `racklab-network-vpnaas-openvpn`. A Stack may include a VPNaaS component that attaches an OpenVPN TAP endpoint to a selected isolated network, giving authorized users direct Layer 2 access to that network from their own VPN client.

Each VPN endpoint is scoped to one deployed Stack network. The logical endpoint has one or more per-node bindings. Each binding allocates a random unused UDP port and binds it to a dedicated per-hypervisor-node public/service IP for that isolated network. The public/service IP comes from an admin-managed pool, and the provider plugin opens only the assigned VPN port for that endpoint binding. For isolated networks spanning multiple hypervisor nodes, the provider plugin must either create one binding per participating node or explicitly advertise a provider-level L2 bridge mode that makes one binding sufficient. The endpoint bridges only the selected isolated network; it does not create general management-plane ingress, cross-project connectivity, or access to other Stack networks.

Group projects require per-user VPN client profiles. Each authorized user receives their own OpenVPN client configuration with unique certificate/key material, owner attribution, expiry, and revocation state. Profiles are never shared between users. Downloading the client configuration is owner-only: project admins and instructors can issue, rotate, and revoke another user's profile when RBAC allows, but they cannot retrieve another user's private client key material. Removing a user from the Project, revoking a share/guest grant, or otherwise losing the role that authorized VPN access automatically revokes that user's active VPN profiles for the Stack. Revoking one user's profile does not affect other group members attached to the same Stack, and every profile issue, download, connect, disconnect, and revoke event is audited.

VPNaaS changes the user's client-side reachability, not RackLab's management-plane reachability. Users can reach VMs directly through their VPN client when the guest firewall and Project SSH keys allow it. RackLab browser SSH still follows `NetworkOffering.reachability`; `isolated_no_ingress` remains unavailable to the SSH console plugin unless the Stack includes a jump host or another management-reachable path.

## RBAC And Quota

Networking actions are controlled by RBAC and quota.

Example permissions:

- `network.create_private`
- `network.create_nat`
- `network.create_router`
- `network.attach_provider`
- `network.allocate_public_ip`
- `network.manage_security_group`
- `network.share`
- `network.vpnaas.endpoint.read`
- `network.vpnaas.endpoint.create`
- `network.vpnaas.endpoint.update`
- `network.vpnaas.endpoint.delete`
- `network.vpnaas.profile.read`
- `network.vpnaas.profile.create`
- `network.vpnaas.profile.update`
- `network.vpnaas.profile.delete`
- `network.vpnaas.profile.download`
- `network.vpnaas.profile.revoke`
- `network.vpnaas.session.read`

`network.vpnaas.profile.download` is additionally constrained by ownership: it only authorizes downloading the caller's own client configuration. Administrative permissions can rotate or revoke another user's profile, but they never expose that user's private client key material.

Quota dimensions include:

- Networks.
- Subnets.
- Ports.
- Routers/NAT gateways.
- Floating/public IPs.
- VPN endpoint public/service IPs and UDP ports.
- VPN client profiles.
- Provider-direct NICs.
- Security group rules.

## Provider Mapping

Provider plugins advertise network capabilities. Proxmox can map RackLab intent to:

- Linux bridges.
- VLAN-aware bridges.
- VLAN tags.
- Proxmox SDN zones.
- Proxmox VNets.
- Proxmox subnets.
- VXLAN.
- EVPN.
- Provider firewall/IPAM capabilities where available.

RackLab should consume configured Proxmox networking safely. Optional future plugins may manage Proxmox SDN objects directly where an admin enables that capability.
