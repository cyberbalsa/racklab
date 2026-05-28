<?php

declare(strict_types=1);

if (! function_exists('enableVpnaasPluginForTests')) {
    /**
     * Walk the racklab/network-vpnaas-openvpn plugin through install →
     * migrate → enable so VPNaaS controllers pass the capability gate
     * (codex M5c S6 P2-1). Idempotent: re-invoking when the plugin is
     * already enabled is a no-op.
     */
    function enableVpnaasPluginForTests(): void
    {
        $slug = 'racklab/network-vpnaas-openvpn';

        $installation = App\Models\PluginInstallation::query()->where('slug', $slug)->first();

        if ($installation instanceof App\Models\PluginInstallation && $installation->state === 'enabled') {
            return;
        }

        if (! $installation instanceof App\Models\PluginInstallation) {
            Illuminate\Support\Facades\Artisan::call('racklab plugin install '.$slug);
        }

        $installation = App\Models\PluginInstallation::query()->where('slug', $slug)->firstOrFail();

        if ($installation->state === 'installed') {
            Illuminate\Support\Facades\Artisan::call('racklab plugin migrate '.$slug);
            $installation = $installation->refresh();
        }

        if ($installation->state === 'migrated') {
            Illuminate\Support\Facades\Artisan::call('racklab plugin enable '.$slug);
        }
    }
}
