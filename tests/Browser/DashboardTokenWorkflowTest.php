<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TokenGrant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Laravel\Sanctum\PersonalAccessToken;

uses(DatabaseMigrations::class);

it('issues and revokes a Track B token from the dashboard in a real browser', function (): void {
    [$tenant, $user, $project] = provisionDashboardTokenBrowserProject();

    $issuedHeader = null;

    $this->browse(function (Browser $browser) use ($user, $project, &$issuedHeader): void {
        $browser
            ->loginAs($user)
            ->visit('/dashboard')
            ->waitForText('API tokens')
            ->assertSee('Create token')
            ->assertSee('Project read')
            ->type('@api-token-name', 'Browser CLI')
            ->select('@api-token-project', (string) $project->getKey())
            ->click('@create-api-token')
            ->waitForText('Browser CLI token issued')
            ->assertVisible('@issued-token-header');

        $issuedHeader = $browser->text('@issued-token-header');

        expect($issuedHeader)->toStartWith('Token ');

        $browser
            ->assertSee('Browser CLI')
            ->assertSee('project.read')
            ->refresh()
            ->waitForText('Browser CLI')
            ->assertDontSee($issuedHeader);
    });

    /** @var TokenGrant $grant */
    $grant = TokenGrant::query()->where('tenant_id', $tenant->getKey())->firstOrFail();
    $sanctumTokenId = $grant->sanctum_token_id;

    expect($grant->name)->toBe('Browser CLI')
        ->and($sanctumTokenId)->not->toBeNull();

    $this->browse(function (Browser $browser): void {
        $browser
            ->visit('/dashboard')
            ->waitForText('Browser CLI')
            ->click('@revoke-api-token')
            ->waitForText('Token revoked.')
            ->assertSee('Revoked');
    });

    $grant->refresh();

    expect($grant->revoked_at)->not->toBeNull()
        ->and($grant->sanctum_token_id)->toBeNull()
        ->and(PersonalAccessToken::query()->whereKey($sanctumTokenId)->exists())->toBeFalse();
});

/**
 * @return array{Tenant, User, Project}
 */
function provisionDashboardTokenBrowserProject(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create(['name' => 'Dashboard Browser Admin', 'email' => 'dashboard-browser@example.test']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}
