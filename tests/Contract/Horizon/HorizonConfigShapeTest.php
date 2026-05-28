<?php

declare(strict_types=1);

it('declares four RackLab supervisors with queues matching actual job dispatches', function (): void {
    putenv('RACKLAB_HORIZON_POOL_GROUP=all');
    $config = require base_path('config/horizon.php');
    putenv('RACKLAB_HORIZON_POOL_GROUP');

    $defaults = $config['defaults'];

    expect($defaults['racklab-provider']['queue'])->toBe(['provider-worker', 'provider', 'default']);
    expect($defaults['racklab-scripts']['queue'])->toBe(['script-worker', 'scripts', 'cleanup']);
    expect($defaults['racklab-console']['queue'])->toBe(['console-worker', 'console']);
    expect($defaults['racklab-notifications']['queue'])->toBe(['notification-worker', 'notifications', 'default']);

    expect($defaults['racklab-provider']['timeout'])->toBe(300);
    expect($defaults['racklab-scripts']['timeout'])->toBe(900);
    expect($defaults['racklab-console']['timeout'])->toBe(3600);
    expect($defaults['racklab-notifications']['timeout'])->toBe(120);

    expect($defaults['racklab-provider']['tries'])->toBe(1);
    expect($defaults['racklab-notifications']['tries'])->toBe(3);

    expect($defaults['racklab-provider']['connection'])->toBe('redis');
});

it('declares production, local, and testing horizon environments', function (): void {
    putenv('RACKLAB_HORIZON_POOL_GROUP=all');
    $config = require base_path('config/horizon.php');
    putenv('RACKLAB_HORIZON_POOL_GROUP');

    foreach (['production', 'local', 'testing'] as $env) {
        expect(isset($config['environments'][$env]))->toBeTrue('missing env: '.$env);
    }
});

it('keeps testing env deterministic (simple balance, processes=1)', function (): void {
    putenv('RACKLAB_HORIZON_POOL_GROUP=all');
    $config = require base_path('config/horizon.php');
    putenv('RACKLAB_HORIZON_POOL_GROUP');

    foreach ($config['environments']['testing'] as $supervisor) {
        expect($supervisor['balance'])->toBe('simple');
        expect($supervisor['processes'])->toBe(1);
    }
});

it('uses BindAuthenticatedTenant in horizon middleware so route-less /horizon still resolves a tenant', function (): void {
    putenv('RACKLAB_HORIZON_POOL_GROUP=all');
    $config = require base_path('config/horizon.php');
    putenv('RACKLAB_HORIZON_POOL_GROUP');

    expect($config['middleware'])->toContain('web');
    expect($config['middleware'])->toContain(App\Http\Middleware\BindAuthenticatedTenant::class);

    // No `auth` middleware — anonymous requests must reach HorizonAuthGate so denial audit is emitted.
    expect($config['middleware'])->not->toContain('auth');
});
