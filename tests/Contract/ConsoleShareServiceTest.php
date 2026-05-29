<?php

declare(strict_types=1);

use App\Deployments\ConsoleShareService;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\RoleBinding;
use App\Models\StackDefinition;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{Tenant, User, Deployment}
 */
function seedConsoleShareFixture(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $owner = User::factory()->create(['name' => 'Owner']);

    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    $project = Project::query()->create([
        'tenant_id' => $tenant->getKey(), 'name' => 'P', 'slug' => 'p',
        'sharing_scope' => 'tenant_local', 'shared_with_tenants' => [],
    ]);
    $stack = StackDefinition::query()->create([
        'tenant_id' => $tenant->getKey(), 'project_id' => $project->getKey(), 'name' => 'S', 'slug' => 's',
        'scope' => 'project_local', 'is_reserved_default' => false,
        'definition' => ['provider' => 'fake', 'components' => []], 'sharing_scope' => 'tenant_local', 'shared_with_tenants' => [],
    ]);

    /** @var Deployment $deployment */
    $deployment = Deployment::query()->create([
        'tenant_id' => $tenant->getKey(), 'project_id' => $project->getKey(), 'stack_definition_id' => $stack->getKey(),
        'requested_by_id' => $owner->id, 'name' => 'lab-vm', 'state' => 'running', 'provider' => 'fake',
        'metadata' => [], 'sharing_scope' => 'tenant_local', 'shared_with_tenants' => [],
    ]);

    // Owner's requester binding (student role → has deployment.update).
    RoleBinding::query()->create([
        'principal_type' => 'user', 'principal_id' => (string) $owner->id, 'role' => 'student',
        'resource_type' => $deployment->resourceType(), 'resource_id' => $deployment->resourceId(),
        'scope_type' => RoleBindingScopeType::TenantLocal, 'tenant_id' => $tenant->getKey(),
        'tenant_set' => [$tenant->getKey()], 'granted_by_id' => $owner->getKey(), 'granted_reason' => 'owner',
    ]);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $owner, $deployment];
}

function makeTenantMember(Tenant $tenant, string $email): User
{
    $user = User::factory()->create(['email' => $email]);
    TenantMembership::query()->create(['tenant_id' => $tenant->getKey(), 'user_id' => $user->id, 'is_primary' => false]);

    return $user;
}

it('shares a deployment console with tenant members and reports non-members', function (): void {
    [$tenant, $owner, $deployment] = seedConsoleShareFixture();
    $guest = makeTenantMember($tenant, 'guest@example.test');

    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    $result = app(ConsoleShareService::class)->share(
        $owner, $context, $deployment, "guest@example.test\noutsider@example.test\n",
    );

    expect($result->shared)->toBe(1)
        ->and($result->missing)->toBe(['outsider@example.test']);

    // The guest can now read + connect to the deployment console.
    $resolver = app(AccessResolver::class);
    $guestActor = new ActorIdentity((string) $guest->id);
    expect($resolver->permitted($guestActor, new Permission('deployment.read'), $deployment, $context)->allowed)->toBeTrue()
        ->and($resolver->permitted($guestActor, new Permission('deployment.console.connect'), $deployment, $context)->allowed)->toBeTrue()
        // ...but cannot manage it.
        ->and($resolver->permitted($guestActor, new Permission('deployment.power'), $deployment, $context)->allowed)->toBeFalse();

    expect(AuditEvent::query()->where('event_type', 'deployment.console.share')->where('action', 'share')->exists())->toBeTrue();

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();
});

it('revokes a shared console', function (): void {
    [$tenant, $owner, $deployment] = seedConsoleShareFixture();
    $guest = makeTenantMember($tenant, 'guest@example.test');

    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    $service = app(ConsoleShareService::class);
    $service->share($owner, $context, $deployment, 'guest@example.test');
    $service->revoke($owner, $context, $deployment, $guest->id);

    expect(app(AccessResolver::class)->permitted(
        new ActorIdentity((string) $guest->id), new Permission('deployment.console.connect'), $deployment, $context,
    )->allowed)->toBeFalse();

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();
});

it('refuses to share for an actor who cannot manage the deployment', function (): void {
    [$tenant, , $deployment] = seedConsoleShareFixture();
    $stranger = makeTenantMember($tenant, 'stranger@example.test');

    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    expect(fn () => app(ConsoleShareService::class)->share($stranger, $context, $deployment, 'x@example.test'))
        ->toThrow(AuthorizationException::class);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();
});
