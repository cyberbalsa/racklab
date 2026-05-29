<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\CatalogVersion;
use App\Models\StackDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;

uses(DatabaseMigrations::class);

it('publishes a project-local stack to the catalog from the browser', function (): void {
    [$tenant, $user, $stack] = provisionCatalogPublishingBrowserActor();

    $this->browse(function (Browser $browser) use ($user, $stack): void {
        $browser
            ->loginAs($user)
            ->visit('/catalog/publish')
            ->waitForText('Catalog publishing')
            ->select('@publish-stack', $stack->getKey())
            ->type('@publish-name', 'Two-tier Lab')
            ->type('@publish-version', '1.0.0')
            ->click('@publish-submit')
            ->waitForText('published to the catalog')
            ->assertSee('Two-tier Lab');
    });

    expect(CatalogVersion::query()->where('state', 'published')->count())->toBe(1);
});

/**
 * @return array{Tenant, User, StackDefinition}
 */
function provisionCatalogPublishingBrowserActor(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create(['name' => 'Publisher', 'email' => 'publisher@example.test']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);

    /** @var StackDefinition $stack */
    $stack = StackDefinition::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'name' => 'Two-tier Lab',
        'slug' => 'two-tier-lab',
        'scope' => 'project_local',
        'is_reserved_default' => false,
        'definition' => ['provider' => 'fake', 'components' => [['key' => 'vm', 'kind' => 'vm']]],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $stack];
}
