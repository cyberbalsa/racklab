<?php

declare(strict_types=1);

use App\Models\PluginInstallation;
use App\Models\PluginMigrationRecord;
use App\Plugins\PluginRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('installs migrates enables disables and uninstalls the reference plugin through lifecycle commands', function (): void {
    $this->artisan('racklab plugin install racklab/plugin-hello')
        ->assertExitCode(0);

    $installation = PluginInstallation::query()->where('slug', 'racklab/plugin-hello')->firstOrFail();

    expect($installation->state)->toBe('installed')
        ->and($installation->service_provider)->toBe(Racklab\PluginHello\PluginHelloServiceProvider::class)
        ->and(app(PluginRegistry::class)->enabledPlugins())->toBe([]);

    $this->artisan('racklab plugin migrate racklab/plugin-hello')
        ->assertExitCode(0);

    expect($installation->refresh()->state)->toBe('migrated')
        ->and(PluginMigrationRecord::query()->where('plugin_slug', 'racklab/plugin-hello')->where('direction', 'up')->exists())->toBeTrue();

    $this->artisan('racklab plugin enable racklab/plugin-hello')
        ->assertExitCode(0);

    expect($installation->refresh()->state)->toBe('enabled')
        ->and(app(PluginRegistry::class)->enabledPlugins())->toHaveKey('racklab/plugin-hello');

    $this->artisan('racklab plugin disable racklab/plugin-hello')
        ->assertExitCode(0);

    expect($installation->refresh()->state)->toBe('disabled')
        ->and(app(PluginRegistry::class)->enabledPlugins())->toBe([]);

    $this->artisan('racklab plugin uninstall racklab/plugin-hello')
        ->assertExitCode(0);

    expect(PluginInstallation::query()->where('slug', 'racklab/plugin-hello')->exists())->toBeFalse()
        ->and(PluginMigrationRecord::query()->where('plugin_slug', 'racklab/plugin-hello')->where('direction', 'down')->exists())->toBeTrue();
});

it('refuses invalid plugin lifecycle transitions', function (): void {
    $this->artisan('racklab plugin install racklab/plugin-hello')
        ->assertExitCode(0);

    $this->artisan('racklab plugin enable racklab/plugin-hello')
        ->assertFailed();

    expect(PluginInstallation::query()->where('slug', 'racklab/plugin-hello')->firstOrFail()->state)->toBe('installed');

    $this->artisan('racklab plugin migrate racklab/plugin-hello')
        ->assertExitCode(0);
    $this->artisan('racklab plugin enable racklab/plugin-hello')
        ->assertExitCode(0);

    $this->artisan('racklab plugin rollback racklab/plugin-hello')
        ->assertFailed();

    expect(PluginInstallation::query()->where('slug', 'racklab/plugin-hello')->firstOrFail()->state)->toBe('enabled');
});

it('rolls back a disabled plugin to migrated state without booting it', function (): void {
    $this->artisan('racklab plugin install racklab/plugin-hello')
        ->assertExitCode(0);
    $this->artisan('racklab plugin migrate racklab/plugin-hello')
        ->assertExitCode(0);
    $this->artisan('racklab plugin enable racklab/plugin-hello')
        ->assertExitCode(0);
    $this->artisan('racklab plugin disable racklab/plugin-hello')
        ->assertExitCode(0);

    $this->artisan('racklab plugin rollback racklab/plugin-hello')
        ->assertExitCode(0);

    expect(PluginInstallation::query()->where('slug', 'racklab/plugin-hello')->firstOrFail()->state)->toBe('migrated')
        ->and(PluginMigrationRecord::query()->where('plugin_slug', 'racklab/plugin-hello')->where('direction', 'down')->exists())->toBeTrue()
        ->and(app(PluginRegistry::class)->enabledPlugins())->toBe([]);
});
