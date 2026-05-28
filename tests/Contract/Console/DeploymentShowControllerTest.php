<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\Deployment;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('renders the deployment detail page with the console pane for an authorized user', function (): void {
    [, $user, $deployment] = provisionDeploymentShowFixture();

    $this->actingAs($user)
        ->get('/deployments/'.$deployment->getKey())
        ->assertOk()
        ->assertSee($deployment->name)
        ->assertSee($deployment->getKey())
        ->assertSee('data-testid="deployment-detail"', escape: false)
        ->assertSee('data-testid="console-pane"', escape: false)
        ->assertSee('data-testid="console-pane-authorized"', escape: false)
        ->assertSee('data-testid="novnc-viewer"', escape: false)
        ->assertSee('Connect');
});

it('returns 404 for a same-tenant actor who lacks deployment.read on the deployment', function (): void {
    [$tenant, , $deployment] = provisionDeploymentShowFixture();

    $outsider = User::factory()->create([
        'name' => 'Detail Outsider',
        'email' => 'detail-outsider@example.test',
    ]);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    app(PersonalProjectProvisioner::class)->ensureFor($outsider, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    // The page must 404 (matching the API show endpoint) so an unrelated user cannot
    // probe deployment existence by guessing an id, even when the console pane itself
    // would self-hide for unauthorized viewers.
    $this->actingAs($outsider)
        ->get('/deployments/'.$deployment->getKey())
        ->assertNotFound();
});

it('returns 404 when the deployment id does not exist', function (): void {
    [, $user] = provisionDeploymentShowFixture();

    $this->actingAs($user)
        ->get('/deployments/01HZNOTAREAL00000000000000')
        ->assertNotFound();
});

/**
 * @return array{Tenant, User, Deployment}
 */
function provisionDeploymentShowFixture(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->firstOrCreate(
        ['slug' => 'default'],
        ['name' => 'Default Tenant'],
    );
    $user = User::factory()->create([
        'name' => 'Detail Owner',
        'email' => 'detail-owner@example.test',
    ]);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    Sanctum::actingAs($user);
    $deploymentId = test()->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'detail-fixture',
    ])->assertCreated()->json('data.id');
    auth()->forgetGuards();

    /** @var Deployment $deployment */
    $deployment = Deployment::query()->whereKey($deploymentId)->firstOrFail();

    return [$tenant, $user, $deployment];
}
