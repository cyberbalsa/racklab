<?php

declare(strict_types=1);

use App\Providers\Proxmox\Exceptions\ProviderAuthError;
use App\Providers\Proxmox\GuzzleProxmoxClient;
use App\Providers\Proxmox\Models\ProxmoxTaskStatus;
use App\Providers\Proxmox\Models\ProxmoxVersion;
use App\Providers\Proxmox\Models\ProxmoxVmCloneRequest;
use App\Providers\Proxmox\Models\ProxmoxVmDeleteRequest;
use App\Providers\Proxmox\Models\ProxmoxVmPowerRequest;
use App\Providers\Proxmox\ProxmoxEndpointConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

it('rejects disabled TLS verification outside local development', function (): void {
    config(['app.env' => 'production']);

    expect(fn (): ProxmoxEndpointConfig => ProxmoxEndpointConfig::fromArray([
        'base_uri' => 'https://pve.example.test:8006',
        'api_token_id' => 'racklab@pve!provider',
        'api_token_secret' => 'secret',
        'verify_ssl' => false,
    ]))->toThrow(InvalidArgumentException::class, 'verify_ssl=false is only allowed in local development.');
});

it('maps Proxmox task status and paginated task logs through Guzzle with API-token auth', function (): void {
    $upid = 'UPID:pve01:0009C3C2:067CF15D:6656B4E1:qmclone:100:root@pam:';
    $history = [];
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'data' => [
                'status' => 'stopped',
                'exitstatus' => 'OK',
            ],
        ], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode([
            'data' => [
                ['n' => 1, 't' => 'clone started'],
                ['n' => 2, 't' => 'clone complete'],
            ],
        ], JSON_THROW_ON_ERROR)),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    $client = new GuzzleProxmoxClient(
        ProxmoxEndpointConfig::fromArray([
            'base_uri' => 'https://pve.example.test:8006',
            'api_token_id' => 'racklab@pve!provider',
            'api_token_secret' => 'secret',
            'verify_ssl' => true,
        ]),
        new Client(['handler' => $stack]),
    );

    $status = $client->taskStatus('pve01', $upid);
    $log = $client->taskLog('pve01', $upid);

    expect($status->status)->toBe('stopped')
        ->and($status->exitStatus)->toBe('OK')
        ->and($log)->toBe(['clone started', 'clone complete'])
        ->and($history[0]['request']->getHeaderLine('Authorization'))
        ->toBe('PVEAPIToken=racklab@pve!provider=secret')
        ->and((string) $history[0]['request']->getUri())
        ->toContain('/api2/json/nodes/pve01/tasks/');
});

it('maps the Proxmox version endpoint into a typed version model', function (): void {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'data' => [
                'version' => '9.2.1',
                'release' => '9.2',
                'repoid' => 'pve-manager/9.2-1/abcdef',
            ],
        ], JSON_THROW_ON_ERROR)),
    ]);
    $client = new GuzzleProxmoxClient(
        ProxmoxEndpointConfig::fromArray([
            'base_uri' => 'https://pve.example.test:8006',
            'api_token_id' => 'racklab@pve!provider',
            'api_token_secret' => 'secret',
            'verify_ssl' => true,
        ]),
        new Client(['handler' => HandlerStack::create($mock)]),
    );

    $version = $client->version();

    expect($version)->toBeInstanceOf(ProxmoxVersion::class)
        ->and($version->major)->toBe(9)
        ->and($version->minor)->toBe(2)
        ->and($version->supportsAtLeast(9, 2))->toBeTrue();
});

