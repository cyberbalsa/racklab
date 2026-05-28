<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\Course;
use App\Models\FloatingIpPool;
use App\Models\NetworkOffering;
use App\Models\Project;
use App\Models\ProviderDrift;
use App\Models\ProviderNetwork;
use App\Models\QuotaLimit;
use App\Models\SubnetPool;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;

uses(DatabaseMigrations::class);

it('browses tenant-scoped Filament admin resources in a real browser', function (): void {
    [$tenant, $user, $project, $course, $providerNetwork, $networkOffering, $subnetPool, $floatingIpPool, $quotaLimit, $providerDrift] = provisionFilamentAdminBrowserResources();

    $this->browse(function (Browser $browser) use ($tenant, $user, $project, $course, $providerNetwork, $networkOffering, $subnetPool, $floatingIpPool, $quotaLimit, $providerDrift): void {
        $browser
            ->loginAs($user)
            ->visit('/admin/'.$tenant->slug)
            ->waitForText('Dashboard')
            ->assertSee($user->name)
            ->visit('/admin/'.$tenant->slug.'/projects')
            ->waitForText('Projects')
            ->assertSee($project->name)
            ->visit('/admin/'.$tenant->slug.'/courses')
            ->waitForText('Courses')
            ->assertSee($course->name)
            ->visit('/admin/'.$tenant->slug.'/users')
            ->waitForText('Users')
            ->assertSee($user->email)
            ->visit('/admin/'.$tenant->slug.'/provider-networks')
            ->waitForText('Provider Networks')
            ->assertSee($providerNetwork->name)
            ->visit('/admin/'.$tenant->slug.'/network-offerings')
            ->waitForText('Network Offerings')
            ->assertSee($networkOffering->name)
            ->visit('/admin/'.$tenant->slug.'/subnet-pools')
            ->waitForText('Subnet Pools')
            ->assertSee($subnetPool->name)
            ->visit('/admin/'.$tenant->slug.'/floating-ip-pools')
            ->waitForText('Floating IP Pools')
            ->assertSee($floatingIpPool->name)
            ->visit('/admin/'.$tenant->slug.'/quota-limits')
            ->waitForText('Quota Limits')
            ->assertSee($quotaLimit->dimension)
            ->visit('/admin/'.$tenant->slug.'/provider-drifts')
            ->waitForText('Provider Drift')
            ->assertSee($providerDrift->resource_label);
    });
});

it('creates and edits a course through Filament in a real browser', function (): void {
    [$tenant, $user] = provisionFilamentAdminBrowserResources();

    $this->browse(function (Browser $browser) use ($tenant, $user): void {
        $browser
            ->loginAs($user)
            ->visit('/admin/'.$tenant->slug.'/courses/create')
            ->waitForText('Create Course')
            ->type('#form\\.name', 'Dusk Created Course')
            ->type('#form\\.slug', 'dusk-created-course')
            ->type('#form\\.description', 'Created through Filament Dusk coverage.')
            ->press('Create')
            ->waitForText('Dusk Created Course')
            ->assertInputValue('#form\\.slug', 'dusk-created-course');
    });

    /** @var Course $course */
    $course = Course::query()->where('slug', 'dusk-created-course')->firstOrFail();

    $this->browse(function (Browser $browser) use ($tenant, $course): void {
        $browser
            ->visit('/admin/'.$tenant->slug.'/courses/'.$course->getKey().'/edit')
            ->waitForText('Edit Dusk Created Course')
            ->type('#form\\.name', 'Dusk Edited Course')
            ->press('Save changes')
            ->waitForText('Dusk Edited Course');
    });

    expect($course->refresh()->name)->toBe('Dusk Edited Course');
});

