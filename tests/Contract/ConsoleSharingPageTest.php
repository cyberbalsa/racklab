<?php

declare(strict_types=1);

use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Livewire\Sharing\ConsoleSharing;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\RoleBinding;
use App\Models\StackDefinition;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * @return array{Tenant, User, Deployment}
 */
function seedSharingPageFixture(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $owner = User::factory()->create(['name' => 'Owner']);

    app(TenantContextStore::class)->set(new TenantContext(activeTenantId: $tenant->getKey()));
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

function makeSharingMember(Tenant $tenant, string $email): User
{
    $user = User::factory()->create(['email' => $email]);
    TenantMembership::query()->create(['tenant_id' => $tenant->getKey(), 'user_id' => $user->id, 'is_primary' => false]);

    return $user;
}

it('shares and revokes a deployment console from the sharing screen', function (): void {
    [$tenant, $owner, $deployment] = seedSharingPageFixture();
    $guest = makeSharingMember($tenant, 'guest@example.test');

    app(TenantContextStore::class)->set(new TenantContext(activeTenantId: $tenant->getKey()));
    $tenant->makeCurrent();
    test()->actingAs($owner);

    // Share: the deployment is listed (owner can manage), the guest is granted.
    Livewire::test(ConsoleSharing::class)
        ->assertOk()
        ->assertSee('lab-vm')
        ->set('emailInputs.'.$deployment->getKey(), 'guest@example.test')
        ->call('share', $deployment->getKey())
        ->assertHasNoErrors()
        ->assertSee('guest@example.test');

    expect(RoleBinding::query()
        ->where('principal_id', (string) $guest->id)
        ->where('resource_id', $deployment->resourceId())
        ->where('role', 'console_guest')
        ->exists())->toBeTrue();

    // Revoke.
    Livewire::test(ConsoleSharing::class)
        ->call('revoke', $deployment->getKey(), $guest->id)
        ->assertHasNoErrors();

    expect(RoleBinding::query()
        ->where('principal_id', (string) $guest->id)
        ->where('resource_id', $deployment->resourceId())
        ->where('role', 'console_guest')
        ->exists())->toBeFalse();

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();
});

it('does not list a deployment the actor cannot manage', function (): void {
    [$tenant, , $deployment] = seedSharingPageFixture();
    $stranger = makeSharingMember($tenant, 'stranger@example.test');

    app(TenantContextStore::class)->set(new TenantContext(activeTenantId: $tenant->getKey()));
    $tenant->makeCurrent();
    test()->actingAs($stranger);

    Livewire::test(ConsoleSharing::class)
        ->assertOk()
        ->assertDontSee('lab-vm');

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();
});
