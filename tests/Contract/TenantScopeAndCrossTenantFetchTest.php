<?php

declare(strict_types=1);

use App\Domain\Rbac\Permission;
use App\Domain\Rbac\RolePermissionLookup;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\CrossTenantFetch;
use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\AuditEvent;
use App\Models\RoleBinding;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\EloquentRoleBindingRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\TenantScopedWidget;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Schema::create('tenant_scoped_widgets', function (Blueprint $table): void {
        $table->id();
        $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
        $table->string('name');
        $table->string('sharing_scope')->default('tenant_local');
        $table->json('shared_with_tenants')->nullable();
        $table->timestamps();
    });
});

it('applies the active tenant as a global scope and fills tenant id on create', function (): void {
    $tenantA = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    $tenantB = Tenant::query()->create(['name' => 'Tenant B', 'slug' => 'tenant-b']);
    $store = app(TenantContextStore::class);

    $store->set(new TenantContext(activeTenantId: $tenantA->getKey()));

    $created = TenantScopedWidget::query()->create(['name' => 'A widget']);

    $store->set(new TenantContext(activeTenantId: $tenantB->getKey()));
    TenantScopedWidget::query()->create(['name' => 'B widget']);

    expect($created->tenant_id)->toBe($tenantA->getKey())
        ->and(TenantScopedWidget::query()->pluck('name')->all())->toBe(['B widget']);

    $store->set(new TenantContext(activeTenantId: $tenantA->getKey()));

    expect(TenantScopedWidget::query()->pluck('name')->all())->toBe(['A widget']);
});

it('fetches legitimate cross-tenant rows only through CrossTenantFetch and audits cross-tenant outcomes', function (): void {
    $tenantA = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    $tenantB = Tenant::query()->create(['name' => 'Tenant B', 'slug' => 'tenant-b']);
    $tenantC = Tenant::query()->create(['name' => 'Tenant C', 'slug' => 'tenant-c']);
    $user = User::factory()->create();
    $store = app(TenantContextStore::class);
    $store->set(new TenantContext(activeTenantId: $tenantA->getKey()));

    $local = TenantScopedWidget::query()->create(['name' => 'local']);
    $shared = TenantScopedWidget::query()->create([
        'tenant_id' => $tenantB->getKey(),
        'name' => 'shared',
        'sharing_scope' => 'shared_with_tenants',
        'shared_with_tenants' => [$tenantA->getKey()],
    ]);
    $denied = TenantScopedWidget::query()->create([
        'tenant_id' => $tenantC->getKey(),
        'name' => 'denied',
    ]);

    createWidgetBinding($user, $tenantA->getKey(), $local->resourceId(), RoleBindingScopeType::TenantLocal, [$tenantA->getKey()]);
    $sharedBindingId = createWidgetBinding($user, $tenantB->getKey(), $shared->resourceId(), RoleBindingScopeType::MultiTenant, [$tenantB->getKey()]);
    createWidgetBinding($user, $tenantC->getKey(), $denied->resourceId(), RoleBindingScopeType::MultiTenant, [$tenantC->getKey()]);

    $fetch = new CrossTenantFetch(
        accessResolver: new AccessResolver(
            roleBindings: app(EloquentRoleBindingRepository::class),
            rolePermissions: new class implements RolePermissionLookup
            {
                public function roleGrants(string $role, Permission $permission): bool
                {
                    return $role === 'Student' && $permission->code === 'widget.read';
                }
            },
        ),
        auditEvents: app(App\Audit\AuditEventWriter::class),
    );

    $results = $fetch->resolveForFetch(
        actor: new ActorIdentity((string) $user->getKey()),
        permission: new Permission('widget.read'),
        modelClass: TenantScopedWidget::class,
        filters: [],
        context: new TenantContext(activeTenantId: $tenantA->getKey()),
    );

    expect(array_map(static fn (App\Domain\Tenancy\CrossTenantFetchResult $result): string => $result->resource->name, $results))
        ->toBe(['local', 'shared'])
        ->and($results[1]->provenance)->toBe([
            'binding:'.$sharedBindingId.':multi_tenant',
            'sharing:shared_with_tenants:'.$tenantB->getKey(),
        ]);

    $auditRows = AuditEvent::query()
        ->where('event_type', 'tenant.cross_access')
        ->orderBy('id')
        ->get();

    expect($auditRows)->toHaveCount(2)
        ->and($auditRows[0]->resource_tenant)->toBe($tenantB->getKey())
        ->and($auditRows[0]->result)->toBe('allowed')
        ->and($auditRows[0]->metadata['reason'])->toBe('allowed')
        ->and($auditRows[1]->resource_tenant)->toBe($tenantC->getKey())
        ->and($auditRows[1]->result)->toBe('denied')
        ->and($auditRows[1]->metadata['reason'])->toBe('resource_not_visible');
});

/**
 * @param  list<string>  $tenantSet
 */
function createWidgetBinding(
    User $user,
    string $tenantId,
    string $resourceId,
    RoleBindingScopeType $scopeType,
    array $tenantSet,
): string {
    $binding = RoleBinding::query()->create([
        'principal_type' => 'user',
        'principal_id' => (string) $user->getKey(),
        'role' => 'Student',
        'resource_type' => 'tenant_scoped_widget',
        'resource_id' => $resourceId,
        'scope_type' => $scopeType,
        'tenant_id' => $scopeType === RoleBindingScopeType::TenantLocal ? $tenantId : null,
        'tenant_set' => $tenantSet,
    ]);

    return $binding->id;
}
