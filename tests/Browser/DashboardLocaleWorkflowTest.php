<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;

uses(DatabaseMigrations::class);

it('persists the dashboard locale switch in a real browser', function (): void {
    $user = provisionDashboardLocaleBrowserUser();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser
            ->loginAs($user)
            ->visit('/dashboard')
            ->waitForText('Dashboard')
            ->assertSee('Language')
            ->select('@account-locale', 'es')
            ->click('@save-locale')
            ->waitForText('Panel')
            ->assertSee('Cerrar sesion')
            ->assertSelected('@account-locale', 'es');
    });

    expect(UserProfile::query()->whereBelongsTo($user)->first()?->locale)->toBe('es');
});

function provisionDashboardLocaleBrowserUser(): User
{
    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create(['name' => 'Dashboard Locale Admin', 'email' => 'dashboard-locale@example.test']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    app(PersonalProjectProvisioner::class)->ensureFor($user, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return $user;
}
