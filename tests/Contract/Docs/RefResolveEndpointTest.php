<?php

declare(strict_types=1);

use App\Docs\Refs\Resolving\RefResolutionContext;
use App\Docs\Refs\Resolving\RefResolver;
use App\Docs\Refs\Resolving\ResolvedRef;
use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Events\Hookspecs\Docs\RefResolvingEvent;
use App\Identity\PersonalProjectProvisioner;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\PluginInstallation;
use App\Models\Project;
use App\Models\RoleBinding;
use App\Models\StackDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Plugins\HookDispatcher;
use App\Plugins\HookListenerStyle;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{Tenant, User, Project}
 */
function provisionRefActor(string $name = 'Ref Reader'): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    /** @var Tenant|null $tenant */
    $tenant = Tenant::query()->where('slug', 'default')->first();
    $tenant ??= Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);

    $user = User::factory()->create(['name' => $name]);
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}

function makeDeployment(Tenant $tenant, Project $project, string $name, string $state): Deployment
{
    $tenant->makeCurrent();

    $stack = StackDefinition::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'name' => 'Stack for '.$name,
        'slug' => 'stack-'.Illuminate\Support\Str::lower(Illuminate\Support\Str::random(6)),
        'scope' => 'project',
        'definition' => ['components' => []],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    $deployment = Deployment::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'stack_definition_id' => $stack->getKey(),
        'name' => $name,
        'state' => $state,
        'provider' => 'fake',
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    Tenant::forgetCurrent();

    return $deployment;
}

function grantDeploymentRead(User $user, Tenant $tenant, Deployment $deployment): void
{
    RoleBinding::query()->create([
        'principal_type' => 'user',
        'principal_id' => (string) $user->id,
        'role' => 'student',
        'resource_type' => $deployment->resourceType(),
        'resource_id' => $deployment->resourceId(),
        'scope_type' => RoleBindingScopeType::TenantLocal,
        'tenant_id' => $tenant->getKey(),
        'tenant_set' => [$tenant->getKey()],
        'granted_by_id' => $user->id,
        'granted_reason' => 'test deployment read',
    ]);
}

it('resolves a deployment the actor can read into a live pill payload', function (): void {
    config()->set('docs.ref_resolve_audit_sample_rate', 1.0);
    [$tenant, $user, $project] = provisionRefActor();
    $deployment = makeDeployment($tenant, $project, 'Lab VM', 'running');
    grantDeploymentRead($user, $tenant, $deployment);

    $this->actingAs($user)
        ->getJson('/plugins/docs/refs/resolve/deployment/'.$deployment->getKey())
        ->assertOk()
        ->assertJsonPath('data.status', 'resolved')
        ->assertJsonPath('data.kind', 'deployment')
        ->assertJsonPath('data.label', 'Lab VM')
        ->assertJsonPath('data.detail', 'running')
        ->assertJsonPath('data.rbac_visible', true)
        ->assertJsonPath('data.url', '/deployments/'.$deployment->getKey());

    expect(AuditEvent::query()
        ->where('event_type', 'docs.ref_resolve')
        ->where('result', 'allowed')
        ->count())->toBe(1);
});

it('redacts a deployment the actor cannot read and audits the denied resolution', function (): void {
    [$tenant, $user, $project] = provisionRefActor();
    // Deployment exists in the tenant but the actor has no binding on it.
    $deployment = makeDeployment($tenant, $project, 'Secret VM', 'running');

    $this->actingAs($user)
        ->getJson('/plugins/docs/refs/resolve/deployment/'.$deployment->getKey())
        ->assertOk()
        ->assertJsonPath('data.status', 'redacted')
        ->assertJsonPath('data.kind', 'deployment')
        ->assertJsonPath('data.label', null)
        ->assertJsonPath('data.detail', null)
        ->assertJsonPath('data.url', null)
        ->assertJsonPath('data.rbac_visible', false);

    expect(AuditEvent::query()
        ->where('event_type', 'docs.ref_resolve')
        ->where('result', 'denied')
        ->count())->toBe(1);
});

it('reports not_found for a reference to a non-existent target', function (): void {
    [$tenant, $user] = provisionRefActor();

    $this->actingAs($user)
        ->getJson('/plugins/docs/refs/resolve/deployment/01HZZZNONEXISTENT0000000000')
        ->assertOk()
        ->assertJsonPath('data.status', 'not_found')
        ->assertJsonPath('data.rbac_visible', false);
});

