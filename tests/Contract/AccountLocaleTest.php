<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows account session controls on the authenticated dashboard', function (): void {
    [, $user] = provisionAccountLocaleUser();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Log out')
        ->assertSee('Language');
});

it('persists the user locale preference and renders the dashboard with it', function (): void {
    [, $user] = provisionAccountLocaleUser();

    $this->actingAs($user)
        ->put('/account/locale', ['locale' => 'es'])
        ->assertRedirect('/dashboard');

    expect(UserProfile::query()->whereBelongsTo($user)->first()?->locale)->toBe('es');

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Panel')
        ->assertSee('Cerrar sesion');
});

/**
 * @return array{Tenant, User}
 */
function provisionAccountLocaleUser(): array
{
    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create(['name' => 'Dorothy Vaughan']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    app(PersonalProjectProvisioner::class)->ensureFor($user, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user];
}
