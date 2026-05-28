<?php

declare(strict_types=1);

use App\Domain\Rbac\DefaultRoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('bootstraps the default tenant and role catalog without demo users', function (): void {
    $this->artisan('db:seed')->assertExitCode(0);
    $this->artisan('db:seed')->assertExitCode(0);

    expect(Tenant::query()->where('slug', config('racklab.default_tenant_slug'))->count())->toBe(1)
        ->and(Tenant::query()->where('slug', 'default')->firstOrFail()->name)->toBe('Default Tenant')
        ->and(User::query()->count())->toBe(0)
        ->and(Role::query()->pluck('name')->sort()->values()->all())
        ->toBe(collect(array_keys(DefaultRoleCatalog::permissionsByRole()))->sort()->values()->all());
});
