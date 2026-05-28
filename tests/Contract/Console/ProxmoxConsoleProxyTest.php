<?php

declare(strict_types=1);

use App\Auth\Jwt\ConsoleAccessGrantIssuer;
use App\Console\Proxy\ProviderConsoleProxyException;
use App\Domain\Console\ConsoleKind;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\DeploymentResource;
use App\Models\Tenant;
use App\Models\User;
use App\Providers\Proxmox\Contracts\ProxmoxClientContract;
use App\Providers\Proxmox\Models\ProxmoxTermProxyTicket;
use App\Providers\Proxmox\Models\ProxmoxVncTicket;
use App\Providers\Proxmox\ProxmoxConsoleProxy;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('forwards an authorized VNC grant to Proxmox vncproxy and returns a ProviderConsoleTicket', function (): void {
    [, , $deployment, $grant] = provisionProxmoxConsoleFixture();

    $vncTicket = new ProxmoxVncTicket(
        ticket: 'PVE:racklab@pve!provider:ABCDEF:vncticket',
        cert: '-----BEGIN CERTIFICATE-----\nMIIDX...',
        port: 5901,
        upid: 'UPID:pve01:0009C3C2:067CF15D:6656B700:vncproxy:101:racklab@pve!provider:',
        user: 'racklab@pve!provider',
    );

    $fakeClient = mock(ProxmoxClientContract::class);
    $fakeClient->shouldReceive('vncProxy')
        ->once()
        ->withArgs(fn (object $request): bool => $request->node === 'pve01'
            && $request->vmid === (int) $deployment->resources()->first()->provider_resource_id
            && $request->websocket === true)
        ->andReturn($vncTicket);

    $proxy = new ProxmoxConsoleProxy($fakeClient, app(App\Audit\AuditEventWriter::class));

    $ticket = $proxy->requestVncTicket($grant, $deployment);

    expect($ticket->ticket)->toBe('PVE:racklab@pve!provider:ABCDEF:vncticket')
        ->and($ticket->consoleKind)->toBe(ConsoleKind::Vnc)
        ->and($ticket->websocketUrl)->toContain('/api2/json/nodes/pve01/qemu/')
        ->and($ticket->websocketUrl)->toContain('vncwebsocket?port=5901')
        ->and($ticket->websocketUrl)->toContain('vncticket='.rawurlencode('PVE:racklab@pve!provider:ABCDEF:vncticket'))
        ->and($ticket->metadata['provider'] ?? null)->toBe('proxmox')
        ->and($ticket->metadata['port'] ?? null)->toBe(5901);

    expect(AuditEvent::query()
        ->where('event_type', 'console.proxy.request')
        ->where('result', 'allowed')
        ->whereJsonContains('metadata->provider', 'proxmox')
        ->whereJsonContains('metadata->console_kind', 'vnc')
        ->exists()
    )->toBeTrue();
});

it('forwards an authorized terminal grant to Proxmox termproxy', function (): void {
    [, , $deployment, $grant] = provisionProxmoxConsoleFixture(ConsoleKind::Terminal);

    $termTicket = new ProxmoxTermProxyTicket(
        ticket: 'PVE:racklab@pve!provider:ABCDEF:termticket',
        port: 5902,
        upid: 'UPID:pve01:0009C3C2:067CF15D:6656B700:vncshell:101:racklab@pve!provider:',
        user: 'racklab@pve!provider',
    );

    $fakeClient = mock(ProxmoxClientContract::class);
    $fakeClient->shouldReceive('termProxy')->once()->andReturn($termTicket);

    $proxy = new ProxmoxConsoleProxy($fakeClient, app(App\Audit\AuditEventWriter::class));

    $ticket = $proxy->requestTerminalProxy($grant, $deployment);

    expect($ticket->consoleKind)->toBe(ConsoleKind::Terminal)
        ->and($ticket->ticket)->toBe('PVE:racklab@pve!provider:ABCDEF:termticket');

    expect(AuditEvent::query()
        ->where('event_type', 'console.proxy.request')
        ->where('result', 'allowed')
        ->whereJsonContains('metadata->console_kind', 'terminal')
        ->exists()
    )->toBeTrue();
});

it('wraps Proxmox client exceptions as provider_error audit + denial', function (): void {
    [, , $deployment, $grant] = provisionProxmoxConsoleFixture();

    $fakeClient = mock(ProxmoxClientContract::class);
    $fakeClient->shouldReceive('vncProxy')
        ->once()
        ->andThrow(new RuntimeException('boom'));

    $proxy = new ProxmoxConsoleProxy($fakeClient, app(App\Audit\AuditEventWriter::class));

    expect(fn (): App\Console\Proxy\ProviderConsoleTicket => $proxy->requestVncTicket($grant, $deployment))
        ->toThrow(ProviderConsoleProxyException::class);

    expect(AuditEvent::query()
        ->where('event_type', 'console.proxy.request')
        ->where('result', 'denied')
        ->whereJsonContains('metadata->reason', 'provider_error')
        ->exists()
    )->toBeTrue();
});

it('rejects non-proxmox deployments with not_a_proxmox_deployment audit', function (): void {
    [, , $deployment, $grant] = provisionProxmoxConsoleFixture();

    // Convert all the resources to fake provider so the proxy cannot find a Proxmox VM.
    DeploymentResource::query()->where('deployment_id', $deployment->getKey())->update(['provider' => 'fake']);

    $fakeClient = mock(ProxmoxClientContract::class);
    $fakeClient->shouldNotReceive('vncProxy');
    $fakeClient->shouldNotReceive('termProxy');

    $proxy = new ProxmoxConsoleProxy($fakeClient, app(App\Audit\AuditEventWriter::class));

    expect(fn (): App\Console\Proxy\ProviderConsoleTicket => $proxy->requestVncTicket($grant, $deployment))
        ->toThrow(ProviderConsoleProxyException::class, 'no active Proxmox');

    expect(AuditEvent::query()
        ->where('event_type', 'console.proxy.request')
        ->where('result', 'denied')
        ->whereJsonContains('metadata->reason', 'not_a_proxmox_deployment')
        ->exists()
    )->toBeTrue();
});

/**
 * @return array{Tenant, User, Deployment, App\Domain\Console\ConsoleAccessGrant}
 */
function provisionProxmoxConsoleFixture(?ConsoleKind $consoleKind = null): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->firstOrCreate(
        ['slug' => 'default'],
        ['name' => 'Default Tenant'],
    );
    $user = User::factory()->create([
        'name' => 'Proxmox Console User',
        'email' => 'proxmox-console@example.test',
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
        'idempotency_key' => 'proxmox-console-fixture',
    ])->assertCreated()->json('data.id');

    /** @var Deployment $deployment */
    $deployment = Deployment::query()->whereKey($deploymentId)->firstOrFail();

    // Convert the fake-provider deployment into a Proxmox-shaped one for the proxy test.
    DeploymentResource::query()
        ->where('deployment_id', $deployment->getKey())
        ->update([
            'provider' => 'proxmox',
            'kind' => 'vm',
            'provider_resource_id' => '101',
            'metadata' => json_encode(['proxmox' => ['node' => 'pve01']], JSON_THROW_ON_ERROR),
        ]);

    $issue = app(ConsoleAccessGrantIssuer::class)->issue(
        issuer: $user,
        context: $context,
        deployment: $deployment,
        consoleKind: $consoleKind ?? ConsoleKind::Vnc,
    );

    auth()->forgetGuards();

    return [$tenant, $user, $deployment, $issue->grant];
}
