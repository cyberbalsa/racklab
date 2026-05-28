<?php

declare(strict_types=1);

use App\Deployments\DeploymentLeaseExpiry;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Jobs\Maintenance\DetectProviderDriftJob;
use App\Jobs\Maintenance\ExpireDeploymentsJob;
use App\Jobs\Maintenance\ReapScriptContainersJob;
use App\Jobs\Maintenance\ReconcileProviderTasksJob;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\StackDefinition;
use App\Models\Tenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

/**
 * Resolve the scheduled events with the console schedule definition applied
 * (the withSchedule callback runs when the console kernel defines the
 * schedule, not during an HTTP test boot).
 *
 * @return Collection<int, Illuminate\Console\Scheduling\Event>
 */
function scheduledEvents(): Collection
{
    test()->artisan('schedule:list')->run();

    return collect(app(Schedule::class)->events());
}

it('schedules every reconciler as a Horizon job instead of a shell loop', function (): void {
    $descriptions = scheduledEvents()->map(fn ($event): string => (string) $event->description);

    expect($descriptions)->toContain(ReconcileProviderTasksJob::class)
        ->and($descriptions)->toContain(ExpireDeploymentsJob::class)
        ->and($descriptions)->toContain(DetectProviderDriftJob::class)
        ->and($descriptions)->toContain(ReapScriptContainersJob::class);
});

it('runs the reconciler at minute cadence and the reaper hourly', function (): void {
    $events = scheduledEvents();

    $reconcile = $events->first(fn ($event): bool => $event->description === ReconcileProviderTasksJob::class);
    $reap = $events->first(fn ($event): bool => $event->description === ReapScriptContainersJob::class);

    expect($reconcile?->expression)->toBe('* * * * *')
        ->and($reap?->expression)->toBe('0 * * * *');
});

it('expires a due deployment when the ExpireDeploymentsJob runs', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    app(TenantContextStore::class)->set(new TenantContext(activeTenantId: $tenant->getKey()));
    $tenant->makeCurrent();

    $project = Project::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Expiry Project',
        'slug' => 'expiry-project',
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    $stack = StackDefinition::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'name' => 'Stack',
        'slug' => 'expire-stack',
        'scope' => 'project_local',
        'is_reserved_default' => false,
        'definition' => ['provider' => 'fake', 'components' => []],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    /** @var Deployment $deployment */
    $deployment = Deployment::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'stack_definition_id' => $stack->getKey(),
        'requested_by_id' => null,
        'name' => 'expiring-vm',
        'state' => 'running',
        'provider' => 'fake',
        'lease_expires_at' => Carbon::now()->subHour(),
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    (new ExpireDeploymentsJob)->handle(app(DeploymentLeaseExpiry::class));

    expect($deployment->fresh()?->state)->toBe('expired');

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();
});

it('queues maintenance jobs on pools matching their capability needs', function (): void {
    // reconcile/expire/drift need no Podman → maintenance queue (app pool).
    expect((new ReconcileProviderTasksJob)->queue)->toBe('maintenance')
        ->and((new ExpireDeploymentsJob)->queue)->toBe('maintenance')
        ->and((new DetectProviderDriftJob)->queue)->toBe('maintenance')
        // the reaper needs the Podman socket → cleanup queue (runner pool).
        ->and((new ReapScriptContainersJob)->queue)->toBe('cleanup');
});

it('serves the maintenance queue from the Podman-free app pool and cleanup from the runner pool', function (): void {
    $supervisors = config('horizon.defaults');

    expect($supervisors['racklab-provider']['queue'])->toContain('maintenance')
        ->and($supervisors['racklab-scripts']['queue'])->toContain('cleanup');
});
