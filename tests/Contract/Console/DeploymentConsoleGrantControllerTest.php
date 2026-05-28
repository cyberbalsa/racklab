<?php

declare(strict_types=1);

use App\Auth\Jwt\ConsoleAccessGrantVerifier;
use App\Domain\Console\ConsoleKind;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('rejects anonymous requests for a console grant', function (): void {
    [, , $deployment] = provisionConsoleEndpointFixture();

    $this->postJson('/api/v1/deployments/'.$deployment->getKey().'/console-grant', [
        'console_kind' => 'vnc',
    ])->assertUnauthorized();
});

it('returns 422 for an unknown console_kind value', function (): void {
    [, $user, $deployment] = provisionConsoleEndpointFixture();

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/deployments/'.$deployment->getKey().'/console-grant', [
        'console_kind' => 'spice',
    ])->assertStatus(422)->assertJsonValidationErrors('console_kind');
});

it('issues a JWT and emits console.session.start when the actor has deployment.console.connect', function (): void {
    [$tenant, $user, $deployment] = provisionConsoleEndpointFixture();

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/deployments/'.$deployment->getKey().'/console-grant', [
        'console_kind' => 'vnc',
    ])->assertOk();

    $payload = $response->json('data');
    expect($payload)->toBeArray()
        ->and($payload['deployment_id'])->toBe($deployment->getKey())
        ->and($payload['console_kind'])->toBe('vnc')
        ->and($payload['jwt'])->toBeString()
        ->and(strlen((string) $payload['jwt']))->toBeGreaterThan(80)
        ->and($payload['grant_id'])->toBeString();

    // Verify the returned JWT is a real, verifiable console grant — defense in depth.
    $grant = app(ConsoleAccessGrantVerifier::class)->verify($payload['jwt']);
    expect($grant->deploymentId)->toBe($deployment->getKey())
        ->and($grant->consoleKind)->toBe(ConsoleKind::Vnc)
        ->and($grant->tenantId)->toBe($tenant->getKey());

    expect(AuditEvent::query()
        ->where('event_type', 'console.session.start')
        ->where('result', 'allowed')
        ->where('actor_id', (string) $user->getKey())
        ->where('resource_id', $deployment->getKey())
        ->exists()
    )->toBeTrue();
});

it('returns 404 when the deployment belongs to another tenant', function (): void {
    [, $user, $deployment] = provisionConsoleEndpointFixture();

    // Provision a second tenant where the user has no membership.
    /** @var Tenant $otherTenant */
    $otherTenant = Tenant::query()->create(['name' => 'Other Tenant', 'slug' => 'other']);
    $otherUser = User::factory()->create(['name' => 'Other Owner', 'email' => 'other-owner@example.test']);
    $otherContext = new TenantContext(activeTenantId: $otherTenant->getKey());
    app(TenantContextStore::class)->set($otherContext);
    $otherTenant->makeCurrent();
    $otherProject = app(PersonalProjectProvisioner::class)->ensureFor($otherUser, $otherContext);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    Sanctum::actingAs($otherUser);
    $otherDeploymentId = $this->postJson('/api/v1/deployments', [
        'project_id' => $otherProject->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'other-tenant-vm',
    ])->assertCreated()->json('data.id');

    // Switch back to the original user/tenant and try to grant a console on a foreign-tenant deployment.
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/deployments/'.$otherDeploymentId.'/console-grant', [
        'console_kind' => 'vnc',
    ])->assertNotFound();

    unset($deployment);
});

it('refuses to mint a new console grant from a console-type Track A JWT', function (): void {
    [$tenant, $user, $deployment] = provisionConsoleEndpointFixture();

    // Mint a real console grant first via the API itself.
    Sanctum::actingAs($user);
    $bootstrap = $this->postJson('/api/v1/deployments/'.$deployment->getKey().'/console-grant', [
        'console_kind' => 'vnc',
    ])->assertOk()->json('data');

    // Replay it: an attacker holding the console JWT must not be able to mint another.
    auth()->forgetGuards();

    $response = $this->withHeader('Authorization', 'Bearer '.$bootstrap['jwt'])
        ->postJson('/api/v1/deployments/'.$deployment->getKey().'/console-grant', [
            'console_kind' => 'vnc',
        ]);

    $response->assertForbidden();

    expect(AuditEvent::query()
        ->where('event_type', 'console.access.denied')
        ->where('result', 'denied')
        ->where('actor_id', (string) $user->getKey())
        ->where('resource_id', $deployment->getKey())
        ->whereJsonContains('metadata->reason', 'console_grant_self_refresh')
        ->exists()
    )->toBeTrue();

    unset($tenant);
});

it('audits console.access.denied when a Track B PAT lacks deployment.console.connect', function (): void {
    [$tenant, $user, $deployment] = provisionConsoleEndpointFixture();

    /** @var App\Models\Project $project */
    $project = App\Models\Project::query()->where('created_for_user_id', $user->getKey())->firstOrFail();

    // Issue a real Track B PAT scoped to `deployment.read` only, then call /console-grant with it.
    auth()->forgetGuards();
    Sanctum::actingAs($user);
    $token = $this->postJson('/api/v1/tokens', [
        'name' => 'read-only',
        'project_id' => $project->getKey(),
        'abilities' => ['deployment.read'],
    ])->assertCreated()->json('data');
    auth()->forgetGuards();

    $this->withHeader('Authorization', $token['authorization_header'])
        ->postJson('/api/v1/deployments/'.$deployment->getKey().'/console-grant', [
            'console_kind' => 'vnc',
        ])
        ->assertForbidden();

    expect(AuditEvent::query()
        ->where('event_type', 'console.access.denied')
        ->where('result', 'denied')
        ->where('actor_id', (string) $user->getKey())
        ->where('resource_id', $deployment->getKey())
        ->whereJsonContains('metadata->reason', 'token_missing_ability')
        ->exists()
    )->toBeTrue();

    unset($tenant);
});

it('denies the API when the actor lacks deployment.console.connect and audits the denial', function (): void {
    [$tenant, , $deployment] = provisionConsoleEndpointFixture();

    // Provision an unrelated user in the same tenant (no role binding to the deployment).
    $outsider = User::factory()->create([
        'name' => 'Console Outsider',
        'email' => 'outsider-console@example.test',
    ]);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    app(PersonalProjectProvisioner::class)->ensureFor($outsider, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    Sanctum::actingAs($outsider);

    $this->postJson('/api/v1/deployments/'.$deployment->getKey().'/console-grant', [
        'console_kind' => 'vnc',
    ])->assertForbidden();

    expect(AuditEvent::query()
        ->where('event_type', 'console.access.denied')
        ->where('result', 'denied')
        ->where('actor_id', (string) $outsider->getKey())
        ->where('resource_id', $deployment->getKey())
        ->exists()
    )->toBeTrue();
});

/**
 * @return array{Tenant, User, Deployment}
 */
function provisionConsoleEndpointFixture(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->firstOrCreate(
        ['slug' => 'default'],
        ['name' => 'Default Tenant'],
    );
    $user = User::factory()->create([
        'name' => 'Console API User',
        'email' => 'console-api@example.test',
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
        'idempotency_key' => 'console-endpoint-fixture',
    ])->assertCreated()->json('data.id');

    /** @var Deployment $deployment */
    $deployment = Deployment::query()->whereKey($deploymentId)->firstOrFail();

    auth()->forgetGuards();

    return [$tenant, $user, $deployment];
}
