<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\ProviderCapacitySnapshot;
use App\Models\Tenant;
use App\Scheduling\PlacementRequest;
use App\Scheduling\ProviderScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('selects an eligible Proxmox node with template locality', function (): void {
    $tenant = createSchedulerTenant();
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    createSchedulerSnapshot($tenant, 'maintenance-node', maintenanceMode: true, templates: [9000], memoryMb: 131_072);
    createSchedulerSnapshot($tenant, 'large-node', templates: [], memoryMb: 131_072);
    createSchedulerSnapshot($tenant, 'template-node', templates: [9000], memoryMb: 16_384);

    $decision = app(ProviderScheduler::class)->schedule($context, new PlacementRequest(
        provider: 'proxmox',
        requiredVcpus: 2,
        requiredMemoryMb: 2048,
        requiredStorageGb: 20,
        templateVmid: 9000,
    ));

    expect($decision->node)->toBe('template-node')
        ->and($decision->candidateNodes)->toBe(['large-node', 'template-node'])
        ->and($decision->reasons)->toContain('template_locality');
});

it('returns a placement validation error when no node has enough capacity', function (): void {
    $tenant = createSchedulerTenant();
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    createSchedulerSnapshot($tenant, 'small-node', vcpus: 1, memoryMb: 512, storageGb: 5);

    app(ProviderScheduler::class)->schedule($context, new PlacementRequest(
        provider: 'proxmox',
        requiredVcpus: 2,
        requiredMemoryMb: 2048,
        requiredStorageGb: 20,
        templateVmid: 9000,
    ));
})->throws(ValidationException::class);

it('respects anti-affinity excluded nodes when capacity allows', function (): void {
    $tenant = createSchedulerTenant();
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    createSchedulerSnapshot($tenant, 'node-a', templates: [9000], memoryMb: 65_536);
    createSchedulerSnapshot($tenant, 'node-b', templates: [], memoryMb: 32_768);

    $decision = app(ProviderScheduler::class)->schedule($context, new PlacementRequest(
        provider: 'proxmox',
        requiredVcpus: 2,
        requiredMemoryMb: 2048,
        requiredStorageGb: 20,
        templateVmid: 9000,
        antiAffinityExcludedNodes: ['node-a'],
    ));

    expect($decision->node)->toBe('node-b')
        ->and($decision->candidateNodes)->toBe(['node-b'])
        ->and($decision->reasons)->toContain('anti_affinity');
});

it('returns a placement validation error when anti-affinity excludes every eligible node', function (): void {
    $tenant = createSchedulerTenant();
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    createSchedulerSnapshot($tenant, 'node-a', templates: [9000], memoryMb: 65_536);

    app(ProviderScheduler::class)->schedule($context, new PlacementRequest(
        provider: 'proxmox',
        requiredVcpus: 2,
        requiredMemoryMb: 2048,
        requiredStorageGb: 20,
        templateVmid: 9000,
        antiAffinityExcludedNodes: ['node-a'],
    ));
})->throws(ValidationException::class);

function createSchedulerTenant(): Tenant
{
    $tenant = Tenant::query()->create(['name' => 'Scheduler Tenant', 'slug' => 'scheduler']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    return $tenant;
}

/**
 * @param  list<int>  $templates
 */
function createSchedulerSnapshot(
    Tenant $tenant,
    string $node,
    bool $maintenanceMode = false,
    array $templates = [],
    int $vcpus = 8,
    int $memoryMb = 32_768,
    int $storageGb = 200,
): ProviderCapacitySnapshot {
    /** @var ProviderCapacitySnapshot $snapshot */
    $snapshot = ProviderCapacitySnapshot::query()->create([
        'tenant_id' => $tenant->getKey(),
        'provider' => 'proxmox',
        'provider_cluster' => 'default',
        'node' => $node,
        'healthy' => true,
        'maintenance_mode' => $maintenanceMode,
        'available_vcpus' => $vcpus,
        'available_memory_mb' => $memoryMb,
        'available_storage_gb' => $storageGb,
        'job_pressure' => 0,
        'templates' => $templates,
        'tags' => [],
        'metadata' => [],
        'observed_at' => now(),
    ]);

    return $snapshot;
}
