<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\Project;
use App\Models\StackDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{Tenant, User, Project}
 */
function provisionStackExportActor(string $name): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    /** @var Tenant $tenant */
    $tenant = Tenant::query()->firstOrCreate(['slug' => 'default'], ['name' => 'Default Tenant']);
    $user = User::factory()->create(['name' => $name]);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}

function makeExportableStack(Tenant $tenant, Project $project): StackDefinition
{
    app(TenantContextStore::class)->set(new TenantContext(activeTenantId: $tenant->getKey()));
    $tenant->makeCurrent();

    /** @var StackDefinition $stack */
    $stack = StackDefinition::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'name' => 'Exportable Lab',
        'slug' => 'exportable-lab',
        'scope' => 'project_local',
        'is_reserved_default' => false,
        'definition' => ['provider' => 'fake', 'components' => [['key' => 'vm', 'kind' => 'vm', 'networks' => []]]],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return $stack;
}

it('lets a project owner export their stack as a downloadable package', function (): void {
    [$tenant, $user, $project] = provisionStackExportActor('Owner');
    $stack = makeExportableStack($tenant, $project);

    $this->actingAs($user)
        ->get('/stacks/'.$stack->getKey().'/export')
        ->assertOk()
        ->assertHeader('content-type', 'application/zip')
        ->assertDownload('exportable-lab.racklab-stack.zip');
});

it('does not let another tenant member export a stack they do not own', function (): void {
    [$tenant, $owner, $project] = provisionStackExportActor('Owner');
    $stack = makeExportableStack($tenant, $project);

    [, $outsider] = provisionStackExportActor('Outsider');

    $this->actingAs($outsider)
        ->get('/stacks/'.$stack->getKey().'/export')
        ->assertNotFound();
});
