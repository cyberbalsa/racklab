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
    $job = new class($tenant->getKey()) implements TenantAwareJob
    {
        use CarriesTenantContext;

        public function __construct(string $tenantId)
        {
            $this->tenantId = $tenantId;
        }
    };

    $middleware->handle($job, function () use ($store, $tenant): void {
        expect($store->current()?->activeTenantId)->toBe($tenant->getKey());
    });

    expect($store->current())->toBeNull();
});
