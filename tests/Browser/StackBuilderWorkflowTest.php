<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\NetworkOffering;
use App\Models\Project;
use App\Models\ProviderNetwork;
use App\Models\StackDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;

uses(DatabaseMigrations::class);

it('builds and saves a project-local stack with an attached network in a real browser', function (): void {
    [$tenant, $user, $project] = provisionStackBuilderBrowserActor();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser
            ->loginAs($user)
            ->visit('/stacks/build')
            ->waitForText('Stack builder')
            ->type('@stack-name', 'Browser Lab Net')
            ->click('@add-vm')
            ->waitFor('@attach-0-private-isolated')
            ->click('@attach-0-private-isolated')
            ->click('@save-stack')
            ->waitForLocation('/dashboard')
            ->waitForText('Stack saved');
    });

    $stack = StackDefinition::query()
        ->where('project_id', $project->getKey())
        ->where('name', 'Browser Lab Net')
        ->first();

    expect($stack)->not->toBeNull()
        ->and($stack?->definition['components'][0]['networks'][0]['offering_slug'] ?? null)->toBe('private-isolated');
});

/**
 * @return array{Tenant, User, Project}
 */
function provisionStackBuilderBrowserActor(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create(['name' => 'Stack Browser', 'email' => 'stack-browser@example.test']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);

    $network = ProviderNetwork::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Isolated Bridge',
        'slug' => 'isolated-bridge',
        'provider' => 'fake',
        'provider_cluster' => null,
        'network_type' => 'bridge',
        'external_id' => 'vmbr100',
        'bridge' => 'vmbr100',
        'vlan_tag' => null,
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);
    NetworkOffering::query()->create([
        'tenant_id' => $tenant->getKey(),
        'provider_network_id' => $network->getKey(),
        'name' => 'Private Isolated',
        'slug' => 'private-isolated',
        'offering_type' => 'private-isolated',
        'reachability' => 'isolated_no_ingress',
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}
