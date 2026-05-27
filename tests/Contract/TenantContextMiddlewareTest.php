<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\SetTenantContextForOctane;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

it('binds tenant context from the RackLab tenant header', function (): void {
    $store = new TenantContextStore;
    $middleware = new IdentifyTenant($store);
    $request = Request::create('/hello', server: ['HTTP_X_RACKLAB_TENANT' => 'tenant-a']);

    $response = $middleware->handle($request, function () use ($store): Response {
        expect($store->current()?->activeTenantId)->toBe('tenant-a');

        return new Response('ok');
    });

    expect($response->getContent())->toBe('ok');
});

it('clears stale tenant context before and after an Octane request lifecycle', function (): void {
    $store = new TenantContextStore;
    $store->set(new TenantContext(activeTenantId: 'stale-tenant'));

    $reset = new SetTenantContextForOctane($store);
    $identify = new IdentifyTenant($store);
    $request = Request::create('/hello', server: ['HTTP_X_RACKLAB_TENANT' => 'tenant-b']);

    $response = $reset->handle($request, function (Request $request) use ($identify, $store): Response {
        expect($store->current())->toBeNull();

        return $identify->handle($request, function () use ($store): Response {
            expect($store->current()?->activeTenantId)->toBe('tenant-b');

            return new Response('ok');
        });
    });

    expect($store->current()?->activeTenantId)->toBe('tenant-b');

    $reset->terminate();

    expect($store->current())->toBeNull();
});

it('registers tenant context middleware in the HTTP kernel', function (): void {
    Route::get('/__tenant-context-contract', static fn (TenantContextStore $store): Response => new Response($store->current()?->activeTenantId ?? 'none'));

    app(TenantContextStore::class)->set(new TenantContext(activeTenantId: 'stale-tenant'));

    $this->withHeader('X-RackLab-Tenant', 'tenant-c')
        ->get('/__tenant-context-contract')
        ->assertOk()
        ->assertSee('tenant-c');

    expect(app(TenantContextStore::class)->current())->toBeNull();
});