it('reports unsupported for a kind no resolver handles', function (): void {
    [$tenant, $user] = provisionRefActor();

    $this->actingAs($user)
        ->getJson('/plugins/docs/refs/resolve/widget/abc-123')
        ->assertOk()
        ->assertJsonPath('data.status', 'unsupported')
        ->assertJsonPath('data.kind', 'widget');
});

it('resolves the personal project the actor owns', function (): void {
    [$tenant, $user, $project] = provisionRefActor();

    $this->actingAs($user)
        ->getJson('/plugins/docs/refs/resolve/project/'.$project->getKey())
        ->assertOk()
        ->assertJsonPath('data.status', 'resolved')
        ->assertJsonPath('data.kind', 'project')
        ->assertJsonPath('data.label', $project->name);
});

it('resolves an enabled plugin for any docs reader without leaking tenant scope', function (): void {
    [$tenant, $user] = provisionRefActor();

    PluginInstallation::query()->create([
        'slug' => 'racklab/docs-plugin',
        'package_name' => 'racklab/docs-plugin',
        'version' => '1.0.0',
        'state' => 'enabled',
        'service_provider' => 'RackLab\\Docs\\DocsServiceProvider',
        'manifest_class' => null,
        'name' => 'RackLab Docs',
        'description' => 'Docs plugin',
        'installed_at' => now(),
        'enabled_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson('/plugins/docs/refs/resolve/plugin/docs-plugin')
        ->assertOk()
        ->assertJsonPath('data.status', 'resolved')
        ->assertJsonPath('data.kind', 'plugin')
        ->assertJsonPath('data.label', 'RackLab Docs')
        ->assertJsonPath('data.detail', 'enabled');
});

it('does not treat an underscore in a plugin id as a SQL LIKE wildcard', function (): void {
    [$tenant, $user] = provisionRefActor();

    // Slug differs from the queried id only at the position a `_` wildcard
    // would otherwise match. The id `aXb` must NOT resolve `racklab/a_b`.
    PluginInstallation::query()->create([
        'slug' => 'racklab/a_b',
        'package_name' => 'racklab/a_b',
        'version' => '1.0.0',
        'state' => 'enabled',
        'service_provider' => 'RackLab\\AB\\Provider',
        'manifest_class' => null,
        'name' => 'AB Plugin',
        'description' => null,
        'installed_at' => now(),
        'enabled_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson('/plugins/docs/refs/resolve/plugin/aXb')
        ->assertOk()
        ->assertJsonPath('data.status', 'not_found');
});

it('resolves a plugin-contributed kind through the RefResolving hookspec', function (): void {
    [$tenant, $user] = provisionRefActor();

    app(HookDispatcher::class)->listen(
        RefResolvingEvent::class,
        static fn (RefResolvingEvent $event): ?RefResolver => $event->kind === 'cluster'
            ? new class implements RefResolver
            {
                public function kind(): string
                {
                    return 'cluster';
                }

                public function resolve(RefResolutionContext $context, string $id): ResolvedRef
                {
                    return ResolvedRef::resolved('cluster', $id, 'PVE Edu Cluster', '/clusters/'.$id, 'online');
                }
            }
        : null,
        HookListenerStyle::Resolver,
        'racklab/provider-proxmox',
        1000,
    );

    $this->actingAs($user)
        ->getJson('/plugins/docs/refs/resolve/cluster/pve-edu-1')
        ->assertOk()
        ->assertJsonPath('data.status', 'resolved')
        ->assertJsonPath('data.kind', 'cluster')
        ->assertJsonPath('data.label', 'PVE Edu Cluster')
        ->assertJsonPath('data.detail', 'online');
});

it('rejects a malformed reference with a 404', function (): void {
    [$tenant, $user] = provisionRefActor();

    $this->actingAs($user)
        ->getJson('/plugins/docs/refs/resolve/UPPERCASE/abc')
        ->assertNotFound();
});

it('requires authentication to resolve a reference', function (): void {
    // Session-backed web endpoint: an unauthenticated browser is redirected
    // to login rather than served the resolver payload.
    $this->get('/plugins/docs/refs/resolve/deployment/abc-123')
        ->assertRedirect();
});
