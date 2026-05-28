<?php

declare(strict_types=1);

use App\Auth\Jwt\ConsoleAccessGrantIssuer;
use App\Auth\Jwt\ConsoleAccessGrantVerifier;
use App\Auth\Jwt\TrackAIssuer;
use App\Auth\Jwt\TrackAJwtRevoker;
use App\Domain\Console\ConsoleKind;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\Deployment;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('round-trips a console JWT into a ConsoleAccessGrant with the console_kind claim', function (): void {
    [$tenant, $user, $deployment] = provisionVerifierFixture();

    $issue = app(ConsoleAccessGrantIssuer::class)->issue(
        issuer: $user,
        context: new TenantContext(activeTenantId: $tenant->getKey()),
        deployment: $deployment,
        consoleKind: ConsoleKind::Terminal,
    );

    $grant = app(ConsoleAccessGrantVerifier::class)->verify($issue->jwt);

    expect($grant->deploymentId)->toBe($deployment->getKey())
        ->and($grant->tenantId)->toBe($tenant->getKey())
        ->and($grant->consoleKind)->toBe(ConsoleKind::Terminal)
        ->and($grant->jti)->toBe($issue->grant->jti)
        ->and($grant->isExpired())->toBeFalse();
});

it('rejects non-console Track A JWTs', function (): void {
    [$tenant, $user, $deployment] = provisionVerifierFixture();

    /** @var App\Models\Project $project */
    $project = App\Models\Project::query()->where('created_for_user_id', $user->getKey())->firstOrFail();

    $apiToken = app(TrackAIssuer::class)->issue(
        issuer: $user,
        context: new TenantContext(activeTenantId: $tenant->getKey()),
        resource: $project,
        permissions: ['project.read'],
        tokenType: 'api',
    );

    expect(fn () => app(ConsoleAccessGrantVerifier::class)->verify($apiToken->jwt))
        ->toThrow(AuthenticationException::class);

    unset($deployment);
});

it('rejects revoked console grants by jti', function (): void {
    [$tenant, $user, $deployment] = provisionVerifierFixture();

    $issue = app(ConsoleAccessGrantIssuer::class)->issue(
        issuer: $user,
        context: new TenantContext(activeTenantId: $tenant->getKey()),
        deployment: $deployment,
        consoleKind: ConsoleKind::Vnc,
    );

    app(TrackAJwtRevoker::class)->revoke(
        jti: $issue->grant->jti,
        tenantId: $tenant->getKey(),
        revokedBy: $user,
        reason: 'test-revoke',
    );

    expect(fn () => app(ConsoleAccessGrantVerifier::class)->verify($issue->jwt))
        ->toThrow(AuthenticationException::class);
});

it('rejects forged JWTs with a non-matching deployment_id claim', function (): void {
    [$tenant, $user, $deployment] = provisionVerifierFixture();

    $issue = app(ConsoleAccessGrantIssuer::class)->issue(
        issuer: $user,
        context: new TenantContext(activeTenantId: $tenant->getKey()),
        deployment: $deployment,
        consoleKind: ConsoleKind::Vnc,
    );

    // Mutate the JWT payload's deployment_id; since the signature won't match, the verifier
    // should still reject because the signature check fails before the resource cross-check.
    [$header, $payload, $signature] = explode('.', $issue->jwt);
    $decoded = (array) json_decode((string) base64_decode(strtr($payload, '-_', '+/'), strict: true), associative: true, flags: JSON_THROW_ON_ERROR);
    $decoded['deployment_id'] = 'forged-id';
    $tampered = $header.'.'.rtrim(strtr(base64_encode(json_encode($decoded, JSON_THROW_ON_ERROR)), '+/', '-_'), '=').'.'.$signature;

    expect(fn () => app(ConsoleAccessGrantVerifier::class)->verify($tampered))
        ->toThrow(AuthenticationException::class);
});

/**
 * @return array{Tenant, User, Deployment}
 */
function provisionVerifierFixture(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->firstOrCreate(
        ['slug' => 'default'],
        ['name' => 'Default Tenant'],
    );
    $user = User::factory()->create([
        'name' => 'Verifier User',
        'email' => 'verifier@example.test',
    ]);
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    Sanctum::actingAs($user);
    $deploymentId = test()->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'verifier-fixture',
    ])->assertCreated()->json('data.id');

    /** @var Deployment $deployment */
    $deployment = Deployment::query()->whereKey($deploymentId)->firstOrFail();

    return [$tenant, $user, $deployment];
}
