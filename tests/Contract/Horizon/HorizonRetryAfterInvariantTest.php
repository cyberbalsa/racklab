<?php

declare(strict_types=1);

it('keeps every Horizon supervisor timeout strictly less than Redis retry_after across all pool groups', function (): void {
    $queue = require base_path('config/queue.php');
    $retryAfter = (int) $queue['connections']['redis']['retry_after'];

    foreach (['app', 'runner', 'all'] as $poolGroup) {
        putenv('RACKLAB_HORIZON_POOL_GROUP='.$poolGroup);
        $horizon = require base_path('config/horizon.php');
        putenv('RACKLAB_HORIZON_POOL_GROUP');

        foreach ($horizon['defaults'] as $name => $supervisor) {
            expect($supervisor['timeout'])->toBeLessThan(
                $retryAfter,
                sprintf('supervisor %s (pool group=%s) timeout %ss must be strictly less than Redis retry_after %ds', $name, $poolGroup, $supervisor['timeout'], $retryAfter),
            );
        }
    }
});

it('config/queue.php Redis retry_after default is 3700 (safe-by-default for the 3600s console supervisor)', function (): void {
    putenv('REDIS_QUEUE_RETRY_AFTER');
    $queue = require base_path('config/queue.php');

    expect((int) $queue['connections']['redis']['retry_after'])->toBe(3700);
});
