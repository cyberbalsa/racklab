<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Livewire\Stacks\StackBuilder;
use App\Models\NetworkOffering;
use App\Models\Project;
use App\Models\ProviderNetwork;
use App\Models\StackDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{Tenant, User, Project}
 */
function provisionStackBuilderActor(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create(['name' => 'Stack Author']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}

function makeStackBuilderOffering(Tenant $tenant, string $slug): NetworkOffering
{
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    $network = ProviderNetwork::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Isolated Bridge',
        'slug' => $slug.'-net',
        'provider' => 'fake',
        'provider_cluster' => null,
        'network_type' => 'bridge',
        'external_id' => 'vmbr-'.$slug,
        'bridge' => 'vmbr100',
        'vlan_tag' => null,
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    $offering = NetworkOffering::query()->create([
        'tenant_id' => $tenant->getKey(),
        'provider_network_id' => $network->getKey(),
        'name' => 'Private Isolated',
        'slug' => $slug,
        'offering_type' => 'private-isolated',
        'reachability' => 'isolated_no_ingress',
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return $offering;
}

function actAsStackBuilder(Tenant $tenant, User $user): void
{
    app(TenantContextStore::class)->set(new TenantContext(activeTenantId: $tenant->getKey()));
    $tenant->makeCurrent();
    test()->actingAs($user);
}

it('lists the tenant network offerings a member can read', function (): void {
    [$tenant, $user] = provisionStackBuilderActor();
    makeStackBuilderOffering($tenant, 'private-isolated');

    actAsStackBuilder($tenant, $user);

    Livewire::test(StackBuilder::class)
        ->assertOk()
        ->assertSee('Private Isolated');
});

it('creates a project-local stack with a VM and an attached network', function (): void {
    [$tenant, $user, $project] = provisionStackBuilderActor();
    makeStackBuilderOffering($tenant, 'private-isolated');

    actAsStackBuilder($tenant, $user);

    Livewire::test(StackBuilder::class)
        ->set('selectedProjectId', $project->getKey())
        ->set('stackName', 'Lab Network')
        ->call('addVm')
        ->call('attachNetwork', 0, 'private-isolated')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $stack = StackDefinition::query()
        ->where('project_id', $project->getKey())
        ->where('name', 'Lab Network')
        ->first();

    expect($stack)->not->toBeNull()
        ->and($stack?->scope)->toBe('project_local')
        ->and($stack?->is_reserved_default)->toBeFalse();

    $components = $stack?->definition['components'] ?? [];
    expect($components)->toHaveCount(1)
        ->and($components[0]['kind'] ?? null)->toBe('vm')
        ->and($components[0]['networks'][0]['offering_slug'] ?? null)->toBe('private-isolated');

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();
});

it('requires a stack name before saving', function (): void {
    [$tenant, $user, $project] = provisionStackBuilderActor();

    actAsStackBuilder($tenant, $user);

    Livewire::test(StackBuilder::class)
        ->set('selectedProjectId', $project->getKey())
        ->set('stackName', '')
        ->call('addVm')
        ->call('save')
        ->assertHasErrors(['stackName']);

    expect(StackDefinition::query()->where('project_id', $project->getKey())->where('is_reserved_default', false)->count())->toBe(0);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();
});
