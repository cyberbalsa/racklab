<?php

declare(strict_types=1);

use App\Console\Proxy\ProviderConsoleProxy;
use App\Console\Proxy\UnavailableProviderConsoleProxy;
use App\Providers\Proxmox\ProxmoxConsoleProxy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('falls back to UnavailableProviderConsoleProxy when proxmox env is set but the plugin is not enabled', function (): void {
    config()->set('racklab.console.proxy', 'proxmox');
    app()->forgetInstance(ProviderConsoleProxy::class);

    expect(app(ProviderConsoleProxy::class))->toBeInstanceOf(UnavailableProviderConsoleProxy::class);
});

it('binds the real ProxmoxConsoleProxy only after racklab/console-proxmox is installed, migrated, and enabled', function (): void {
    config()->set('racklab.console.proxy', 'proxmox');

    test()->artisan('racklab plugin install racklab/console-proxmox')->assertExitCode(0);
    app()->forgetInstance(ProviderConsoleProxy::class);
    expect(app(ProviderConsoleProxy::class))->toBeInstanceOf(UnavailableProviderConsoleProxy::class);

    test()->artisan('racklab plugin migrate racklab/console-proxmox')->assertExitCode(0);
    app()->forgetInstance(ProviderConsoleProxy::class);
    expect(app(ProviderConsoleProxy::class))->toBeInstanceOf(UnavailableProviderConsoleProxy::class);

    test()->artisan('racklab plugin enable racklab/console-proxmox')->assertExitCode(0);
    app()->forgetInstance(ProviderConsoleProxy::class);

    expect(app(ProviderConsoleProxy::class))->toBeInstanceOf(ProxmoxConsoleProxy::class);

    test()->artisan('racklab plugin disable racklab/console-proxmox')->assertExitCode(0);
    app()->forgetInstance(ProviderConsoleProxy::class);
    expect(app(ProviderConsoleProxy::class))->toBeInstanceOf(UnavailableProviderConsoleProxy::class);
});

it('keeps the in-memory binding even when the plugin is enabled, as long as env says in-memory', function (): void {
    test()->artisan('racklab plugin install racklab/console-proxmox')->assertExitCode(0);
    test()->artisan('racklab plugin migrate racklab/console-proxmox')->assertExitCode(0);
    test()->artisan('racklab plugin enable racklab/console-proxmox')->assertExitCode(0);

    config()->set('racklab.console.proxy', 'in-memory');
    app()->forgetInstance(ProviderConsoleProxy::class);

    expect(app(ProviderConsoleProxy::class))->toBeInstanceOf(App\Console\Proxy\InMemoryProviderConsoleProxy::class);
});
