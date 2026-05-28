<?php

declare(strict_types=1);

use App\Broadcasting\BroadcastEventLogWriter;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Events\RackLabBroadcastEvent;
use App\Identity\PersonalProjectProvisioner;
use App\Models\Deployment;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

uses(RefreshDatabase::class);

it('dispatches a broadcastable event for each durable replay-log append', function (): void {
    Event::fake([RackLabBroadcastEvent::class]);

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $event = app(BroadcastEventLogWriter::class)->append(
        tenantId: $tenant->getKey(),
        channel: 'private-tenant.'.$tenant->getKey().'.deployment.01HDEPLOYMENT000000000000',
        eventClass: 'App\\Events\\Deployments\\DeploymentStateChanged',
        payload: ['deployment_id' => '01HDEPLOYMENT000000000000', 'state' => 'running'],
    );

    Event::assertDispatched(
        RackLabBroadcastEvent::class,
        fn (RackLabBroadcastEvent $broadcast): bool => $broadcast->eventId === $event->getKey()
            && $broadcast->channel === $event->channel
            && $broadcast->eventClass === $event->event_class
            && $broadcast->broadcastOn()[0]->name === $event->channel
            && $broadcast->broadcastWith()['payload'] === $event->payload,
    );
});

it('authorizes private deployment channels through RackLab access policy', function (): void {
    [$tenant, $user, $otherUser, $deploymentId] = provisionBroadcastingDeployment($this);

    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'racklab-key',
        'broadcasting.connections.reverb.secret' => 'racklab-secret',
        'broadcasting.connections.reverb.app_id' => 'racklab-app',
        'broadcasting.connections.reverb.options.host' => '127.0.0.1',
        'broadcasting.connections.reverb.options.port' => 8080,
        'broadcasting.connections.reverb.options.scheme' => 'http',
        'broadcasting.connections.reverb.options.useTLS' => false,
    ]);
    Broadcast::setDefaultDriver('reverb');
    Broadcast::purge('reverb');
    require base_path('routes/channels.php');

    $channelName = 'private-tenant.'.$tenant->getKey().'.deployment.'.$deploymentId;
    $payload = ['socket_id' => '1234.5678', 'channel_name' => $channelName];
    /** @var Deployment $deployment */
    $deployment = Deployment::query()->whereKey($deploymentId)->firstOrFail();
    $decision = app(AccessResolver::class)->permitted(
        new ActorIdentity((string) $user->id),
        new Permission('deployment.read'),
        $deployment,
        new TenantContext($tenant->getKey()),
    );

    expect($decision->allowed)->toBeTrue();

    $this->actingAs($user)
        ->withHeader('X-RackLab-Tenant', $tenant->slug)
        ->postJson('/broadcasting/auth', $payload)
        ->assertOk()
        ->assertJsonStructure(['auth']);

    $this->actingAs($otherUser)
        ->withHeader('X-RackLab-Tenant', $tenant->slug)
        ->postJson('/broadcasting/auth', $payload)
        ->assertForbidden();
});

/**
 * @return array{Tenant, User, User, string}
 */
function provisionBroadcastingDeployment(TestCase $test): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create(['name' => 'Broadcast Student', 'email' => 'broadcast@example.test']);
    $otherUser = User::factory()->create(['name' => 'Other Student', 'email' => 'broadcast-other@example.test']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);
    app(PersonalProjectProvisioner::class)->ensureFor($otherUser, $context);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    Sanctum::actingAs($user);

    $deploymentId = $test->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'broadcast-channel-new-vm',
    ])->assertCreated()->json('data.id');

    expect($deploymentId)->toBeString();

    return [$tenant, $user, $otherUser, $deploymentId];
}
