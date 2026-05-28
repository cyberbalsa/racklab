<?php

declare(strict_types=1);

use App\Models\PluginInstallation;
use App\Plugins\PluginRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Racklab\ConsoleProxmox\ConsoleProxmoxServiceProvider;
use Racklab\ConsoleProxmox\Manifest;

uses(RefreshDatabase::class);

it('drives the console-proxmox plugin through the full racklab lifecycle', function (): void {
    $this->artisan('racklab plugin install racklab/console-proxmox')->assertExitCode(0);

    $installation = PluginInstallation::query()->where('slug', 'racklab/console-proxmox')->firstOrFail();
    expect($installation->state)->toBe('installed')
        ->and($installation->service_provider)->toBe(ConsoleProxmoxServiceProvider::class);

    $this->artisan('racklab plugin migrate racklab/console-proxmox')->assertExitCode(0);
    expect($installation->refresh()->state)->toBe('migrated');

    $this->artisan('racklab plugin enable racklab/console-proxmox')->assertExitCode(0);
    expect($installation->refresh()->state)->toBe('enabled')
        ->and(app(PluginRegistry::class)->enabledPlugins())->toHaveKey('racklab/console-proxmox');

    $this->artisan('racklab plugin disable racklab/console-proxmox')->assertExitCode(0);
    expect($installation->refresh()->state)->toBe('disabled')
        ->and(app(PluginRegistry::class)->enabledPlugins())->toBe([]);

    $this->artisan('racklab plugin uninstall racklab/console-proxmox')->assertExitCode(0);
    expect(PluginInstallation::query()->where('slug', 'racklab/console-proxmox')->exists())->toBeFalse();
});

it('declares the console:proxmox:v1 capability in its manifest', function (): void {
    $manifest = new Manifest;

    expect($manifest->slug())->toBe('racklab/console-proxmox')
        ->and($manifest->capabilities())->toBe(['console:proxmox:v1'])
        ->and($manifest->name())->toBe('RackLab Proxmox Console');
});
