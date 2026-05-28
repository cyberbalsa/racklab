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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

it('issues and revokes Track B tokens from the dashboard', function (): void {
    [$tenant, $user, $project] = provisionTokenBrowserProject();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('API tokens')
        ->assertSee('Create token')
        ->assertSee('Project read');

    $response = $this->actingAs($user)
        ->post('/account/tokens', [
            'name' => 'Dashboard CLI',
            'project_id' => $project->getKey(),
            'abilities' => ['project.read'],
        ]);

    $response
        ->assertRedirect('/dashboard')
        ->assertSessionHas('issued_token_authorization_header');

    $header = session('issued_token_authorization_header');
    expect($header)->toBeString()->toStartWith('Token ');

    /** @var TokenGrant $grant */
    $grant = TokenGrant::query()->where('tenant_id', $tenant->getKey())->firstOrFail();
    $sanctumTokenId = $grant->sanctum_token_id;

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Dashboard CLI')
        ->assertSee($header, false)
        ->assertSee('project.read');

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertDontSee($header, false);

    $this->actingAs($user)
        ->post('/account/tokens/'.$grant->getKey().'/revoke')
        ->assertRedirect('/dashboard');

    $grant->refresh();

    expect($grant->revoked_at)->not->toBeNull()
        ->and($grant->sanctum_token_id)->toBeNull()
        ->and(PersonalAccessToken::query()->whereKey($sanctumTokenId)->exists())->toBeFalse();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Dashboard CLI')
        ->assertSee('Revoked');
});

/**
 * @return array{Tenant, User, Project}
 */
function provisionTokenBrowserProject(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create(['name' => 'Token Browser Admin', 'email' => 'token-browser@example.test']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}
