<?php

declare(strict_types=1);

use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantContext;
use App\Identity\PersonalProjectProvisioner;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\RoleBinding;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a profile, tenant membership, personal project, project membership, and project-local role binding', function (): void {
    $tenant = Tenant::query()->create(['name' => 'RIT', 'slug' => 'rit']);
    $user = User::factory()->create(['name' => 'Ada Lovelace']);

    $project = app(PersonalProjectProvisioner::class)->ensureFor(
        user: $user,
        context: new TenantContext(activeTenantId: $tenant->getKey()),
    );

    expect(UserProfile::query()->whereBelongsTo($user)->first()?->display_name)->toBe('Ada Lovelace')
        ->and(TenantMembership::query()->whereBelongsTo($tenant)->whereBelongsTo($user)->first()?->is_primary)->toBeTrue()
        ->and($project->tenant_id)->toBe($tenant->getKey())
        ->and($project->created_for_user_id)->toBe($user->getKey())
        ->and($project->is_personal_default)->toBeTrue()
        ->and(ProjectMembership::query()->whereBelongsTo($project)->whereBelongsTo($user)->first()?->role)->toBe('owner');

    $binding = RoleBinding::query()
        ->where('principal_type', 'user')
        ->where('principal_id', (string) $user->getKey())
        ->where('resource_type', 'project')
        ->where('resource_id', $project->resourceId())
        ->first();

    expect($binding)->not->toBeNull()
        ->and($binding?->role)->toBe('admin')
        ->and($binding?->scope_type)->toBe(RoleBindingScopeType::TenantLocal)
        ->and($binding?->tenant_id)->toBe($tenant->getKey());
});

it('is idempotent per user and tenant but creates separate personal projects for separate tenants', function (): void {
    $tenantA = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    $tenantB = Tenant::query()->create(['name' => 'Tenant B', 'slug' => 'tenant-b']);
    $user = User::factory()->create(['name' => 'Grace Hopper']);
    $provisioner = app(PersonalProjectProvisioner::class);

    $first = $provisioner->ensureFor($user, new TenantContext(activeTenantId: $tenantA->getKey()));
    $again = $provisioner->ensureFor($user, new TenantContext(activeTenantId: $tenantA->getKey()));
    $otherTenant = $provisioner->ensureFor($user, new TenantContext(activeTenantId: $tenantB->getKey()));

    expect($again->is($first))->toBeTrue()
        ->and($otherTenant->is($first))->toBeFalse()
        ->and(Project::query()->where('created_for_user_id', $user->getKey())->count())->toBe(2)
        ->and(TenantMembership::query()->whereBelongsTo($user)->count())->toBe(2);
});
