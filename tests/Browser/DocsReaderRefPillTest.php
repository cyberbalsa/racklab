<?php

declare(strict_types=1);

use App\Docs\MarkdownRenderer;
use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\Deployment;
use App\Models\Doc;
use App\Models\DocVersion;
use App\Models\Project;
use App\Models\RoleBinding;
use App\Models\StackDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\AssertsNoAxeViolations;

uses(DatabaseMigrations::class, AssertsNoAxeViolations::class);

it('upgrades a cross-link into a resolved status pill in a real browser', function (): void {
    [$tenant, $user, $project] = provisionPillBrowserActor();
    $deployment = makePillDeployment($tenant, $project, 'Lab VM', 'running');
    grantPillDeploymentRead($user, $tenant, $deployment);
    $docId = publishPillDoc($tenant, $user, $project, '[[deployment:'.$deployment->getKey().']]');

    $this->browse(function (Browser $browser) use ($user, $docId): void {
        $browser
            ->loginAs($user)
            ->visit('/docs/'.$docId)
            ->waitForText('Lab 1')
            // island upgrades the pending pill once the resolver answers
            ->waitFor('racklab-ref.racklab-ref--resolved', 10)
            ->assertSee('Lab VM')
            ->assertSee('running');

        $this->assertNoAxeViolations($browser);
    });
});

it('redacts a cross-link the reader cannot access', function (): void {
    [$tenant, $user, $project] = provisionPillBrowserActor();
    // Deployment exists in the tenant but the reader has no binding on it.
    $deployment = makePillDeployment($tenant, $project, 'Secret VM', 'running');
    $docId = publishPillDoc($tenant, $user, $project, '[[deployment:'.$deployment->getKey().']]');

    $this->browse(function (Browser $browser) use ($user, $docId): void {
        $browser
            ->loginAs($user)
            ->visit('/docs/'.$docId)
            ->waitFor('racklab-ref.racklab-ref--redacted', 10)
            ->assertSee('redacted')
            ->assertDontSee('Secret VM');
    });
});

/**
 * @return array{Tenant, User, Project}
 */
function provisionPillBrowserActor(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();
    /** @var Tenant|null $tenant */
    $tenant = Tenant::query()->where('slug', 'default')->first();
    $tenant ??= Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create(['name' => 'Pill Reader']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}

function makePillDeployment(Tenant $tenant, Project $project, string $name, string $state): Deployment
{
    $tenant->makeCurrent();
    $stack = StackDefinition::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'name' => 'Stack '.$name,
        'slug' => 'stack-'.Str::lower(Str::random(6)),
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

function grantPillDeploymentRead(User $user, Tenant $tenant, Deployment $deployment): void
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
        'granted_reason' => 'dusk pill read',
    ]);
}

function publishPillDoc(Tenant $tenant, User $user, Project $project, string $refSyntax): string
{
    $tenant->makeCurrent();
    $markdown = "# Lab 1\n\nInspect ".$refSyntax.' then continue.';
    $html = app(MarkdownRenderer::class)->render($markdown);

    $doc = Doc::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'owner_user_id' => $user->id,
        'slug' => 'lab-1-'.Str::lower(Str::random(6)),
        'title' => 'Lab 1 — Networking',
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
        'published_at' => now(),
    ]);
    $version = DocVersion::query()->create([
        'tenant_id' => $tenant->getKey(),
        'doc_id' => $doc->getKey(),
        'version_number' => 1,
        'markdown_source' => $markdown,
        'html_cache' => $html,
        'author_user_id' => $user->id,
        'editor_message' => 'initial',
    ]);
    $doc->forceFill(['current_version_id' => $version->getKey()])->save();
    Tenant::forgetCurrent();

    return $doc->getKey();
}
