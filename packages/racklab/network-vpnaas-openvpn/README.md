# racklab/network-vpnaas-openvpn

RackLab plugin that gates the OpenVPN VPNaaS capability
(`network:vpnaas:openvpn:v1`) through the standard `racklab plugin
install|migrate|enable|disable|uninstall` lifecycle.

The endpoint allocator, profile lifecycle, and session ledger live in
core (`App\Networking\*`) — they're not OpenVPN-specific. This plugin
exists so that an operator can explicitly enable or disable VPNaaS
without touching the env / config, and so that the capability surface
is discoverable in the plugin manifest.

See `docs/superpowers/specs/` and `docs/roadmap/M05c-network-vpnaas-openvpn.md`
for the full M5c design.
