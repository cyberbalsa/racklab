<?php

declare(strict_types=1);

use App\Auth\Jwt\TrackAIssuer;
use App\Auth\Jwt\TrackAJwtRevoker;
use App\Auth\Jwt\TrackAJwtVerifier;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TokenGrant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('issues a short-lived Track A JWT with RackLab claims and a retained grant row', function (): void {
    [$tenant, $user, $project] = provisionTrackAUserProject();

    $issue = app(TrackAIssuer::class)->issue(
        issuer: $user,
        context: new TenantContext(activeTenantId: $tenant->getKey()),
        resource: $project,
        permissions: ['project.read'],
        tokenType: 'console',
    );

    $claims = app(TrackAJwtVerifier::class)->verify($issue->jwt);
    $grant = TokenGrant::query()->whereKey($issue->grant->getKey())->firstOrFail();

    expect($claims->subjectUserId)->toBe((string) $user->getKey())
        ->and($claims->tenantId)->toBe($tenant->getKey())
        ->and($claims->grantId)->toBe($grant->getKey())
        ->and($claims->jti)->toBe($issue->jti)
        ->and($claims->permissions)->toBe(['project.read'])
        ->and($claims->tokenType)->toBe('console')
        ->and($grant->track)->toBe('jwt')
        ->and($grant->jti)->toBe($issue->jti)
        ->and($grant->abilities)->toBe(['project.read']);
});

it('publishes the active signing key through JWKS so sidecars can verify Track A tokens', function (): void {
    [$tenant, $user, $project] = provisionTrackAUserProject();
    $issue = app(TrackAIssuer::class)->issue(
        issuer: $user,
        context: new TenantContext(activeTenantId: $tenant->getKey()),
        resource: $project,
        permissions: ['project.read'],
        tokenType: 'deployment',
    );

    $response = $this->getJson('/.well-known/jwks.json')
        ->assertOk()
        ->assertJsonPath('keys.0.kid', $issue->kid)
        ->assertJsonPath('keys.0.kty', 'RSA')
        ->assertJsonPath('keys.0.alg', 'RS256');

    $keys = JWK::parseKeySet($response->json());
    $decoded = JWT::decode($issue->jwt, $keys);

    expect($decoded->jti)->toBe($issue->jti)
        ->and($decoded->grant_id)->toBe($issue->grant->getKey());
});

it('authenticates Bearer Track A JWTs for API requests and enforces delegated permissions', function (): void {
    [$tenant, $user, $project] = provisionTrackAUserProject();
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    $reader = app(TrackAIssuer::class)->issue($user, $context, $project, ['project.read'], 'api');
    $creatorOnly = app(TrackAIssuer::class)->issue($user, $context, $project, ['token.create'], 'api');

    $this->withHeader('Authorization', 'Bearer '.$reader->jwt)
        ->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonPath('data.0.id', $project->getKey());

    $this->withHeader('Authorization', 'Bearer '.$creatorOnly->jwt)
        ->getJson('/api/v1/projects')
        ->assertForbidden();
});

it('rejects revoked Track A JWTs by jti within one request cycle', function (): void {
    [$tenant, $user, $project] = provisionTrackAUserProject();
    $issue = app(TrackAIssuer::class)->issue(
        issuer: $user,
        context: new TenantContext(activeTenantId: $tenant->getKey()),
        resource: $project,
        permissions: ['project.read'],
        tokenType: 'api',
    );

    app(TrackAJwtRevoker::class)->revoke(
        jti: $issue->jti,
        tenantId: $tenant->getKey(),
        revokedBy: $user,
        reason: 'test',
    );

    expect(fn () => app(TrackAJwtVerifier::class)->verify($issue->jwt))
        ->toThrow(AuthenticationException::class);

    $this->withHeader('Authorization', 'Bearer '.$issue->jwt)
        ->getJson('/api/v1/projects')
        ->assertUnauthorized();
});

/**
 * @return array{Tenant, User, Project}
 */
function provisionTrackAUserProject(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create(['name' => 'Katherine Johnson', 'email' => 'katherine@example.test']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}
