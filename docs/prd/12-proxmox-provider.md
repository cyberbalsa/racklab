# Proxmox Provider

Proxmox is the first backend provider.

## Provider Scope

The Proxmox plugin supports:

- Multiple independent Proxmox servers.
- Multiple Proxmox clusters.
- Inventory discovery.
- Node health and capacity collection.
- Storage discovery.
- Network discovery.
- Template discovery.
- VM clone operations.
- Snapshot restore operations.
- VM power actions.
- VM console session setup.
- VM metadata and tags.
- Proxmox task tracking.
- Drift reconciliation.
- Placement facts for the scheduler.

## Deployment Modes

The plugin supports:

- Clone from template.
- Restore from snapshot where supported.
- Cloud-init customization.
- Post-processing through RackLab script runners.
- Existing VM import where admin policy allows it.

## Networking

The Proxmox provider maps RackLab's Neutron-inspired model to configured Proxmox capabilities:

- Bridges.
- VLAN-aware bridges.
- VLAN tags.
- SDN zones.
- VNets.
- Subnets.
- VXLAN.
- EVPN.
- NAT.
- Firewall/IPAM capabilities where available.

The plugin must advertise capability flags so catalog validation can detect unsupported network requirements before deployment.

## Placement

Proxmox placement considers:

- Cluster/server availability.
- Node health.
- Storage availability.
- Template locality.
- Network availability.
- CPU/memory pressure.
- Admin tags.
- Course/project affinity.
- Anti-affinity.
- Maintenance state.

## Console

The initial console backend supports two Proxmox-native console paths through one plugin (`racklab-console-proxmox`):

- **noVNC** for KVM (QEMU) graphical consoles. Proxmox's `vncproxy` → `vncwebsocket` ticket flow is brokered through RackLab; the browser embeds noVNC under RackLab's console pane.
- **xterm.js** for LXC container consoles and KVM serial consoles. Proxmox exposes a websocket terminal feed (`termproxy` → `vncwebsocket`) consumed by xterm.js in the browser. This is the standard Proxmox approach for non-graphical consoles.

The two paths share the same `ConsoleAccessGrant` flow (short-lived, scoped, audit-logged) and the same in-page chrome (markdown instructions, share controls, focus-release shortcut for keyboard users — see UI/UX accessibility requirements). The choice of noVNC vs xterm.js is a per-target capability flag the provider reports; the console pane picks the right renderer.

Optional future console plugins can support Apache Guacamole, SPICE, SSH, RDP, or other brokered access modes. SPICE is deferred because RackLab v1 prioritizes browser-native console paths. A future SPICE plugin may be added when an operator has a specific client-based desktop-console requirement.

## Reliability

All Proxmox operations must:

- Be idempotent where possible.
- Persist Proxmox task identifiers.
- Reconcile final state after provider task completion.
- Handle retryable provider failures.
- Detect partial deployment state.
- Avoid leaking provider credentials to script workers or user-visible logs.