it('creates and edits a project through Filament in a real browser', function (): void {
    [$tenant, $user] = provisionFilamentAdminBrowserResources();

    $this->browse(function (Browser $browser) use ($tenant, $user): void {
        $browser
            ->loginAs($user)
            ->visit('/admin/'.$tenant->slug.'/projects/create')
            ->waitForText('Create Project')
            ->type('#form\\.name', 'Dusk Created Project')
            ->type('#form\\.slug', 'dusk-created-project')
            ->press('Create')
            ->waitForText('Dusk Created Project')
            ->assertInputValue('#form\\.slug', 'dusk-created-project');
    });

    /** @var Project $project */
    $project = Project::query()->where('slug', 'dusk-created-project')->firstOrFail();

    $this->browse(function (Browser $browser) use ($tenant, $project): void {
        $browser
            ->visit('/admin/'.$tenant->slug.'/projects/'.$project->getKey().'/edit')
            ->waitForText('Edit Dusk Created Project')
            ->type('#form\\.name', 'Dusk Edited Project')
            ->press('Save changes')
            ->waitForText('Dusk Edited Project');
    });

    expect($project->refresh()->name)->toBe('Dusk Edited Project');
});

/**
 * @return array{Tenant, User, Project, Course, ProviderNetwork, NetworkOffering, SubnetPool, FloatingIpPool, QuotaLimit, ProviderDrift}
 */
function provisionFilamentAdminBrowserResources(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create(['name' => 'Filament Browser Admin', 'email' => 'filament-browser@example.test']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);

    /** @var Course $course */
    $course = Course::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Browser Admin Course',
        'slug' => 'browser-admin-course',
        'description' => 'Course visible through Filament browser coverage.',
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    /** @var ProviderNetwork $providerNetwork */
    $providerNetwork = ProviderNetwork::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Browser Provider Bridge',
        'slug' => 'browser-provider-bridge',
        'provider' => 'fake',
        'provider_cluster' => null,
        'network_type' => 'bridge',
        'external_id' => 'vmbr-browser',
        'bridge' => 'vmbr-browser',
        'vlan_tag' => null,
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    /** @var NetworkOffering $networkOffering */
    $networkOffering = NetworkOffering::query()->create([
        'tenant_id' => $tenant->getKey(),
        'provider_network_id' => $providerNetwork->getKey(),
        'name' => 'Browser Isolated Offering',
        'slug' => 'browser-isolated-offering',
        'offering_type' => 'private-isolated',
        'reachability' => 'isolated_no_ingress',
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    /** @var SubnetPool $subnetPool */
    $subnetPool = SubnetPool::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Browser Student Pool',
        'slug' => 'browser-student-pool',
        'cidr' => '10.80.0.0/16',
        'ip_version' => 4,
        'default_prefix_length' => 24,
        'min_prefix_length' => 24,
        'max_prefix_length' => 28,
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    /** @var FloatingIpPool $floatingIpPool */
    $floatingIpPool = FloatingIpPool::query()->create([
        'tenant_id' => $tenant->getKey(),
        'provider_network_id' => $providerNetwork->getKey(),
        'name' => 'Browser Floating IP Pool',
        'slug' => 'browser-floating-ip-pool',
        'cidr' => '198.51.100.0/29',
        'ip_version' => 4,
        'provider' => 'fake',
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    /** @var QuotaLimit $quotaLimit */
    $quotaLimit = QuotaLimit::query()->create([
        'tenant_id' => $tenant->getKey(),
        'scope_type' => 'project',
        'scope_id' => $project->getKey(),
        'dimension' => 'vcpu',
        'limit_value' => 2,
        'metadata' => [],
    ]);

    /** @var ProviderDrift $providerDrift */
    $providerDrift = ProviderDrift::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'provider' => 'fake',
        'resource_type' => 'network',
        'resource_id' => 'browser-drifted-network',
        'resource_label' => 'Browser Drifted Network',
        'state' => 'detected',
        'expected_state' => ['state' => 'active'],
        'observed_state' => ['state' => 'externally_disabled'],
        'drift' => [
            [
                'path' => 'state',
                'expected' => 'active',
                'observed' => 'externally_disabled',
            ],
        ],
        'detected_at' => now(),
        'resolved_at' => null,
        'resolved_by_id' => null,
        'resolution' => null,
        'metadata' => [],
    ]);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project, $course, $providerNetwork, $networkOffering, $subnetPool, $floatingIpPool, $quotaLimit, $providerDrift];
}
