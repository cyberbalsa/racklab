# Neutron-Inspired Networking Research

## Neutron Concepts To Borrow

Neutron provides a mature vocabulary for cloud networking: networks, subnets, ports, routers, floating IPs, external/provider networks, security groups, and quotas. RackLab should borrow these concepts internally without promising Neutron API compatibility.

Sources:

- Neutron networking overview: https://docs.openstack.org/neutron/latest/admin/intro-os-networking.html
- Networking API v2: https://docs.openstack.org/api-ref/network/v2/
- Neutron concepts: https://docs.openstack.org/neutron/queens/install/concepts.html

## Self-Service Networks

OpenStack distinguishes provider networks from self-service networks. Provider networks provide direct L2 connectivity and usually require admin-managed external infrastructure. Self-service networks allow projects to create private networks, often connected through routers and NAT to external networks.

Source:

- Neutron networking overview: https://docs.openstack.org/neutron/latest/admin/intro-os-networking.html

## Floating IPs And NAT

Neutron floating IPs are resources allocated from external networks and associated with internal ports. This informs RackLab's `FloatingIP` and port-forwarding model, even if Proxmox implementations may map to NAT rules or other provider-specific mechanisms.

Source:

- OpenStack Networking API floating IPs: https://docs.openstack.org/api-ref/network/v2/

## Proxmox SDN Mapping

Proxmox SDN uses zones, VNets, and subnets for separated guest networks. It supports use cases ranging from isolated private node-local networks to overlay networks across clusters. Proxmox SDN can use VXLAN and EVPN concepts, while standard Linux bridges and VLAN-aware bridges remain common.

Sources:

- Proxmox SDN: https://pve.proxmox.com/pve-docs/chapter-pvesdn.html
- Proxmox Datacenter Manager SDN integration: https://pdm.proxmox.com/docs/sdn-integration.html

## Design Impact

- RackLab should expose logical networks in stack templates, not raw bridge names.
- Network offerings should constrain what students can create.
- Direct provider network attachment should require explicit permission and quota.
- Provider plugins should advertise feature support for routers, NAT, security groups, floating IPs, and direct bridge attachment.
- Proxmox implementations must handle both simple bridge/VLAN setups and SDN VNet/VXLAN/EVPN setups.
