<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows a guest a log in link in the navigation chrome', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee(route('login'), escape: false)
        ->assertSee('RackLab', escape: false);
});

it('shows an authenticated member catalog and dashboard navigation links', function (): void {
    app(RbacDefaultsSynchronizer::class)->sync();
    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create(['name' => 'Nav Member']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    app(PersonalProjectProvisioner::class)->ensureFor($user, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee(route('catalog'), escape: false)
        ->assertSee(route('dashboard'), escape: false)
        ->assertSee('Catalog', escape: false);
});
