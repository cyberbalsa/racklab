<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TokenGrant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

it('issues a tenant-local Track B personal access token and stores only the hashed bearer', function (): void {
    [$tenant, $user, $project] = provisionTrackBUserProject();

    $response = $this->actingAs($user)->postJson('/api/v1/tokens', [
        'name' => 'CLI token',
        'project_id' => $project->getKey(),
        'abilities' => ['project.read'],
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.name', 'CLI token')
        ->assertJsonPath('data.track', 'pat')
        ->assertJsonPath('data.tenant_id', $tenant->getKey())
        ->assertJsonPath('data.abilities.0', 'project.read')
        ->assertJsonStructure(['data' => ['id', 'plain_text_token', 'authorization_header']]);

    $plainTextToken = $response->json('data.plain_text_token');
    expect($plainTextToken)->toBeString()->toContain('|');

    $grant = TokenGrant::query()->firstOrFail();

    expect($grant->tenant_id)->toBe($tenant->getKey())
        ->and($grant->owner_user_id)->toBe($user->getKey())
        ->and($grant->resource_type)->toBe('project')
        ->and($grant->resource_id)->toBe($project->resourceId())
        ->and($grant->abilities)->toBe(['project.read'])
        ->and(PersonalAccessToken::query()->where('token', $plainTextToken)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('event_type', 'token.grant')->where('action', 'create')->where('result', 'allowed')->exists())->toBeTrue();
});

it('accepts Track B tokens with the Token prefix and rejects the wrong Bearer prefix', function (): void {
    [$tenant, $user, $project] = provisionTrackBUserProject();
    $plainTextToken = issueTrackBToken($this, $user, $project, ['project.read']);

    $this->withHeader('Authorization', 'Token '.$plainTextToken)
        ->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonPath('data.0.id', $project->getKey())
        ->assertJsonPath('data.0.tenant_id', $tenant->getKey());

    $this->withHeader('Authorization', 'Bearer '.$plainTextToken)
        ->getJson('/api/v1/projects')
        ->assertUnauthorized();
});

it('enforces Track B token abilities on protected API operations', function (): void {
    [, $user, $project] = provisionTrackBUserProject();
    $plainTextToken = issueTrackBToken($this, $user, $project, ['token.create']);

    $this->withHeader('Authorization', 'Token '.$plainTextToken)
        ->getJson('/api/v1/projects')
        ->assertForbidden();
});

it('revokes Track B grants by deleting the Sanctum token hash and retaining audit history', function (): void {
    [, $user, $project] = provisionTrackBUserProject();
    $plainTextToken = issueTrackBToken($this, $user, $project, ['project.read']);
    $grant = TokenGrant::query()->firstOrFail();
    $sanctumTokenId = $grant->sanctum_token_id;

    $this->actingAs($user)
        ->deleteJson('/api/v1/tokens/'.$grant->getKey())
        ->assertNoContent();

    $grant->refresh();

    expect($grant->revoked_at)->not->toBeNull()
        ->and($grant->sanctum_token_id)->toBeNull()
        ->and(PersonalAccessToken::query()->whereKey($sanctumTokenId)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('event_type', 'token.grant')->where('action', 'revoke')->where('result', 'allowed')->exists())->toBeTrue();

    $this->withHeader('Authorization', 'Token '.$plainTextToken)
        ->getJson('/api/v1/projects')
        ->assertUnauthorized();
});

/**
 * @return array{Tenant, User, Project}
 */
function provisionTrackBUserProject(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.test']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}

/**
 * @param  list<string>  $abilities
 */
function issueTrackBToken(Illuminate\Foundation\Testing\TestCase $test, User $user, Project $project, array $abilities): string
{
    $response = $test->actingAs($user)->postJson('/api/v1/tokens', [
        'name' => 'API token',
        'project_id' => $project->getKey(),
        'abilities' => $abilities,
    ]);

    $response->assertCreated();

    $plainTextToken = $response->json('data.plain_text_token');
    expect($plainTextToken)->toBeString();

    return $plainTextToken;
}
