<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Jobs\Concerns\CarriesTenantContext;
use App\Jobs\Contracts\TenantAwareJob;
use App\Jobs\Middleware\BindTenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('binds tenant context for the duration of a tenant-aware job and clears it afterward', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    $store = new TenantContextStore;
    $store->set(new TenantContext(activeTenantId: 'stale-tenant'));

    $middleware = new BindTenantContext($store);
    $job = makeTenantAwareJob($tenant->getKey());

    $middleware->handle($job, function () use ($store, $tenant): void {
        expect($store->current()?->activeTenantId)->toBe($tenant->getKey());
    });

    expect($store->current())->toBeNull();
});

it('drives Spatie current-tenant alongside the RackLab store', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    $store = new TenantContextStore;
    $middleware = new BindTenantContext($store);
    $job = makeTenantAwareJob($tenant->getKey());

    Tenant::forgetCurrent();

    $middleware->handle($job, function () use ($tenant): void {
        // Inside the job, both RackLab + Spatie current tenant resolve to $tenant.
        $current = Tenant::current();
        expect($current)->not->toBeNull();
        expect($current?->getKey())->toBe($tenant->getKey());
    });

    // After the job, Spatie current is cleared too.
    expect(Tenant::current())->toBeNull();
});

it('does not leak Spatie current-tenant between two sequential jobs on different tenants', function (): void {
    // codex v1 P1 regression guard. Without the Spatie call in BindTenantContext,
    // Job B would observe Tenant A's current-tenant slot until something else cleared it.
    $tenantA = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    $tenantB = Tenant::query()->create(['name' => 'Tenant B', 'slug' => 'tenant-b']);
    $store = new TenantContextStore;
    $middleware = new BindTenantContext($store);

    $middleware->handle(makeTenantAwareJob($tenantA->getKey()), function () use ($tenantA): void {
        expect(Tenant::current()?->getKey())->toBe($tenantA->getKey());
    });
    expect(Tenant::current())->toBeNull();

    $middleware->handle(makeTenantAwareJob($tenantB->getKey()), function () use ($tenantB): void {
        // The critical assertion: Spatie current must reflect tenantB, not the previous tenantA.
        expect(Tenant::current()?->getKey())->toBe($tenantB->getKey());
    });
    expect(Tenant::current())->toBeNull();
});

function makeTenantAwareJob(string $tenantId): TenantAwareJob
{
    return new class($tenantId) implements TenantAwareJob
    {
        use CarriesTenantContext;

        public function __construct(string $tenantId)
        {
            $this->tenantId = $tenantId;
        }
    };
}
