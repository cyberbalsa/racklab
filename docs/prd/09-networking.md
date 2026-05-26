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
- `isolated_no_ingress` — no path from the management plane to any VM on this network. SSH console is not offered for deployments using this offering. Catalog templates that need both this offering and SSH must spin up an instructor-controlled jump host inside the network as part of the stack; the SSH console plugin then targets that jump host, not arbitrary VMs.

The reachability capability is admin-configured on each `NetworkOffering` and is the single source of truth for which deployments expose the SSH console plugin. The capability is published into the catalog so catalog authors and deployment-time UI can show "SSH supported / not supported" before the user commits.

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

Quota dimensions include:

- Networks.
- Subnets.
- Ports.
- Routers/NAT gateways.
- Floating/public IPs.
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
