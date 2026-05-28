<?php

declare(strict_types=1);

use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\SharingScope;
use App\Domain\Tenancy\TenantScopedResource;
use App\Models\AuditEvent;
use App\Models\RoleBinding;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\EloquentRoleBindingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists tenants and memberships with a primary tenant flag', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'RIT',
        'slug' => 'rit',
    ]);
    $user = User::factory()->create();

    $membership = TenantMembership::query()->create([
        'tenant_id' => $tenant->getKey(),
        'user_id' => $user->getKey(),
        'is_primary' => true,
    ]);

    expect($tenant->getKey())->toBeString()->not->toBe('')
        ->and($tenant->slug)->toBe('rit')
        ->and($membership->tenant->is($tenant))->toBeTrue()
        ->and($membership->user->is($user))->toBeTrue()
        ->and($membership->is_primary)->toBeTrue();
});

it('persists role binding tenant scope and tenant sets as the AccessResolver input shape', function (): void {
    $tenantA = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    $tenantB = Tenant::query()->create(['name' => 'Tenant B', 'slug' => 'tenant-b']);
    $user = User::factory()->create();

    $binding = RoleBinding::query()->create([
        'principal_type' => 'user',
        'principal_id' => (string) $user->getKey(),
        'role' => 'Instructor',
        'resource_type' => 'project',
        'resource_id' => 'project-1',
        'scope_type' => RoleBindingScopeType::MultiTenant,
        'tenant_id' => null,
        'tenant_set' => [$tenantA->getKey(), $tenantB->getKey()],
        'granted_by_id' => $user->getKey(),
        'granted_reason' => 'course collaboration',
    ]);

    $record = $binding->toRecord();

    expect($binding->scope_type)->toBe(RoleBindingScopeType::MultiTenant)
        ->and($binding->tenant_set)->toBe([$tenantA->getKey(), $tenantB->getKey()])
        ->and($record->coversTenant($tenantA->getKey()))->toBeTrue()
        ->and($record->coversTenant($tenantB->getKey()))->toBeTrue()
        ->and($record->coversTenant('tenant-c'))->toBeFalse();
});

it('hydrates role binding records from the database for an actor and resource', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    RoleBinding::query()->create([
        'principal_type' => 'user',
        'principal_id' => (string) $user->getKey(),
        'role' => 'Student',
        'resource_type' => 'project',
        'resource_id' => 'project-1',
        'scope_type' => RoleBindingScopeType::TenantLocal,
        'tenant_id' => $tenant->getKey(),
        'tenant_set' => [],
    ]);
    RoleBinding::query()->create([
        'principal_type' => 'user',
        'principal_id' => (string) $otherUser->getKey(),
        'role' => 'Admin',
        'resource_type' => 'project',
        'resource_id' => 'project-1',
        'scope_type' => RoleBindingScopeType::TenantLocal,
        'tenant_id' => $tenant->getKey(),
        'tenant_set' => [],
    ]);

    $records = app(EloquentRoleBindingRepository::class)->forActorAndResource(
        actor: new ActorIdentity((string) $user->getKey()),
        resource: persistedTenantResource($tenant->getKey(), 'project', 'project-1'),
    );

    expect($records)->toHaveCount(1)
        ->and($records[0]->role)->toBe('Student')
        ->and($records[0]->coversTenant($tenant->getKey()))->toBeTrue();
});

it('surfaces audit events bidirectionally across actor, resource, and target tenant sets', function (): void {
    $actorTenant = Tenant::query()->create(['name' => 'Actor Tenant', 'slug' => 'actor']);
    $resourceTenant = Tenant::query()->create(['name' => 'Resource Tenant', 'slug' => 'resource']);
    $targetTenant = Tenant::query()->create(['name' => 'Target Tenant', 'slug' => 'target']);

    $accessEvent = AuditEvent::query()->create([
        'event_type' => 'tenant.cross_access',
        'action' => 'deployment.read',
        'result' => 'allowed',
        'actor_type' => 'user',
        'actor_id' => '1',
        'actor_tenant' => $actorTenant->getKey(),
        'resource_type' => 'deployment',
        'resource_id' => 'deployment-1',
        'resource_tenant' => $resourceTenant->getKey(),
        'target_tenant_set' => [],
        'metadata' => ['reason' => 'allowed'],
        'occurred_at' => now(),
        'prev_hash' => null,
        'hash' => str_repeat('a', 64),
    ]);
    $issuanceEvent = AuditEvent::query()->create([
        'event_type' => 'tenant.cross_access',
        'action' => 'issue',
        'result' => 'denied',
        'actor_type' => 'user',
        'actor_id' => '1',
        'actor_tenant' => $actorTenant->getKey(),
        'resource_type' => 'role_binding',
        'resource_id' => null,
        'resource_tenant' => null,
        'target_tenant_set' => [$targetTenant->getKey()],
        'metadata' => ['reason' => 'insufficient_scope'],
        'occurred_at' => now(),
        'prev_hash' => $accessEvent->hash,
        'hash' => str_repeat('b', 64),
    ]);

    expect(AuditEvent::query()->visibleToTenant($actorTenant->getKey())->pluck('id')->all())
        ->toBe([$accessEvent->getKey(), $issuanceEvent->getKey()])
        ->and(AuditEvent::query()->visibleToTenant($resourceTenant->getKey())->pluck('id')->all())
        ->toBe([$accessEvent->getKey()])
        ->and(AuditEvent::query()->visibleToTenant($targetTenant->getKey())->pluck('id')->all())
        ->toBe([$issuanceEvent->getKey()]);
});

function persistedTenantResource(string $tenantId, string $resourceType, string $resourceId): TenantScopedResource
{
    return new readonly class($tenantId, $resourceType, $resourceId) implements TenantScopedResource
    {
        public function __construct(
            private string $tenantId,
            private string $resourceType,
            private string $resourceId,
        ) {}

        public function tenantId(): string
        {
            return $this->tenantId;
        }

        public function resourceType(): string
        {
            return $this->resourceType;
        }

        public function resourceId(): string
        {
            return $this->resourceId;
        }

        public function sharingScope(): SharingScope
        {
            return SharingScope::TenantLocal;
        }

        public function sharedWithTenantIds(): array
        {
            return [];
        }
    };
}
