<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\BroadcastEventLog;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('replays deployment events after the supplied ULID cursor', function (): void {
    [, $user, $project] = provisionBroadcastReplayUserProject();

    Sanctum::actingAs($user);

    $deploymentId = $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'replay-new-vm',
    ])->assertCreated()->json('data.id');

    expect($deploymentId)->toBeString();

    $channel = 'private-tenant.'.Tenant::query()->firstOrFail()->getKey().'.deployment.'.$deploymentId;
    $firstEvent = BroadcastEventLog::query()->where('channel', $channel)->orderBy('id')->firstOrFail();

    $this->getJson('/api/v1/replay?channel='.urlencode($channel).'&since='.$firstEvent->getKey())
        ->assertOk()
        ->assertJsonPath('gap', false)
        ->assertJsonCount(1, 'events')
        ->assertJsonPath('events.0.payload.state', 'running');
});

it('rejects replay for deployments the actor cannot read', function (): void {
    [, $user] = provisionBroadcastReplayUserProject();
    [, $otherUser, $otherProject] = provisionBroadcastReplayUserProject(email: 'replay-other@example.test');

    Sanctum::actingAs($otherUser);

    $hiddenDeploymentId = $this->postJson('/api/v1/deployments', [
        'project_id' => $otherProject->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'hidden-replay-new-vm',
    ])->assertCreated()->json('data.id');

    expect($hiddenDeploymentId)->toBeString();

    $tenantId = Tenant::query()->firstOrFail()->getKey();
    $channel = 'private-tenant.'.$tenantId.'.deployment.'.$hiddenDeploymentId;

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/replay?channel='.urlencode($channel).'&since=00000000000000000000000000')
        ->assertForbidden();
});

it('returns a replay gap sentinel when the cursor event is outside the retention window', function (): void {
    [, $user, $project] = provisionBroadcastReplayUserProject();

    Sanctum::actingAs($user);

    $deploymentId = $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'old-replay-new-vm',
    ])->assertCreated()->json('data.id');

    expect($deploymentId)->toBeString();

    $channel = 'private-tenant.'.Tenant::query()->firstOrFail()->getKey().'.deployment.'.$deploymentId;
    $oldEvent = BroadcastEventLog::query()->where('channel', $channel)->orderBy('id')->firstOrFail();
    $oldEvent->forceFill(['created_at' => CarbonImmutable::now()->subHours(25)])->save();

    $this->getJson('/api/v1/replay?channel='.urlencode($channel).'&since='.$oldEvent->getKey())
        ->assertOk()
        ->assertJsonPath('gap', true)
        ->assertJsonCount(0, 'events');
});

/**
 * @return array{Tenant, User, Project}
 */
function provisionBroadcastReplayUserProject(?string $email = null): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->firstOrCreate(
        ['slug' => 'default'],
        ['name' => 'Default Tenant'],
    );
    $user = User::factory()->create([
        'name' => 'Replay Student',
        'email' => $email ?? fake()->unique()->safeEmail(),
    ]);
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}
