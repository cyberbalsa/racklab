<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\Project;
use App\Models\RoleBinding;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('shows the authenticated user personal project on the dashboard', function (): void {
    [$tenant, $user, $project] = provisionUserProjectForAuthenticatedSurface();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertSee($tenant->name)
        ->assertSee($project->name);
});

it('returns the authenticated user and active tenant from the versioned API', function (): void {
    [$tenant, $user] = provisionUserProjectForAuthenticatedSurface();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->getKey())
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.tenant.id', $tenant->getKey())
        ->assertJsonPath('data.tenant.slug', $tenant->slug)
        ->assertJsonPath('data.profile.display_name', $user->name);
});

it('returns only projects readable by the authenticated user through AccessResolver', function (): void {
    [$tenant, $user, $project] = provisionUserProjectForAuthenticatedSurface();
    $otherUser = User::factory()->create(['name' => 'Unbound User']);
    $otherProject = app(PersonalProjectProvisioner::class)->ensureFor(
        user: $otherUser,
        context: new TenantContext(activeTenantId: $tenant->getKey()),
    );

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $project->getKey())
        ->assertJsonMissingPath('data.1');

    expect(RoleBinding::query()
        ->where('principal_id', (string) $user->getKey())
        ->where('resource_id', $otherProject->resourceId())
        ->exists())->toBeFalse();
});

/**
 * @return array{Tenant, User, Project}
 */
function provisionUserProjectForAuthenticatedSurface(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}