it('submits a QEMU clone request and returns the Proxmox UPID', function (): void {
    $upid = 'UPID:pve01:0009C3C2:067CF15D:6656B4E1:qmclone:101:root@pam:';
    $history = [];
    $mock = new MockHandler([
        new Response(200, [], json_encode(['data' => $upid], JSON_THROW_ON_ERROR)),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    $client = new GuzzleProxmoxClient(
        ProxmoxEndpointConfig::fromArray([
            'base_uri' => 'https://pve.example.test:8006',
            'api_token_id' => 'racklab@pve!provider',
            'api_token_secret' => 'secret',
            'verify_ssl' => true,
        ]),
        new Client(['handler' => $stack]),
    );

    $result = $client->cloneVm(new ProxmoxVmCloneRequest(
        node: 'pve01',
        templateVmid: 9000,
        targetVmid: 101,
        name: 'racklab-101',
        fullClone: true,
        storage: 'local-lvm',
    ));

    expect($result)->toBe($upid)
        ->and($history[0]['request']->getMethod())->toBe('POST')
        ->and((string) $history[0]['request']->getUri())->toContain('/api2/json/nodes/pve01/qemu/9000/clone')
        ->and((string) $history[0]['request']->getBody())->toContain('newid=101')
        ->and((string) $history[0]['request']->getBody())->toContain('full=1');
});

it('submits a QEMU delete request and returns the Proxmox UPID', function (): void {
    $upid = 'UPID:pve01:0009C3C2:067CF15D:6656B4E1:qmdestroy:101:root@pam:';
    $history = [];
    $mock = new MockHandler([
        new Response(200, [], json_encode(['data' => $upid], JSON_THROW_ON_ERROR)),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    $client = new GuzzleProxmoxClient(
        ProxmoxEndpointConfig::fromArray([
            'base_uri' => 'https://pve.example.test:8006',
            'api_token_id' => 'racklab@pve!provider',
            'api_token_secret' => 'secret',
            'verify_ssl' => true,
        ]),
        new Client(['handler' => $stack]),
    );

    $result = $client->deleteVm(new ProxmoxVmDeleteRequest(node: 'pve01', vmid: 101, purge: true));

    expect($result)->toBe($upid)
        ->and($history[0]['request']->getMethod())->toBe('DELETE')
        ->and((string) $history[0]['request']->getUri())->toContain('/api2/json/nodes/pve01/qemu/101')
        ->and((string) $history[0]['request']->getUri())->toContain('purge=1');
});

it('submits a QEMU power request and returns the Proxmox UPID', function (): void {
    $upid = 'UPID:pve01:0009C3C2:067CF15D:6656B700:qmstop:101:root@pam:';
    $history = [];
    $mock = new MockHandler([
        new Response(200, [], json_encode(['data' => $upid], JSON_THROW_ON_ERROR)),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    $client = new GuzzleProxmoxClient(
        ProxmoxEndpointConfig::fromArray([
            'base_uri' => 'https://pve.example.test:8006',
            'api_token_id' => 'racklab@pve!provider',
            'api_token_secret' => 'secret',
            'verify_ssl' => true,
        ]),
        new Client(['handler' => $stack]),
    );

    $result = $client->powerVm(new ProxmoxVmPowerRequest(node: 'pve01', vmid: 101, action: 'stop'));

    expect($result)->toBe($upid)
        ->and($history[0]['request']->getMethod())->toBe('POST')
        ->and((string) $history[0]['request']->getUri())->toContain('/api2/json/nodes/pve01/qemu/101/status/stop');
});

it('maps Proxmox authentication failures to provider auth errors', function (): void {
    $mock = new MockHandler([
        new Response(401, [], json_encode(['errors' => ['auth' => 'permission denied']], JSON_THROW_ON_ERROR)),
    ]);

    $client = new GuzzleProxmoxClient(
        ProxmoxEndpointConfig::fromArray([
            'base_uri' => 'https://pve.example.test:8006',
            'api_token_id' => 'racklab@pve!provider',
            'api_token_secret' => 'secret',
            'verify_ssl' => true,
        ]),
        new Client(['handler' => HandlerStack::create($mock)]),
    );

    expect(fn (): ProxmoxTaskStatus => $client->taskStatus('pve01', 'UPID:pve01:0009C3C2:067CF15D:6656B4E1:qmclone:100:root@pam:'))
        ->toThrow(ProviderAuthError::class);
});
