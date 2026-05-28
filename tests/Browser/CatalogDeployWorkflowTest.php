<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Database\Seeders\CatalogDemoSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;

uses(DatabaseMigrations::class);

it('logs in, browses the catalog, and deploys an item into a project in a real browser', function (): void {
    [, $user] = provisionCatalogDeployBrowserActor();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser
            ->loginAs($user)
            ->visit('/catalog')
            ->waitForText('Catalog')
            ->assertSee('Ubuntu Server 22.04')
            ->assertSee('Debian 12')
            // deploy the Ubuntu item into the (default-selected) personal project
            ->click('@catalog-deploy-ubuntu-2204')
            ->waitForLocation('/dashboard')
            ->waitForText('Deployments')
            ->assertSee('Ubuntu Server 22.04');
    });
});

/**
 * @return array{Tenant, User, Project}
 */
function provisionCatalogDeployBrowserActor(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create([
        'name' => 'Default Tenant',
        'slug' => config('racklab.default_tenant_slug', 'default'),
    ]);
    $user = User::factory()->create([
        'name' => 'Catalog Deployer',
        'email' => 'catalog-deployer@example.test',
    ]);

    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    app(CatalogDemoSeeder::class)->run();

    return [$tenant, $user, $project];
}
