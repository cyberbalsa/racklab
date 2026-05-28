<?php

declare(strict_types=1);

use App\Domain\Rbac\DefaultRoleCatalog;
use App\Domain\Rbac\Permission as RackLabPermission;
use App\Domain\Rbac\RolePermissionLookup;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('syncs the default role catalog into persisted role and permission rows idempotently', function (): void {
    $sync = app(RbacDefaultsSynchronizer::class);

    $first = $sync->sync();
    $second = $sync->sync();

    $expectedPermissions = collect(DefaultRoleCatalog::permissionsByRole())
        ->flatten()
        ->unique()
        ->sort()
        ->values()
        ->all();
    $expectedRoles = collect(array_keys(DefaultRoleCatalog::permissionsByRole()))
        ->sort()
        ->values()
        ->all();

    expect($first->rolesCreated)->toBeGreaterThan(0)
        ->and($second->rolesCreated)->toBe(0)
        ->and(Role::query()->pluck('name')->sort()->values()->all())
        ->toBe($expectedRoles)
        ->and(Permission::query()->pluck('name')->sort()->values()->all())
        ->toBe($expectedPermissions)
        ->and(Role::findByName('student')->permissions()->pluck('name')->sort()->values()->all())
        ->toBe(DefaultRoleCatalog::permissionsByRole()['student']);
});

it('exposes an artisan command for syncing RackLab RBAC defaults', function (): void {
    expect(Artisan::call('racklab:sync-rbac-defaults'))->toBe(0)
        ->and(Role::findByName('admin')->hasPermissionTo('tenant.manage'))->toBeTrue();
});

it('answers role permission lookups from persisted Spatie rows', function (): void {
    app(RbacDefaultsSynchronizer::class)->sync();

    $lookup = app(RolePermissionLookup::class);

    expect($lookup->roleGrants('student', new RackLabPermission('project.read')))->toBeTrue()
        ->and($lookup->roleGrants('student', new RackLabPermission('tenant.manage')))->toBeFalse()
        ->and($lookup->roleGrants('missing-role', new RackLabPermission('project.read')))->toBeFalse();
});
