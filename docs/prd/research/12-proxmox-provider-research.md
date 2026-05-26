# Proxmox Provider Research

## API And Python Client

Proxmox has an API viewer and documented required permissions per method. `proxmoxer` is the common Python client for Proxmox API access and is a practical first library for the provider plugin.

Sources:

- Proxmox API viewer reference from user management docs: https://pve.proxmox.com/pve-docs/chapter-pveum.html
- proxmoxer: https://github.com/proxmoxer/proxmoxer

## Cloud-Init

Proxmox supports cloud-init configuration for VMs and recommends converting prepared cloud images into templates for linked clone rollout. RackLab should integrate with this path for Linux and compatible guest images.

Source:

- Proxmox cloud-init support: https://pve.proxmox.com/wiki/Cloud-Init_Support

## Networking

Proxmox SDN defines zones, VNets, and subnets, and supports advanced virtual network separation. RackLab's Proxmox plugin needs to discover these objects and also support common non-SDN bridge/VLAN deployments.

Source:

- Proxmox SDN: https://pve.proxmox.com/pve-docs/chapter-pvesdn.html

## User Management And API Tokens

Proxmox supports multiple auth sources, role-based permissions, API tokens, and privilege-separated tokens. RackLab provider credentials should use limited Proxmox API tokens where possible and record the Proxmox task ids for audit.

Source:

- Proxmox user management: https://pve.proxmox.com/pve-docs/chapter-pveum.html

## Design Impact

- The provider plugin should persist VMID, node, storage, network binding, and UPID/task ids.
- RackLab should not require root@pam credentials.
- Provider permissions should be documented as part of setup.
- The provider plugin should expose capabilities for clone, snapshot restore, cloud-init, noVNC, SDN, and direct bridge attachment.
- Proxmox-specific objects should live in provider metadata, while RackLab objects remain provider-neutral.
