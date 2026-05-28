<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @return array{Tenant, User, Project}
 */
function provisionReaderActor(string $slug = 'default', string $name = 'Doc Reader'): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    /** @var Tenant|null $tenant */
    $tenant = Tenant::query()->where('slug', $slug)->first();
    $tenant ??= Tenant::query()->create(['name' => ucfirst($slug).' Tenant', 'slug' => $slug]);

    $user = User::factory()->create(['name' => $name]);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}

function createDocWithRef(User $user, Project $project, bool $publish): string
{
    Sanctum::actingAs($user);

    $id = test()->postJson('/api/v1/docs', [
        'project_id' => $project->getKey(),
        'title' => 'Lab 1 — Networking',
        'markdown' => "# Lab 1\n\nDeploy and inspect [[deployment:abc-123]] then continue.",
        'editor_message' => 'initial',
    ])->assertCreated()->json('data.id');

    if ($publish) {
        test()->postJson(sprintf('/api/v1/docs/%s/publish', $id))->assertOk();
    }

    return $id;
}

it('renders a published doc with its cross-link element and the ref-pill island', function (): void {
    [$tenant, $user, $project] = provisionReaderActor();
    $id = createDocWithRef($user, $project, publish: true);

    $this->actingAs($user)
        ->get('/docs/'.$id)
        ->assertOk()
        ->assertSee('Lab 1', escape: false)
        ->assertSee('<racklab-ref', escape: false)
        ->assertSee('data-kind="deployment"', escape: false)
        ->assertSee('data-id="abc-123"', escape: false)
        // the reader page wires the status-pill island
        ->assertSee('racklab-ref', escape: false);
});

it('lets the owner read their own unpublished draft', function (): void {
    [$tenant, $user, $project] = provisionReaderActor();
    $id = createDocWithRef($user, $project, publish: false);

    $this->actingAs($user)
        ->get('/docs/'.$id)
        ->assertOk()
        ->assertSee('Lab 1', escape: false);
});

it('returns 404 for a user with no access to the doc project (no existence leak)', function (): void {
    [$tenantA, $userA, $projectA] = provisionReaderActor('default', 'Owner A');
    $id = createDocWithRef($userA, $projectA, publish: true);

    // A second user in the same tenant with no role binding on the doc's
    // project must get 404 (not 403) — AccessResolver denies, no leak.
    [$tenantB, $userB] = provisionReaderActor('default', 'Outsider B');

    $this->actingAs($userB)
        ->get('/docs/'.$id)
        ->assertNotFound();
});

it('redirects an unauthenticated visitor to login', function (): void {
    [$tenant, $user, $project] = provisionReaderActor();
    $id = createDocWithRef($user, $project, publish: true);

    // createDocWithRef authenticated the actor; clear it for the guest check.
    Illuminate\Support\Facades\Auth::forgetGuards();

    $this->get('/docs/'.$id)->assertRedirect();
});
