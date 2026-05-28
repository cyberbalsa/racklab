<?php

declare(strict_types=1);

use App\Http\Middleware\BindAuthenticatedTenant;

/*
 * RackLab Horizon config — see docs/superpowers/specs/2026-05-28-horizon-and-supply-chain-design.md.
 *
 * Topology: four supervisors matching the actual job dispatches in app/Jobs/.
 * Two Quadlets each run `php artisan horizon` with RACKLAB_HORIZON_POOL_GROUP
 * scoping which supervisors are active:
 *   - `app`     → racklab-provider + racklab-notifications (no Podman socket)
 *   - `runner`  → racklab-scripts + racklab-console (with Podman socket)
 *   - `all`     → all four (local dev + Pest testing)
 *
 * Queue names match what app/Jobs/RunScriptContainer.php, PollProxmoxTask.php,
 * RunFakeProviderTask.php, and RunConsoleScript.php (override) dispatch onto.
 * Legacy aliases (`provider`, `scripts`, `console`, `notifications`, `default`)
 * remain in each supervisor's queue list so in-flight payloads keep draining.
 */

$appSupervisors = ['racklab-provider', 'racklab-notifications'];
$runnerSupervisors = ['racklab-scripts', 'racklab-console'];
$poolGroup = env('RACKLAB_HORIZON_POOL_GROUP', 'all');

$selectSupervisors = static function (array $supervisorMap) use ($poolGroup, $appSupervisors, $runnerSupervisors): array {
    return match ($poolGroup) {
        'app' => array_intersect_key($supervisorMap, array_flip($appSupervisors)),
        'runner' => array_intersect_key($supervisorMap, array_flip($runnerSupervisors)),
        default => $supervisorMap,
    };
};

$productionMap = [
    'racklab-provider' => ['minProcesses' => 1, 'maxProcesses' => 6],
    'racklab-scripts' => ['minProcesses' => 1, 'maxProcesses' => 8],
    'racklab-console' => ['minProcesses' => 1, 'maxProcesses' => 1],
    'racklab-notifications' => ['minProcesses' => 1, 'maxProcesses' => 4],
];

$localMap = [
    'racklab-provider' => ['minProcesses' => 1, 'maxProcesses' => 2],
    'racklab-scripts' => ['minProcesses' => 1, 'maxProcesses' => 2],
    'racklab-console' => ['minProcesses' => 1, 'maxProcesses' => 1],
    'racklab-notifications' => ['minProcesses' => 1, 'maxProcesses' => 1],
];

$testingMap = [
    'racklab-provider' => ['balance' => 'simple', 'processes' => 1, 'minProcesses' => 1, 'maxProcesses' => 1],
    'racklab-scripts' => ['balance' => 'simple', 'processes' => 1, 'minProcesses' => 1, 'maxProcesses' => 1],
    'racklab-console' => ['balance' => 'simple', 'processes' => 1, 'minProcesses' => 1, 'maxProcesses' => 1],
    'racklab-notifications' => ['balance' => 'simple', 'processes' => 1, 'minProcesses' => 1, 'maxProcesses' => 1],
];

return [
    'domain' => env('HORIZON_DOMAIN'),
    'path' => env('HORIZON_PATH', 'horizon'),
    'use' => 'default',
    'prefix' => env('HORIZON_PREFIX', 'racklab_horizon:'),
    // No `auth` middleware — anonymous requests must reach the HorizonAuthGate
    // so the denial audit row is emitted. BindAuthenticatedTenant binds the
    // user's primary tenant for downstream views even though /horizon isn't
    // /admin/{tenant}/...
    'middleware' => ['web', BindAuthenticatedTenant::class],
    'waits' => [
        'redis:provider-worker' => 60,
        'redis:script-worker' => 60,
        'redis:console-worker' => 600,
        'redis:notification-worker' => 30,
    ],
    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],
    'silenced' => [],
    'metrics' => [
        'trim_snapshots' => ['job' => 24, 'queue' => 24],
    ],
    'fast_termination' => false,
    'memory_limit' => 128,
    'defaults' => [
        'racklab-provider' => [
            'connection' => 'redis',
            'queue' => ['provider-worker', 'provider', 'default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 6,
            'maxTime' => 3600,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 300,
            'nice' => 0,
        ],
        'racklab-scripts' => [
            'connection' => 'redis',
            'queue' => ['script-worker', 'scripts', 'cleanup'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 8,
            'maxTime' => 3600,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 900,
            'nice' => 0,
        ],
        'racklab-console' => [
            'connection' => 'redis',
            'queue' => ['console-worker', 'console'],
            'balance' => 'simple',
            'maxProcesses' => 1,
            'maxTime' => 3600,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 3600,
            'nice' => 0,
        ],
        'racklab-notifications' => [
            'connection' => 'redis',
            'queue' => ['notification-worker', 'notifications', 'default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 4,
            'maxTime' => 3600,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 120,
            'nice' => 0,
        ],
    ],
    'environments' => [
        'production' => $selectSupervisors($productionMap),
        'local' => $selectSupervisors($localMap),
        'testing' => $selectSupervisors($testingMap),
    ],
];
