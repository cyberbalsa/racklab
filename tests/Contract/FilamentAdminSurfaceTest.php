<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Filament\Resources\CourseResource;
use App\Filament\Resources\FloatingIpPoolResource;
use App\Filament\Resources\NetworkOfferingResource;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProviderDriftResource;
use App\Filament\Resources\ProviderNetworkResource;
use App\Filament\Resources\QuotaLimitResource;
use App\Filament\Resources\SubnetPoolResource;
use App\Filament\Resources\UserResource;
use App\Http\Middleware\BindFilamentTenantContext;
use App\Identity\PersonalProjectProvisioner;
use App\Models\Course;
use App\Models\FloatingIpPool;
use App\Models\NetworkOffering;
use App\Models\Project;
use App\Models\ProviderDrift;
use App\Models\ProviderNetwork;
use App\Models\QuotaLimit;
use App\Models\SubnetPool;
use App\Models\Tenant;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes tenant memberships to Filament', function (): void {
    $tenantA = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    $tenantB = Tenant::query()->create(['name' => 'Tenant B', 'slug' => 'tenant-b']);
    $tenantC = Tenant::query()->create(['name' => 'Tenant C', 'slug' => 'tenant-c']);
    $user = User::factory()->create();
    $panel = Panel::make()->id('admin');

    provisionFilamentTenantMembership($user, $tenantA);
    provisionFilamentTenantMembership($user, $tenantB);

    $tenantIds = collect($user->getTenants($panel))
        ->map(static fn (Tenant $tenant): string => $tenant->getKey())
        ->all();

    expect($tenantIds)->toBe([$tenantA->getKey(), $tenantB->getKey()])
        ->and($user->getDefaultTenant($panel)?->is($tenantA))->toBeTrue()
        ->and($user->canAccessTenant($tenantA))->toBeTrue()
        ->and($user->canAccessTenant($tenantC))->toBeFalse();
});

it('configures the Filament admin panel for persistent RackLab tenant context', function (): void {
    $panel = (new AdminPanelProvider(app()))->panel(Panel::make());

    expect($panel->hasTenancy())->toBeTrue()
        ->and($panel->getTenantModel())->toBe(Tenant::class)
        ->and($panel->getTenantSlugAttribute())->toBe('slug')
        ->and($panel->getTenantMiddleware())->toContain(BindFilamentTenantContext::class);
});

it('registers minimal Filament resources for M1 admin models', function (): void {
    expect(UserResource::getModel())->toBe(User::class)
        ->and(CourseResource::getModel())->toBe(Course::class)
        ->and(ProjectResource::getModel())->toBe(Project::class)
        ->and(CourseResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(ProjectResource::getPages())->toHaveKeys(['index', 'create', 'edit']);
});

it('registers tenant-scoped Filament resources for M5a network administration', function (): void {
    expect(ProviderNetworkResource::getModel())->toBe(ProviderNetwork::class)
        ->and(NetworkOfferingResource::getModel())->toBe(NetworkOffering::class)
        ->and(SubnetPoolResource::getModel())->toBe(SubnetPool::class)
        ->and(FloatingIpPoolResource::getModel())->toBe(FloatingIpPool::class)
        ->and(ProviderNetworkResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(NetworkOfferingResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(SubnetPoolResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(FloatingIpPoolResource::getPages())->toHaveKeys(['index', 'create', 'edit']);
});

it('registers tenant-scoped Filament resources for M6 quota administration', function (): void {
    expect(QuotaLimitResource::getModel())->toBe(QuotaLimit::class)
        ->and(QuotaLimitResource::getPages())->toHaveKeys(['index', 'create', 'edit']);
});

it('registers the provider drift Filament admin surface', function (): void {
    expect(ProviderDriftResource::getModel())->toBe(ProviderDrift::class)
        ->and(ProviderDriftResource::getPages())->toHaveKeys(['index']);
});

function provisionFilamentTenantMembership(User $user, Tenant $tenant): void
{
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    app(PersonalProjectProvisioner::class)->ensureFor($user, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();
}
