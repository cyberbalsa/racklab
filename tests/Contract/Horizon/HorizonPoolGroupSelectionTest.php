<?php

declare(strict_types=1);

it('emits only app supervisors when RACKLAB_HORIZON_POOL_GROUP=app', function (): void {
    putenv('RACKLAB_HORIZON_POOL_GROUP=app');
    $config = require base_path('config/horizon.php');
    putenv('RACKLAB_HORIZON_POOL_GROUP');

    foreach (['production', 'local', 'testing'] as $env) {
        expect(array_keys($config['environments'][$env]))
            ->toEqualCanonicalizing(['racklab-provider', 'racklab-notifications'], 'env='.$env);
    }
});

it('emits only runner supervisors when RACKLAB_HORIZON_POOL_GROUP=runner', function (): void {
    putenv('RACKLAB_HORIZON_POOL_GROUP=runner');
    $config = require base_path('config/horizon.php');
    putenv('RACKLAB_HORIZON_POOL_GROUP');

    foreach (['production', 'local', 'testing'] as $env) {
        expect(array_keys($config['environments'][$env]))
            ->toEqualCanonicalizing(['racklab-scripts', 'racklab-console'], 'env='.$env);
    }
});

it('emits all four supervisors when RACKLAB_HORIZON_POOL_GROUP=all', function (): void {
    putenv('RACKLAB_HORIZON_POOL_GROUP=all');
    $config = require base_path('config/horizon.php');
    putenv('RACKLAB_HORIZON_POOL_GROUP');

    foreach (['production', 'local', 'testing'] as $env) {
        expect(array_keys($config['environments'][$env]))
            ->toEqualCanonicalizing(
                ['racklab-provider', 'racklab-scripts', 'racklab-console', 'racklab-notifications'],
                'env='.$env,
            );
    }
});

it('falls back to all supervisors when RACKLAB_HORIZON_POOL_GROUP is unset (safe default for dev)', function (): void {
    putenv('RACKLAB_HORIZON_POOL_GROUP');
    $config = require base_path('config/horizon.php');

    foreach (['production', 'local', 'testing'] as $env) {
        expect(array_keys($config['environments'][$env]))->toHaveCount(4, 'env='.$env);
    }
});
