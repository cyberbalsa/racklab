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

it('issues a console grant for the owner over the session web route', function (): void {
    app(RbacDefaultsSynchronizer::class)->sync();
    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create();
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);

    Sanctum::actingAs($user);
    $deploymentId = test()->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'console-web-fixture',
    ])->assertCreated()->json('data.id');
    auth()->forgetGuards();
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    $deployment = Deployment::query()->whereKey($deploymentId)->firstOrFail();

    $this->actingAs($user)
        ->post('/deployments/'.$deployment->getKey().'/console-grant', ['console_kind' => 'vnc'])
        ->assertOk()
        ->assertJsonPath('data.deployment_id', $deployment->resourceId())
        ->assertJsonStructure(['data' => ['grant_id', 'jwt', 'console_kind']]);
});
