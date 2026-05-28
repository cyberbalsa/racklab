<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\AuditEvent;
use App\Models\Doc;
use App\Models\DocVersion;
use App\Models\Project;
use App\Models\RoleBinding;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @return array{Tenant, User, Project}
 */
function provisionDocsActor(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create(['name' => 'Docs Author']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}

it('creates a doc, persists an initial version, and audits docs.page create', function (): void {
    [$tenant, $user, $project] = provisionDocsActor();

    Sanctum::actingAs($user);

    $payload = $this->postJson('/api/v1/docs', [
        'project_id' => $project->getKey(),
        'title' => 'Lab 1: Building the network',
        'markdown' => "# Welcome\n\nFirst doc.",
        'editor_message' => 'initial draft',
    ])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Lab 1: Building the network')
        ->assertJsonPath('data.sharing_scope', 'tenant_local')
        ->assertJsonPath('data.project_id', $project->getKey())
        ->assertJsonPath('data.published_at', null)
        ->assertJsonPath('data.current_version.version_number', 1)
        ->json('data');

    expect($payload['id'])->toBeString()
        ->and(Doc::query()->where('tenant_id', $tenant->getKey())->count())->toBe(1)
        ->and(DocVersion::query()->where('doc_id', $payload['id'])->count())->toBe(1);

    expect(AuditEvent::query()
        ->where('event_type', 'docs.page')
        ->where('action', 'create')
        ->where('result', 'allowed')
        ->count())->toBe(1);
});

it('updates a doc and appends a new version while retaining the prior one', function (): void {
    [, $user, $project] = provisionDocsActor();

    Sanctum::actingAs($user);

    $created = $this->postJson('/api/v1/docs', [
        'project_id' => $project->getKey(),
        'title' => 'Draft',
        'markdown' => 'first body',
    ])->assertCreated()->json('data');

    $this->patchJson('/api/v1/docs/'.$created['id'], [
        'title' => 'Revised title',
        'markdown' => 'second body with more detail',
        'editor_message' => 'expand intro',
    ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Revised title')
        ->assertJsonPath('data.current_version.version_number', 2)
        ->assertJsonPath('data.current_version.editor_message', 'expand intro');

    expect(DocVersion::query()->where('doc_id', $created['id'])->count())->toBe(2);

    $this->getJson('/api/v1/docs/'.$created['id'].'/versions')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.version_number', 2)
        ->assertJsonPath('data.1.version_number', 1);
});

it('publishes a doc and stamps published_at', function (): void {
    [, $user, $project] = provisionDocsActor();

    Sanctum::actingAs($user);

    $doc = $this->postJson('/api/v1/docs', [
        'project_id' => $project->getKey(),
        'title' => 'Doc',
        'markdown' => 'body',
    ])->assertCreated()->json('data');

    expect($doc['published_at'])->toBeNull();

    $published = $this->postJson('/api/v1/docs/'.$doc['id'].'/publish')
        ->assertOk()
        ->json('data');

    expect($published['published_at'])->toBeString();

    expect(AuditEvent::query()
        ->where('event_type', 'docs.page')
        ->where('action', 'publish')
        ->where('result', 'allowed')
        ->count())->toBe(1);
});

it('responds 404 when the show endpoint is hit by an actor without docs.view', function (): void {
    [$tenant, $owner, $project] = provisionDocsActor();
    Sanctum::actingAs($owner);

    $created = $this->postJson('/api/v1/docs', [
        'project_id' => $project->getKey(),
        'title' => 'Hidden doc',
        'markdown' => 'shh',
    ])->assertCreated()->json('data');

    // Outsider: a user with no tenant binding at all. AccessResolver
    // should deny on InsufficientScope, and the show endpoint must
    // return 404 — never 403 — so existence is not leaked.
    $outsider = User::factory()->create(['name' => 'Outsider']);
    Sanctum::actingAs($outsider);

    $this->getJson('/api/v1/docs/'.$created['id'])->assertNotFound();
});

it('refuses cross-tenant doc access via the show endpoint', function (): void {
    [, $owner, $project] = provisionDocsActor();
    Sanctum::actingAs($owner);

    $created = $this->postJson('/api/v1/docs', [
        'project_id' => $project->getKey(),
        'title' => 'Tenant A doc',
        'markdown' => 'private',
    ])->assertCreated()->json('data');

    // Build a second tenant with its own admin user, then act inside it.
    $tenantB = Tenant::query()->create(['name' => 'Tenant B', 'slug' => 'tenant-b']);
    $userB = User::factory()->create(['name' => 'Tenant B User']);
    $contextB = new TenantContext(activeTenantId: $tenantB->getKey());

    app(TenantContextStore::class)->set($contextB);
    $tenantB->makeCurrent();
    app(PersonalProjectProvisioner::class)->ensureFor($userB, $contextB);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    Sanctum::actingAs($userB);

    // Active tenant for $userB is tenant B; the doc belongs to tenant A.
    // The early tenant-mismatch guard short-circuits with 404 before
    // AccessResolver is even consulted.
    $this->getJson('/api/v1/docs/'.$created['id'])->assertNotFound();
});

it('refuses to publish when the role does not grant docs.publish', function (): void {
    [$tenant, $owner, $project] = provisionDocsActor();
    Sanctum::actingAs($owner);

    $created = $this->postJson('/api/v1/docs', [
        'project_id' => $project->getKey(),
        'title' => 'Doc',
        'markdown' => 'body',
    ])->assertCreated()->json('data');

    // Force the owner's role binding down to a 'student' role —
    // student.permissions has docs.view/create/edit but no publish.
    // This proves the AccessResolver predicate fires on the actual
    // permission, not just on existence of a binding.
    RoleBinding::query()
        ->where('principal_id', (string) $owner->getKey())
        ->where('resource_type', 'project')
        ->update(['role' => 'student']);

    $this->postJson('/api/v1/docs/'.$created['id'].'/publish')->assertForbidden();

    expect(AuditEvent::query()
        ->where('event_type', 'docs.page')
        ->where('action', 'publish')
        ->where('result', 'denied')
        ->count())->toBe(1);
});

it('hides drafts from non-owner viewers without docs.publish', function (): void {
    // Codex M8 S2 P1 #1: draft visibility regression. Owner-author
    // creates a doc; a second project member with docs.view (TA role)
    // must not be able to read the unpublished draft.
    [$tenant, $owner, $project] = provisionDocsActor();
    Sanctum::actingAs($owner);

    $created = $this->postJson('/api/v1/docs', [
        'project_id' => $project->getKey(),
        'title' => 'Private draft',
        'markdown' => 'shh',
    ])->assertCreated()->json('data');

    // Add a TA into the same project. TA role has docs.view + edit but
    // NOT docs.publish, so they cannot read another user's draft.
    $ta = User::factory()->create(['name' => 'Lab TA']);
    RoleBinding::query()->create([
        'principal_type' => 'user',
        'principal_id' => (string) $ta->getKey(),
        'role' => 'ta',
        'resource_type' => 'project',
        'resource_id' => $project->getKey(),
        'scope_type' => App\Domain\Tenancy\RoleBindingScopeType::TenantLocal,
        'tenant_id' => $tenant->getKey(),
        'tenant_set' => [],
        'granted_by_id' => $owner->getKey(),
        'granted_reason' => 'test',
    ]);

    Sanctum::actingAs($ta);

    $this->getJson('/api/v1/docs/'.$created['id'])->assertNotFound();

    // After the owner publishes, the TA can read the doc.
    Sanctum::actingAs($owner);
    $this->postJson('/api/v1/docs/'.$created['id'].'/publish')->assertOk();

    Sanctum::actingAs($ta);
    $this->getJson('/api/v1/docs/'.$created['id'])->assertOk();
});

it('blocks non-owner TAs from editing a draft', function (): void {
    // Codex M8 S2 P1 #1: draft-edit gate.
    [$tenant, $owner, $project] = provisionDocsActor();
    Sanctum::actingAs($owner);

    $created = $this->postJson('/api/v1/docs', [
        'project_id' => $project->getKey(),
        'title' => 'Draft',
        'markdown' => 'body',
    ])->assertCreated()->json('data');

    $ta = User::factory()->create(['name' => 'Lab TA']);
    RoleBinding::query()->create([
        'principal_type' => 'user',
        'principal_id' => (string) $ta->getKey(),
        'role' => 'ta',
        'resource_type' => 'project',
        'resource_id' => $project->getKey(),
        'scope_type' => App\Domain\Tenancy\RoleBindingScopeType::TenantLocal,
        'tenant_id' => $tenant->getKey(),
        'tenant_set' => [],
        'granted_by_id' => $owner->getKey(),
        'granted_reason' => 'test',
    ]);

    Sanctum::actingAs($ta);

    $this->patchJson('/api/v1/docs/'.$created['id'], [
        'title' => 'Hijacked',
        'markdown' => 'overwrite',
    ])->assertForbidden();

    expect(AuditEvent::query()
        ->where('event_type', 'docs.page')
        ->where('action', 'update')
        ->where('result', 'denied')
        ->whereJsonContains('metadata->reason', 'draft_owner_only')
        ->count())->toBe(1);
});

it('audits a denied read via the show endpoint instead of silently 404ing', function (): void {
    // Codex M8 S2 P1 #3: denied reads must emit an audit row.
    [, $owner, $project] = provisionDocsActor();
    Sanctum::actingAs($owner);

    $created = $this->postJson('/api/v1/docs', [
        'project_id' => $project->getKey(),
        'title' => 'Doc',
        'markdown' => 'body',
    ])->assertCreated()->json('data');

    $outsider = User::factory()->create(['name' => 'Outsider']);
    Sanctum::actingAs($outsider);

    $this->getJson('/api/v1/docs/'.$created['id'])->assertNotFound();

    expect(AuditEvent::query()
        ->where('event_type', 'docs.page')
        ->where('action', 'read')
        ->where('result', 'denied')
        ->count())->toBe(1);
});

it('returns 403 from the index when token lacks docs.view', function (): void {
    // Codex M8 S2 P1 #4: index used to return 200 with empty data when
    // the token lacked docs.view, hiding scope errors. It must now 403
    // like every other index endpoint.
    [, $user, $project] = provisionDocsActor();

    // Issue a Track B PAT with only `project.read` ability — no docs.view.
    Sanctum::actingAs($user);
    $response = $this->postJson('/api/v1/tokens', [
        'name' => 'no-docs-token',
        'project_id' => $project->getKey(),
        'abilities' => ['project.read'],
    ])->assertCreated();

    $plainTextToken = $response->json('data.plain_text_token');
    expect($plainTextToken)->toBeString();

    $this->withHeaders(['Authorization' => 'Token '.$plainTextToken])
        ->getJson('/api/v1/docs')
        ->assertForbidden();
});

it('returns 404 (not 422) when project_id belongs to a different tenant', function (): void {
    // Codex M8 S2 P1 #5: validation must not leak cross-tenant project
    // existence by distinguishing 422 (does-not-exist) from 404
    // (exists-but-not-yours).
    [, $userA, $projectA] = provisionDocsActor();

    // Tenant B owns its own project.
    $tenantB = Tenant::query()->create(['name' => 'Tenant B', 'slug' => 'tenant-b']);
    $userB = User::factory()->create(['name' => 'Tenant B User']);
    $contextB = new TenantContext(activeTenantId: $tenantB->getKey());
    app(TenantContextStore::class)->set($contextB);
    $tenantB->makeCurrent();
    $projectB = app(PersonalProjectProvisioner::class)->ensureFor($userB, $contextB);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    Sanctum::actingAs($userA);

    // Acting in tenant A, try to create a doc against tenant B's project.
    // Same status as a wholly nonexistent project id — both 404.
    $this->postJson('/api/v1/docs', [
        'project_id' => $projectB->getKey(),
        'title' => 'Cross-tenant attempt',
        'markdown' => 'body',
    ])->assertNotFound();

    $this->postJson('/api/v1/docs', [
        'project_id' => '01HZDOESNOTEXIST00000000000',
        'title' => 'Nonexistent project',
        'markdown' => 'body',
    ])->assertNotFound();
});

it('lists docs for the active tenant via the index endpoint', function (): void {
    [, $user, $project] = provisionDocsActor();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/docs', [
        'project_id' => $project->getKey(),
        'title' => 'First',
        'markdown' => 'a',
    ])->assertCreated();

    $this->postJson('/api/v1/docs', [
        'project_id' => $project->getKey(),
        'title' => 'Second',
        'markdown' => 'b',
    ])->assertCreated();

    $response = $this->getJson('/api/v1/docs')->assertOk()->json('data');

    expect($response)->toHaveCount(2)
        ->and(array_column($response, 'title'))->toContain('First', 'Second');
});
