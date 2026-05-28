<?php

declare(strict_types=1);

use App\Jobs\PollProxmoxTask;
use App\Jobs\RunAnsiblePlaybook;
use App\Jobs\RunConsoleScript;
use App\Jobs\RunFakeProviderTask;
use App\Jobs\RunUserScript;

it('RunUserScript queues on script-worker', function (): void {
    $job = new RunUserScript('tenant-a', 'run-1');
    expect($job->queue)->toBe('script-worker');
});

it('RunAnsiblePlaybook queues on script-worker', function (): void {
    $job = new RunAnsiblePlaybook('tenant-a', 'run-1');
    expect($job->queue)->toBe('script-worker');
});

it('RunConsoleScript queues on console-worker (overrides parent script-worker)', function (): void {
    $job = new RunConsoleScript('tenant-a', 'run-1');
    expect($job->queue)->toBe('console-worker');
});

it('PollProxmoxTask queues on provider-worker', function (): void {
    $job = new PollProxmoxTask('tenant-a', 'provider-task-1');
    expect($job->queue)->toBe('provider-worker');
});

it('RunFakeProviderTask queues on provider-worker', function (): void {
    $job = new RunFakeProviderTask('tenant-a', 'provider-task-1');
    expect($job->queue)->toBe('provider-worker');
});

it('RunConsoleScript has $timeout 3630s sized for console runs (3600 + 30s cleanup margin)', function (): void {
    $job = new RunConsoleScript('tenant-a', 'run-1');
    expect($job->timeout)->toBe(3630);
});

it('RunConsoleScript container manifest timeoutSeconds is 3600s', function (): void {
    $manifest = RunConsoleScript::containerManifest();
    expect($manifest->timeoutSeconds)->toBe(3600);
});

it('RunConsoleScript $timeout is strictly less than the hardcoded Redis retry_after default of 3700', function (): void {
    // Hardcoded — Tiny tests can't load config/queue.php without Laravel boot.
    // This invariant is locked by HorizonRetryAfterInvariantTest (Contract) for the config-level default.
    $retryAfter = 3700;
    $job = new RunConsoleScript('tenant-a', 'run-1');
    expect($job->timeout)->toBeLessThan($retryAfter);
});
