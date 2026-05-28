<?php

declare(strict_types=1);

use App\Models\PluginInstallation;
use App\Plugins\PluginRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Racklab\NetworkVpnaasOpenvpn\Manifest;
use Racklab\NetworkVpnaasOpenvpn\NetworkVpnaasOpenvpnServiceProvider;

uses(RefreshDatabase::class);

it('drives the network-vpnaas-openvpn plugin through the full racklab lifecycle', function (): void {
    $this->artisan('racklab plugin install racklab/network-vpnaas-openvpn')->assertExitCode(0);

    $installation = PluginInstallation::query()->where('slug', 'racklab/network-vpnaas-openvpn')->firstOrFail();
    expect($installation->state)->toBe('installed')
        ->and($installation->service_provider)->toBe(NetworkVpnaasOpenvpnServiceProvider::class);

    $this->artisan('racklab plugin migrate racklab/network-vpnaas-openvpn')->assertExitCode(0);
    expect($installation->refresh()->state)->toBe('migrated');

    $this->artisan('racklab plugin enable racklab/network-vpnaas-openvpn')->assertExitCode(0);
    expect($installation->refresh()->state)->toBe('enabled')
        ->and(app(PluginRegistry::class)->enabledPlugins())->toHaveKey('racklab/network-vpnaas-openvpn');

    $this->artisan('racklab plugin disable racklab/network-vpnaas-openvpn')->assertExitCode(0);
    expect($installation->refresh()->state)->toBe('disabled')
        ->and(app(PluginRegistry::class)->enabledPlugins())->toBe([]);

    $this->artisan('racklab plugin uninstall racklab/network-vpnaas-openvpn')->assertExitCode(0);
    expect(PluginInstallation::query()->where('slug', 'racklab/network-vpnaas-openvpn')->exists())->toBeFalse();
});

it('declares the network:vpnaas:openvpn:v1 capability in its manifest', function (): void {
    $manifest = new Manifest;

    expect($manifest->slug())->toBe('racklab/network-vpnaas-openvpn')
        ->and($manifest->capabilities())->toBe(['network:vpnaas:openvpn:v1'])
        ->and($manifest->name())->toBe('RackLab OpenVPN VPNaaS');
});

it('rejects VPN endpoint creation with 503 when the plugin is not enabled', function (): void {
    // Codex M5c S6 P2-1: the racklab/network-vpnaas-openvpn plugin is the
    // operator-facing capability gate. Without explicitly enabling it the
    // endpoint create endpoint returns 503, even when the actor has the
    // role + token ability.
    app(App\Rbac\RbacDefaultsSynchronizer::class)->sync();
    $tenant = App\Models\Tenant::query()->firstOrCreate(['slug' => 'default'], ['name' => 'Default Tenant']);
    $user = App\Models\User::factory()->create(['name' => 'Gate Tester', 'email' => 'gate-tester@example.test']);
    $context = new App\Domain\Tenancy\TenantContext(activeTenantId: $tenant->getKey());
    app(App\Domain\Tenancy\TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    $project = app(App\Identity\PersonalProjectProvisioner::class)->ensureFor($user, $context);
    app(App\Domain\Tenancy\TenantContextStore::class)->forget();
    App\Models\Tenant::forgetCurrent();

    Laravel\Sanctum\Sanctum::actingAs($user);
    test()->postJson('/api/v1/network-vpn-endpoints', [
        'name' => 'gate-vpn',
        'project_id' => $project->getKey(),
        'network_id' => '01HZUNKNOWN0000000000000000',
        'vpn_public_ip_pool_slug' => 'nonexistent',
    ])->assertStatus(503);
});
