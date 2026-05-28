# racklab/console-proxmox

RackLab plugin that gates the Proxmox console capability (`console:proxmox:v1`)
through the standard `racklab plugin install|migrate|enable|disable|uninstall`
lifecycle.

The actual `ProxmoxConsoleProxy` implementation lives in
`App\Providers\Proxmox\ProxmoxConsoleProxy` for tight coupling with the
existing Proxmox provider code. This plugin exists so that an operator can
explicitly enable or disable Proxmox console access without changing
`RACKLAB_CONSOLE_PROXY`, and so that the capability surface is discoverable in
the plugin manifest.

See `docs/superpowers/specs/2026-05-28-m4-console-proxmox-design.md` for the
full M4 design.
