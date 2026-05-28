<?php

declare(strict_types=1);

use App\Auth\Jwt\ConsoleAccessGrantIssuer;
use App\Console\Proxy\InMemoryProviderConsoleProxy;
use App\Console\Proxy\ProviderConsoleProxy;
use App\Console\Proxy\ProviderConsoleProxyException;
use App\Domain\Console\ConsoleAccessGrant;
use App\Domain\Console\ConsoleKind;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('binds in-memory proxy when RACKLAB_CONSOLE_PROXY=in-memory', function (): void {
    config()->set('racklab.console.proxy', 'in-memory');
    $this->app->forgetInstance(ProviderConsoleProxy::class);

    expect(app(ProviderConsoleProxy::class))->toBeInstanceOf(InMemoryProviderConsoleProxy::class);
});

it('binds an unavailable proxy by default for production safety', function (): void {
    config()->set('racklab.console.proxy', 'unavailable');
    $this->app->forgetInstance(ProviderConsoleProxy::class);

    $proxy = app(ProviderConsoleProxy::class);

    [, , $deployment, $grant] = provisionProxyFixture();

    expect(fn () => $proxy->requestVncTicket($grant, $deployment))
        ->toThrow(ProviderConsoleProxyException::class);
});

it('issues a deterministic VNC ticket and audits console.proxy.request for a valid grant', function (): void {
    [, , $deployment, $grant] = provisionProxyFixture();

    /** @var InMemoryProviderConsoleProxy $proxy */
    $proxy = app(InMemoryProviderConsoleProxy::class);

    $ticket = $proxy->requestVncTicket($grant, $deployment);

    expect($ticket->ticket)->toStartWith('in-memory-ticket-')
        ->and($ticket->consoleKind)->toBe(ConsoleKind::Vnc)
        ->and($ticket->expiresAt->getTimestamp())->toBe($grant->expiresAt->getTimestamp())
        ->and($ticket->metadata['deployment_id'] ?? null)->toBe($deployment->getKey());

    expect(AuditEvent::query()
        ->where('event_type', 'console.proxy.request')
        ->where('result', 'allowed')
        ->where('resource_id', $deployment->getKey())
        ->whereJsonContains('metadata->console_kind', 'vnc')
        ->exists()
    )->toBeTrue();
});

it('issues a deterministic terminal proxy ticket for a terminal-kind grant', function (): void {
    [, , $deployment, $grant] = provisionProxyFixture(ConsoleKind::Terminal);

    /** @var InMemoryProviderConsoleProxy $proxy */
    $proxy = app(InMemoryProviderConsoleProxy::class);

    $ticket = $proxy->requestTerminalProxy($grant, $deployment);

    expect($ticket->consoleKind)->toBe(ConsoleKind::Terminal);

    expect(AuditEvent::query()
        ->where('event_type', 'console.proxy.request')
        ->where('result', 'allowed')
        ->whereJsonContains('metadata->console_kind', 'terminal')
        ->exists()
    )->toBeTrue();
});

it('refuses to issue a VNC ticket for a terminal-kind grant and audits the mismatch', function (): void {
    [, , $deployment, $grant] = provisionProxyFixture(ConsoleKind::Terminal);

    /** @var InMemoryProviderConsoleProxy $proxy */
    $proxy = app(InMemoryProviderConsoleProxy::class);

    expect(fn () => $proxy->requestVncTicket($grant, $deployment))
        ->toThrow(ProviderConsoleProxyException::class, 'kind');

    expect(AuditEvent::query()
        ->where('event_type', 'console.proxy.request')
        ->where('result', 'denied')
        ->whereJsonContains('metadata->reason', 'console_kind_mismatch')
        ->exists()
    )->toBeTrue();
});

it('rejects expired grants with grant_expired audit reason', function (): void {
    [$tenant, , $deployment, $liveGrant] = provisionProxyFixture();

    // Re-shape the grant with an expiry in the past — simulates a delayed proxy hit.
    $expired = new ConsoleAccessGrant(
        grantId: $liveGrant->grantId,
        jti: $liveGrant->jti,
        tenantId: $tenant->getKey(),
        deploymentId: $deployment->getKey(),
        consoleKind: ConsoleKind::Vnc,
        expiresAt: CarbonImmutable::now()->subSeconds(1),
    );

    /** @var InMemoryProviderConsoleProxy $proxy */
    $proxy = app(InMemoryProviderConsoleProxy::class);

    expect(fn () => $proxy->requestVncTicket($expired, $deployment))
        ->toThrow(ProviderConsoleProxyException::class);

    expect(AuditEvent::query()
        ->where('event_type', 'console.proxy.request')
        ->where('result', 'denied')
        ->whereJsonContains('metadata->reason', 'grant_expired')
        ->exists()
    )->toBeTrue();
});

it('refuses to issue a ticket for a deployment the grant does not name', function (): void {
    [, , $deployment, $grant] = provisionProxyFixture();
    [, , $otherDeployment] = provisionProxyFixture(emailSuffix: 'mismatch');

    /** @var InMemoryProviderConsoleProxy $proxy */
    $proxy = app(InMemoryProviderConsoleProxy::class);

    expect(fn () => $proxy->requestVncTicket($grant, $otherDeployment))
        ->toThrow(ProviderConsoleProxyException::class, 'deployment');

    expect(AuditEvent::query()
        ->where('event_type', 'console.proxy.request')
        ->where('result', 'denied')
        ->whereJsonContains('metadata->reason', 'deployment_mismatch')
        ->exists()
    )->toBeTrue();

    unset($deployment);
});

/**
 * @return array{Tenant, User, Deployment, ConsoleAccessGrant}
 */
function provisionProxyFixture(?ConsoleKind $consoleKind = null, string $emailSuffix = ''): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->firstOrCreate(
        ['slug' => 'default'],
        ['name' => 'Default Tenant'],
    );
    $email = 'proxy-fixture'.($emailSuffix !== '' ? '-'.$emailSuffix : '').'@example.test';
    $user = User::factory()->create([
        'name' => 'Proxy Fixture User '.$emailSuffix,
        'email' => $email,
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
        'idempotency_key' => 'proxy-fixture-'.($emailSuffix !== '' ? $emailSuffix : 'main'),
    ])->assertCreated()->json('data.id');

    /** @var Deployment $deployment */
    $deployment = Deployment::query()->whereKey($deploymentId)->firstOrFail();

    $issue = app(ConsoleAccessGrantIssuer::class)->issue(
        issuer: $user,
        context: $context,
        deployment: $deployment,
        consoleKind: $consoleKind ?? ConsoleKind::Vnc,
    );

    auth()->forgetGuards();

    return [$tenant, $user, $deployment, $issue->grant];
}
