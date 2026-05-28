<?php

declare(strict_types=1);

use App\Domain\Tenancy\PlatformResource;
use App\Domain\Tenancy\RoleBindingScopeType;
use App\Models\RoleBinding;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('creates BOTH project-scope and platform-scope admin bindings for the bootstrap admin', function (): void {
    Tenant::query()->create(['name' => 'RIT', 'slug' => 'rit']);

    $exitCode = Artisan::call('racklab:bootstrap-admin', [
        '--email' => 'platform@example.edu',
        '--password' => 'correct horse battery staple',
        '--tenant-slug' => 'rit',
    ]);

    expect($exitCode)->toBe(0);

    $user = User::query()->where('email', 'platform@example.edu')->firstOrFail();

    // Project-scope binding (existing behavior, locked by BootstrapAdminCommandTest)
    expect(RoleBinding::query()
        ->where('principal_id', (string) $user->id)
        ->where('scope_type', RoleBindingScopeType::TenantLocal->value)
        ->where('role', 'admin')
        ->where('resource_type', 'project')
        ->count())->toBeGreaterThanOrEqual(1);

    // Platform-scope binding (v3 new — Horizon + future platform endpoints)
    expect(RoleBinding::query()
        ->where('principal_id', (string) $user->id)
        ->where('scope_type', RoleBindingScopeType::Global->value)
        ->where('role', 'admin')
        ->where('resource_type', PlatformResource::RESOURCE_TYPE)
        ->where('resource_id', PlatformResource::RACKLAB_ID)
        ->count())->toBe(1);
});

it('is idempotent — re-running does not duplicate the platform binding', function (): void {
    Tenant::query()->create(['name' => 'RIT', 'slug' => 'rit']);

    Artisan::call('racklab:bootstrap-admin', [
        '--email' => 'platform@example.edu',
        '--password' => 'correct horse battery staple',
        '--tenant-slug' => 'rit',
    ]);
    Artisan::call('racklab:bootstrap-admin', [
        '--email' => 'platform@example.edu',
        '--password' => 'correct horse battery staple',
        '--tenant-slug' => 'rit',
    ]);

    $user = User::query()->where('email', 'platform@example.edu')->firstOrFail();

    expect(RoleBinding::query()
        ->where('principal_id', (string) $user->id)
        ->where('scope_type', RoleBindingScopeType::Global->value)
        ->where('role', 'admin')
        ->where('resource_type', PlatformResource::RESOURCE_TYPE)
        ->where('resource_id', PlatformResource::RACKLAB_ID)
        ->count())->toBe(1);
});
