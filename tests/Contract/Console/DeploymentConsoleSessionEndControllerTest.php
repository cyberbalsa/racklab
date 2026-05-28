<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\JwtRevocation;
use App\Models\Tenant;
use App\Models\TokenGrant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('revokes a console grant by jti and emits console.session.end on disconnect', function (): void {
    [, $user, $deployment, $grantId, $jti] = provisionEndSessionFixture();

    $this->actingAs($user)
        ->deleteJson('/api/v1/deployments/'.$deployment->getKey().'/console-sessions/'.$grantId)
        ->assertNoContent();

    expect(JwtRevocation::query()->where('jti', $jti)->exists())->toBeTrue();
    expect(TokenGrant::query()->whereKey($grantId)->firstOrFail()->revoked_at)->not->toBeNull();

    expect(AuditEvent::query()
        ->where('event_type', 'console.session.end')
        ->where('result', 'allowed')
        ->where('actor_id', (string) $user->getKey())
        ->where('resource_id', $deployment->getKey())
        ->exists()
    )->toBeTrue();
});

it('returns 404 when the console grant id does not exist for this deployment', function (): void {
    [, $user, $deployment] = provisionEndSessionFixture();

    $this->actingAs($user)
        ->deleteJson('/api/v1/deployments/'.$deployment->getKey().'/console-sessions/01HZNOTAREAL00000000000000')
        ->assertNotFound();
});

it('refuses to revoke another users console grant', function (): void {
    [$tenant, , $deployment, $grantId] = provisionEndSessionFixture();

    $otherUser = User::factory()->create(['name' => 'Other Detail', 'email' => 'other-detail@example.test']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    app(PersonalProjectProvisioner::class)->ensureFor($otherUser, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    $this->actingAs($otherUser)
        ->deleteJson('/api/v1/deployments/'.$deployment->getKey().'/console-sessions/'.$grantId)
        ->assertForbidden();
});

/**
 * @return array{Tenant, User, Deployment, string, string}
 */
function provisionEndSessionFixture(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->firstOrCreate(
        ['slug' => 'default'],
        ['name' => 'Default Tenant'],
    );
    $user = User::factory()->create([
        'name' => 'End Session Owner',
        'email' => 'end-session-owner@example.test',
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
        'idempotency_key' => 'end-fixture',
    ])->assertCreated()->json('data.id');

    $grant = test()->postJson('/api/v1/deployments/'.$deploymentId.'/console-grant', [
        'console_kind' => 'vnc',
    ])->assertOk()->json('data');

    auth()->forgetGuards();

    /** @var Deployment $deployment */
    $deployment = Deployment::query()->whereKey($deploymentId)->firstOrFail();

    /** @var TokenGrant $tokenGrant */
    $tokenGrant = TokenGrant::query()->whereKey($grant['grant_id'])->firstOrFail();

    return [$tenant, $user, $deployment, (string) $grant['grant_id'], (string) $tokenGrant->jti];
}
