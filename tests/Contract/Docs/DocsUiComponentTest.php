<?php

declare(strict_types=1);

use App\Docs\DocService;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Livewire\Docs\DocEditor;
use App\Livewire\Docs\DocIndex;
use App\Models\AuditEvent;
use App\Models\Doc;
use App\Models\DocVersion;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Provisions an actor and leaves their tenant context active + acting-as
 * set, so the full-page Livewire components resolve auth + tenant.
 *
 * @return array{Tenant, User, Project, TenantContext}
 */
function provisionDocsUiActor(string $name = 'Doc Author'): array
{
    app(RbacDefaultsSynchronizer::class)->sync();
    $tenant = Tenant::query()->where('slug', 'default')->first()
        ?? Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create(['name' => $name]);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);
    test()->actingAs($user);

    return [$tenant, $user, $project, $context];
}

it('creates a doc through the editor and redirects to edit mode', function (): void {
    [$tenant, $user, $project] = provisionDocsUiActor();

    Livewire::test(DocEditor::class)
        ->set('title', 'Lab 1 — Networking')
        ->set('projectId', $project->getKey())
        ->set('markdown', "# Lab 1\n\nSee [[deployment:abc-123]].")
        ->set('editorMessage', 'initial')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $doc = Doc::query()->where('tenant_id', $tenant->getKey())->firstOrFail();
    expect($doc->title)->toBe('Lab 1 — Networking')
        ->and($doc->published_at)->toBeNull()
        ->and(DocVersion::query()->where('doc_id', $doc->getKey())->count())->toBe(1);

    expect(AuditEvent::query()->where('event_type', 'docs.page')->where('action', 'create')->count())->toBe(1);
});

it('validates required title and markdown', function (): void {
    [$tenant, $user, $project] = provisionDocsUiActor();

    Livewire::test(DocEditor::class)
        ->set('title', '')
        ->set('markdown', '')
        ->set('projectId', $project->getKey())
        ->call('save')
        ->assertHasErrors(['title', 'markdown']);
});

it('edits an existing doc into a new version', function (): void {
    [$tenant, $user, $project, $context] = provisionDocsUiActor();
    $doc = app(DocService::class)->create($user, $context, 'Draft', '# v1', $project, null, 'v1');

    Livewire::test(DocEditor::class, ['doc' => $doc])
        ->assertSet('title', 'Draft')
        ->assertSet('markdown', '# v1')
        ->set('markdown', "# v2\n\nupdated body")
        ->set('editorMessage', 'revise')
        ->call('save')
        ->assertHasNoErrors();

    expect(DocVersion::query()->where('doc_id', $doc->getKey())->max('version_number'))->toBe(2);
});

it('publishes a draft from the editor', function (): void {
    [$tenant, $user, $project, $context] = provisionDocsUiActor();
    $doc = app(DocService::class)->create($user, $context, 'To publish', '# body', $project, null, 'v1');

    Livewire::test(DocEditor::class, ['doc' => $doc])
        ->call('publish')
        ->assertSet('isPublished', true);

    expect($doc->fresh()?->published_at)->not->toBeNull();
    expect(AuditEvent::query()->where('event_type', 'docs.page')->where('action', 'publish')->count())->toBe(1);
});

it('lists readable docs in the index with an edit affordance for the owner', function (): void {
    [$tenant, $user, $project, $context] = provisionDocsUiActor();
    $doc = app(DocService::class)->create($user, $context, 'Readable Lab', '# body', $project, null, 'v1');
    app(DocService::class)->publish($user, $context, $doc);

    Livewire::test(DocIndex::class)
        ->assertOk()
        ->assertSee('Readable Lab')
        ->assertSeeHtml('dusk="docs-edit-'.$doc->getKey().'"');
});

it('404s when opening the editor for a doc the actor cannot edit', function (): void {
    // Owner A creates a draft in their own project.
    [$tenantA, $userA, $projectA, $contextA] = provisionDocsUiActor('Owner A');
    $doc = app(DocService::class)->create($userA, $contextA, 'A draft', '# secret', $projectA, null, 'v1');

    // Switch to a second user in the same tenant with no binding on A's project.
    $userB = User::factory()->create(['name' => 'Outsider B']);
    app(PersonalProjectProvisioner::class)->ensureFor($userB, $contextA);

    // The edit route mounts the editor, whose mount() authorizes docs.edit
    // against the doc's project and 404s (not 403) on deny.
    test()->actingAs($userB)
        ->get('/docs/'.$doc->getKey().'/edit')
        ->assertNotFound();
});
