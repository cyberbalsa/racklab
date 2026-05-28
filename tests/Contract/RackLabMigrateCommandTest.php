<?php

declare(strict_types=1);

use App\Models\PluginInstallation;
use App\Models\PluginMigrationRecord;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('runs core migrations and bootstrap seed through the RackLab migrate command', function (): void {
    $this->artisan('racklab:migrate')
        ->assertExitCode(0);

    expect(Tenant::query()->where('slug', 'default')->exists())->toBeTrue()
        ->and(Role::query()->where('name', 'admin')->exists())->toBeTrue();
});

it('migrates installed plugins during the RackLab migrate command', function (): void {
    $this->artisan('racklab plugin install racklab/plugin-hello')
        ->assertExitCode(0);

    $this->artisan('racklab:migrate')
        ->assertExitCode(0);

    expect(PluginInstallation::query()->whereKey('racklab/plugin-hello')->firstOrFail()->state)->toBe('migrated')
        ->and(PluginMigrationRecord::query()
            ->where('plugin_slug', 'racklab/plugin-hello')
            ->where('direction', 'up')
            ->exists())->toBeTrue();
});
