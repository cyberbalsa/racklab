<?php

declare(strict_types=1);

use App\Catalog\CatalogPublisher;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\AuditEvent;
use App\Models\CatalogItem;
use App\Models\CatalogVersion;
use App\Models\Project;
use App\Models\StackDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{Tenant, User, Project}
 */
function provisionPublisher(string $name = 'Instructor'): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    /** @var Tenant $tenant */
    $tenant = Tenant::query()->firstOrCreate(['slug' => 'default'], ['name' => 'Default Tenant']);
    $user = User::factory()->create(['name' => $name]);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}

function makeSourceStack(Tenant $tenant, Project $project): StackDefinition
{
    app(TenantContextStore::class)->set(new TenantContext(activeTenantId: $tenant->getKey()));
    $tenant->makeCurrent();

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

    return $stack;
}

it('publishes a project-local stack to the catalog as an immutable published version', function (): void {
    [$tenant, $user, $project] = provisionPublisher();
    $source = makeSourceStack($tenant, $project);

    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    $version = app(CatalogPublisher::class)->publish(
        actor: $user,
        context: $context,
        source: $source,
        itemName: 'Two-tier Lab',
        versionLabel: '1.0.0',
        description: 'A two-tier lab network.',
    );

    expect($version->state)->toBe('published')
        ->and($version->published_at)->not->toBeNull();

    $item = CatalogItem::query()->whereKey($version->catalog_item_id)->firstOrFail();
    expect($item->name)->toBe('Two-tier Lab')
        ->and($item->tenant_id)->toBe($tenant->getKey());

    // The published version wraps a catalog-scoped copy of the stack, not the
    // mutable project-local source.
    $catalogStack = StackDefinition::query()->whereKey($version->stack_definition_id)->firstOrFail();
    expect($catalogStack->scope)->toBe('catalog')
        ->and($catalogStack->project_id)->toBeNull()
        ->and($catalogStack->getKey())->not->toBe($source->getKey())
        ->and($catalogStack->definition)->toBe($source->definition);

    expect(AuditEvent::query()->where('event_type', 'catalog.publish')->where('result', 'allowed')->exists())->toBeTrue();

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();
});

it('rejects a duplicate version label for the same catalog item instead of a 500', function (): void {
    [$tenant, $user, $project] = provisionPublisher();
    $source = makeSourceStack($tenant, $project);

    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    app(CatalogPublisher::class)->publish($user, $context, $source, 'Two-tier Lab', '1.0.0', null);

    expect(fn () => app(CatalogPublisher::class)->publish($user, $context, $source, 'Two-tier Lab', '1.0.0', null))
        ->toThrow(App\Catalog\DuplicateCatalogVersionException::class);

    expect(CatalogVersion::query()->where('version', '1.0.0')->count())->toBe(1);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();
});

it('refuses to publish for an actor without catalog.publish on the source project', function (): void {
    [$tenant, $owner, $project] = provisionPublisher('Owner');
    $source = makeSourceStack($tenant, $project);

    [, $outsider] = provisionPublisher('Outsider');

    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    expect(fn () => app(CatalogPublisher::class)->publish(
        actor: $outsider,
        context: $context,
        source: $source,
        itemName: 'Sneaky',
        versionLabel: '1.0.0',
        description: null,
    ))->toThrow(AuthorizationException::class);

    expect(CatalogVersion::query()->count())->toBe(0);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();
});
