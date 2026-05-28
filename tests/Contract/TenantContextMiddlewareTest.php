<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\SetTenantContextForOctane;
use App\Models\Tenant;
use App\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

it('binds tenant context from the RackLab tenant header', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    $store = new TenantContextStore;
    $middleware = new IdentifyTenant($store, new TenantResolver);
    $request = Request::create('/hello', server: ['HTTP_X_RACKLAB_TENANT' => 'tenant-a']);

    $response = $middleware->handle($request, function () use ($store, $tenant): Response {
        expect($store->current()?->activeTenantId)->toBe($tenant->getKey());
        expect(Tenant::current()?->is($tenant))->toBeTrue();

        return new Response('ok');
    });

    expect($response->getContent())->toBe('ok');
});

it('clears stale tenant context before and after an Octane request lifecycle', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Tenant B', 'slug' => 'tenant-b']);
    $store = new TenantContextStore;
    $store->set(new TenantContext(activeTenantId: 'stale-tenant'));

    $reset = new SetTenantContextForOctane($store);
    $identify = new IdentifyTenant($store, new TenantResolver);
    $request = Request::create('/hello', server: ['HTTP_X_RACKLAB_TENANT' => 'tenant-b']);

    $response = $reset->handle($request, function (Request $request) use ($identify, $store, $tenant): Response {
        expect($store->current())->toBeNull();

        return $identify->handle($request, function () use ($store, $tenant): Response {
            expect($store->current()?->activeTenantId)->toBe($tenant->getKey());
            expect(Tenant::current()?->is($tenant))->toBeTrue();

            return new Response('ok');
        });
    });

    expect($store->current()?->activeTenantId)->toBe($tenant->getKey());

    $reset->terminate();

    expect($store->current())->toBeNull();
    expect(Tenant::current())->toBeNull();
});

it('registers tenant context middleware in the HTTP kernel', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Tenant C', 'slug' => 'tenant-c']);

    Route::get('/__tenant-context-contract', static fn (TenantContextStore $store): Response => new Response($store->current()?->activeTenantId ?? 'none'));

    app(TenantContextStore::class)->set(new TenantContext(activeTenantId: 'stale-tenant'));

    $this->withHeader('X-RackLab-Tenant', 'tenant-c')
        ->get('/__tenant-context-contract')
        ->assertOk()
        ->assertSee($tenant->getKey());

    expect(app(TenantContextStore::class)->current())->toBeNull();
    expect(Tenant::current())->toBeNull();
});

it('rejects an unknown tenant identifier before binding request context', function (): void {
    Route::get('/__unknown-tenant-contract', static fn (TenantContextStore $store): Response => new Response($store->current()?->activeTenantId ?? 'none'));

    $this->withHeader('X-RackLab-Tenant', 'missing-tenant')
        ->get('/__unknown-tenant-contract')
        ->assertNotFound();

    expect(app(TenantContextStore::class)->current())->toBeNull();
});
