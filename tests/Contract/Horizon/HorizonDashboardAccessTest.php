<?php

declare(strict_types=1);

use App\Domain\Tenancy\PlatformResource;
use App\Domain\Tenancy\RoleBindingScopeType;
use App\Models\AuditEvent;
use App\Models\RoleBinding;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns 403 (NOT a redirect) for anonymous visitors and emits horizon.access.denied audit', function (): void {
    // Horizon's middleware is ['web', BindAuthenticatedTenant::class] — no `auth`.
    // The gate fires for anonymous and returns 403, keeping the denial audit visible.
    $response = $this->get('/horizon');
    $response->assertForbidden();

    expect(AuditEvent::query()->where('event_type', 'horizon.access.denied')->count())->toBe(1);
});

it('returns 403 for an authenticated user without platform-scope horizon.view', function (): void {
    Tenant::query()->firstOrCreate(['slug' => 'default'], ['name' => 'Default Tenant', 'is_active' => true]);
    $user = User::factory()->create();
    // No platform binding created.

    $this->actingAs($user)->get('/horizon')->assertForbidden();

    expect(AuditEvent::query()->where('event_type', 'horizon.access.denied')->where('actor_id', (string) $user->id)->count())->toBeGreaterThanOrEqual(1);
});

it('returns 200 for an authenticated admin with platform-scope binding', function (): void {
    Tenant::query()->firstOrCreate(['slug' => 'default'], ['name' => 'Default Tenant', 'is_active' => true]);
    syncRbacDefaults();

    $user = User::factory()->create();
    RoleBinding::query()->create([
        'principal_type' => 'user',
        'principal_id' => (string) $user->id,
        'scope_type' => RoleBindingScopeType::Global,
        'role' => 'admin',
        'resource_type' => PlatformResource::RESOURCE_TYPE,
        'resource_id' => PlatformResource::RACKLAB_ID,
        'tenant_id' => null,
        'tenant_set' => null,
    ]);

    $this->actingAs($user)->get('/horizon')->assertOk();

    expect(AuditEvent::query()->where('event_type', 'horizon.access')->where('actor_id', (string) $user->id)->count())->toBeGreaterThanOrEqual(1);
});

it('OVER-AUTH REGRESSION GUARD: returns 403 for a user with global-scope binding on a project (not the platform resource)', function (): void {
    // A global-scope admin binding on a specific project must NOT grant Horizon.
    // codex v2 P1 regression — permittedPlatform requires the dedicated platform resource.
    Tenant::query()->firstOrCreate(['slug' => 'default'], ['name' => 'Default Tenant', 'is_active' => true]);
    syncRbacDefaults();

    $user = User::factory()->create();
    RoleBinding::query()->create([
        'principal_type' => 'user',
        'principal_id' => (string) $user->id,
        'scope_type' => RoleBindingScopeType::Global,
        'role' => 'admin',
        'resource_type' => 'project',   // NOT 'platform'
        'resource_id' => 'project-xyz',
        'tenant_id' => null,
        'tenant_set' => null,
    ]);

    $this->actingAs($user)->get('/horizon')->assertForbidden();
});

/**
 * Sync the default RBAC catalog so 'admin' role gets the persisted `horizon.view` permission.
 */
function syncRbacDefaults(): void
{
    app(App\Rbac\RbacDefaultsSynchronizer::class)->sync();
}
