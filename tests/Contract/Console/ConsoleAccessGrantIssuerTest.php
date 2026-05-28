<?php

declare(strict_types=1);

use App\Auth\Jwt\ConsoleAccessGrantIssuer;
use App\Domain\Console\ConsoleKind;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Tenant;
use App\Models\TokenGrant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('issues a Track A JWT for an authorized user with console_kind + deployment_id claims', function (): void {
    [$tenant, $user, $deployment] = provisionConsoleGrantFixture();

    $issue = app(ConsoleAccessGrantIssuer::class)->issue(
        issuer: $user,
        context: new TenantContext(activeTenantId: $tenant->getKey()),
        deployment: $deployment,
        consoleKind: ConsoleKind::Vnc,
    );

    $grant = TokenGrant::query()->whereKey($issue->grant->grantId)->firstOrFail();

    expect($issue->grant->deploymentId)->toBe($deployment->getKey())
        ->and($issue->grant->tenantId)->toBe($tenant->getKey())
        ->and($issue->grant->consoleKind)->toBe(ConsoleKind::Vnc)
        ->and($issue->grant->isExpired())->toBeFalse()
        ->and($grant->track)->toBe('jwt')
        ->and($grant->resource_type)->toBe('deployment')
        ->and($grant->resource_id)->toBe($deployment->getKey())
        ->and($grant->abilities)->toBe([ConsoleAccessGrantIssuer::CONNECT_PERMISSION])
        ->and($grant->jti)->toBe($issue->grant->jti);

    [$header, $payload] = array_map(
        static fn (string $segment): array => (array) json_decode(
            (string) base64_decode(strtr($segment, '-_', '+/'), strict: true),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        ),
        array_slice(explode('.', $issue->jwt), 0, 2),
    );

    expect($header['kid'] ?? null)->toBe($issue->kid)
        ->and($payload['console_kind'] ?? null)->toBe('vnc')
        ->and($payload['deployment_id'] ?? null)->toBe($deployment->getKey())
        ->and($payload['token_type'] ?? null)->toBe(ConsoleAccessGrantIssuer::TOKEN_TYPE)
        ->and($payload['permissions'] ?? null)->toBe([ConsoleAccessGrantIssuer::CONNECT_PERMISSION]);
});

it('respects the configured console grant TTL', function (): void {
    config()->set('racklab.console.grant_ttl_seconds', 60);

    [$tenant, $user, $deployment] = provisionConsoleGrantFixture();

    $before = time();
    $issue = app(ConsoleAccessGrantIssuer::class)->issue(
        issuer: $user,
        context: new TenantContext(activeTenantId: $tenant->getKey()),
        deployment: $deployment,
        consoleKind: ConsoleKind::Terminal,
    );

    $skew = $issue->grant->expiresAt->getTimestamp() - $before;
    expect($skew)->toBeGreaterThanOrEqual(55)->toBeLessThanOrEqual(65);
});

it('denies issuance and emits console.access.denied when actor lacks deployment.console.connect', function (): void {
    [$tenant, $owner, $deployment] = provisionConsoleGrantFixture();

    // Provision an unrelated user in the same tenant — no role binding to the deployment.
    $outsider = User::factory()->create([
        'name' => 'Unrelated User',
        'email' => 'outsider@example.test',
    ]);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    app(PersonalProjectProvisioner::class)->ensureFor($outsider, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    expect(fn () => app(ConsoleAccessGrantIssuer::class)->issue(
        issuer: $outsider,
        context: $context,
        deployment: $deployment,
        consoleKind: ConsoleKind::Vnc,
    ))->toThrow(AuthorizationException::class);

    expect(AuditEvent::query()
        ->where('event_type', 'console.access.denied')
        ->where('result', 'denied')
        ->where('actor_id', (string) $outsider->getKey())
        ->where('resource_id', $deployment->getKey())
        ->exists()
    )->toBeTrue();

    expect(TokenGrant::query()->count())->toBe(0);

    // Guard against the owner's path silently creating audit noise for outsider denials.
    unset($owner);
});

/**
 * @return array{Tenant, User, Deployment}
 */
function provisionConsoleGrantFixture(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->firstOrCreate(
        ['slug' => 'default'],
        ['name' => 'Default Tenant'],
    );
    $user = User::factory()->create([
        'name' => 'Console User',
        'email' => 'console@example.test',
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
        'idempotency_key' => 'console-fixture',
    ])->assertCreated()->json('data.id');

    /** @var Deployment $deployment */
    $deployment = Deployment::query()->whereKey($deploymentId)->firstOrFail();

    return [$tenant, $user, $deployment];
}
