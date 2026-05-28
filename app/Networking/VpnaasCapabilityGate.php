<?php

declare(strict_types=1);

namespace App\Networking;

use App\Plugins\PluginRegistry;
use Throwable;

/**
 * Gates VPNaaS operations on the `racklab/network-vpnaas-openvpn` plugin
 * being enabled in the RackLab plugin lifecycle (PluginInstallation
 * state=enabled).
 *
 * The endpoint create + profile create controllers consult this gate as the
 * outer fence: even if a user has every required role + token ability,
 * VPNaaS is unavailable until the operator explicitly enables the plugin
 * via `racklab plugin enable racklab/network-vpnaas-openvpn`. This matches
 * the console-proxmox capability-gating model (codex M5c S6 P2-1).
 *
 * The gate fails open if the plugin registry isn't bootable (e.g. during
 * pre-migration container startup), so initial install / migrate flows
 * still work. Once the schema is in place, the registry-backed check
 * becomes authoritative.
 */
final readonly class VpnaasCapabilityGate
{
    public const string PLUGIN_SLUG = 'racklab/network-vpnaas-openvpn';

    public function __construct(private PluginRegistry $registry) {}

    public function isEnabled(): bool
    {
        try {
            $enabled = $this->registry->enabledPlugins();
        } catch (Throwable) {
            // Pre-migration boot: assume disabled. Endpoint controllers will
            // return 503-shaped errors, which is the correct behaviour.
            return false;
        }

        return isset($enabled[self::PLUGIN_SLUG]);
    }
}
