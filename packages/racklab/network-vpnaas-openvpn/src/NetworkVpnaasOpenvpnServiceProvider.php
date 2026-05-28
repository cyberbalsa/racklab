<?php

declare(strict_types=1);

namespace Racklab\NetworkVpnaasOpenvpn;

use Illuminate\Support\ServiceProvider;
use Override;

final class NetworkVpnaasOpenvpnServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        // The OpenVPN realization logic lives in core (App\Networking\*).
        // This service provider is the capability gate: when the plugin is
        // enabled through `racklab plugin enable racklab/network-vpnaas-openvpn`,
        // PluginRegistry boots this provider and the capability becomes
        // discoverable. Disabling/uninstalling the plugin un-boots it.
    }

    public function boot(): void
    {
        //
    }
}
