<?php

declare(strict_types=1);

use App\Models\ScriptRun;
use App\Models\Tenant;
use App\Models\User;
use App\Runtime\ContainerManifest;
use App\Runtime\ContainerRunRequest;
use App\Runtime\ContainerRunResult;
use App\Runtime\NativeContainerProcessRunner;
use App\Runtime\PodmanCommandBuilder;
use App\Runtime\PodmanContainerRuntime;
use App\Runtime\PodmanStaleContainerReaper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

it('runs a hardened script container on a real Podman host when available', function (): void {
    $binary = racklabPodmanIntegrationBinary();
    $info = racklabPodmanIntegrationProcess([...$binary, 'info', '--format', 'json']);

    if (! $info->isSuccessful()) {
        racklabPodmanIntegrationUnavailable($this, 'Podman is not usable for this user: '.$info->getErrorOutput());
    }

    $probe = racklabPodmanIntegrationProcess([
        ...$binary,
        'run',
        '--rm',
        '--network=none',
        '--read-only',
        '--tmpfs=/tmp',
        '--user=10001:10001',
        '--cap-drop=all',
        '--security-opt=no-new-privileges',
        '--cpus=0.25',
        '--memory=64m',
        '--pids-limit=64',
        'docker.io/library/busybox:1.36',
        'sh',
        '-c',
        'echo racklab-podman-capability-ok',
    ], 90);

    if (! $probe->isSuccessful()) {
        racklabPodmanIntegrationUnavailable($this, 'Podman is present but this host cannot run RackLab hardened limits: '.$probe->getErrorOutput());
    }

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create(['name' => 'Podman Runner']);
    $run = ScriptRun::query()->create([
        'tenant_id' => $tenant->getKey(),
        'actor_user_id' => $user->getKey(),
        'runner_kind' => 'user_script',
        'state' => 'queued',
        'command' => ['sh', '-c', 'echo racklab-podman-ok'],
        'source' => 'echo racklab-podman-ok',
        'metadata' => [],
    ]);
    $manifest = new ContainerManifest(
        image: 'docker.io/library/busybox:1.36',
        networkMode: 'none',
        cpus: 0.25,
        memory: '64m',
        pidsLimit: 64,
        readOnlyRoot: true,
        tmpfs: ['/tmp'],
        user: '10001:10001',
        mounts: [],
        environment: [],
        timeoutSeconds: 30,
    );

    $result = (new PodmanContainerRuntime(
        new PodmanCommandBuilder($binary),
        new NativeContainerProcessRunner,
        cleanupTimeoutSeconds: 10,
    ))->run(new ContainerRunRequest($run, $manifest));

    expect($result->exitCode)->toBe(0)
        ->and($result->stdout)->toContain('racklab-podman-ok')
        ->and($result->metadata['runtime'])->toBe('podman')
        ->and($result->metadata['container_name'])->toBe('racklab-script-'.$run->getKey());

    $identityResult = racklabPodmanIntegrationRunScript(
        $binary,
        $tenant->getKey(),
        $user->getKey(),
        ['sh', '-c', 'id -u'],
        'id -u',
    );

    expect($identityResult->exitCode)->toBe(0)
        ->and(trim($identityResult->stdout))->toBe('10001');

    $capabilityResult = racklabPodmanIntegrationRunScript(
        $binary,
        $tenant->getKey(),
        $user->getKey(),
        ['sh', '-c', 'awk \'/^CapEff:/ { print $2 }\' /proc/self/status'],
        'read effective capability mask',
    );

    expect($capabilityResult->exitCode)->toBe(0)
        ->and(trim($capabilityResult->stdout))->toBe('0000000000000000');

    $noNewPrivilegesResult = racklabPodmanIntegrationRunScript(
        $binary,
        $tenant->getKey(),
        $user->getKey(),
        ['sh', '-c', 'awk \'/^NoNewPrivs:/ { print $2 }\' /proc/self/status'],
        'read no-new-privileges bit',
    );

    expect($noNewPrivilegesResult->exitCode)->toBe(0)
        ->and(trim($noNewPrivilegesResult->stdout))->toBe('1');

    $readOnlyResult = racklabPodmanIntegrationRunScript(
        $binary,
        $tenant->getKey(),
        $user->getKey(),
        ['sh', '-c', 'touch /racklab-readonly-denied'],
        'touch /racklab-readonly-denied',
    );

    expect($readOnlyResult->exitCode)->not->toBe(0);

    $networkResult = racklabPodmanIntegrationRunScript(
        $binary,
        $tenant->getKey(),
        $user->getKey(),
        ['sh', '-c', 'while read iface destination rest; do [ "$destination" = "00000000" ] && exit 0; done < /proc/net/route; exit 1'],
        'assert no default route',
    );

    expect($networkResult->exitCode)->not->toBe(0);

    $timeoutResult = racklabPodmanIntegrationRunScript(
        $binary,
        $tenant->getKey(),
        $user->getKey(),
        ['sh', '-c', 'sleep 30'],
        'sleep 30',
        timeoutSeconds: 2,
    );

    expect($timeoutResult->timedOut)->toBeTrue()
        ->and($timeoutResult->exitCode)->toBe(124)
        ->and($timeoutResult->metadata['cleanup_exit_code'])->toBe(0)
        ->and($timeoutResult->metadata['cleanup_timed_out'])->toBeFalse();

    $staleContainerName = 'racklab-script-stale-'.$tenant->getKey();
    racklabPodmanIntegrationProcess([...$binary, 'rm', '-f', '--ignore', $staleContainerName]);
    $createStaleContainer = racklabPodmanIntegrationProcess([
        ...$binary,
        'create',
        '--name='.$staleContainerName,
        '--label=racklab.kind=script-run',
        '--label=racklab.created_at='.(time() - 3600),
        'docker.io/library/busybox:1.36',
        'sh',
        '-c',
        'sleep 1',
    ], 90);

    if (! $createStaleContainer->isSuccessful()) {
        throw new RuntimeException('Unable to create stale RackLab script container: '.$createStaleContainer->getErrorOutput());
    }

    try {
        $reaped = (new PodmanStaleContainerReaper(
            new PodmanCommandBuilder($binary),
            new NativeContainerProcessRunner,
        ))->reap(maxAgeSeconds: 300, now: new DateTimeImmutable('@'.time()));
        $exists = racklabPodmanIntegrationProcess([...$binary, 'container', 'exists', $staleContainerName]);

        expect($reaped)->toBeGreaterThanOrEqual(1)
            ->and($exists->isSuccessful())->toBeFalse();
    } finally {
        racklabPodmanIntegrationProcess([...$binary, 'rm', '-f', '--ignore', $staleContainerName]);
    }
});

/**
 * @return list<string>
 */
function racklabPodmanIntegrationBinary(): array
{
    $binary = getenv('RACKLAB_PODMAN_BINARY') ?: 'podman';
    $parts = preg_split('/\s+/', trim($binary));

    if ($parts === false) {
        return ['podman'];
    }

    $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));

    return $parts === [] ? ['podman'] : $parts;
}

/**
 * @param  list<string>  $command
 */
function racklabPodmanIntegrationProcess(array $command, int $timeoutSeconds = 20): Process
{
    $process = new Process($command);
    $process->setTimeout($timeoutSeconds);
    $process->run();

    return $process;
}

function racklabPodmanIntegrationUnavailable(object $test, string $reason): void
{
    if (filter_var(getenv('RACKLAB_REQUIRE_PODMAN_INTEGRATION'), FILTER_VALIDATE_BOOL)) {
        throw new RuntimeException($reason);
    }

    $test->markTestSkipped($reason);
}

/**
 * @param  list<string>  $binary
 * @param  list<string>  $command
 */
function racklabPodmanIntegrationRunScript(
    array $binary,
    string $tenantId,
    int $userId,
    array $command,
    string $source,
    int $timeoutSeconds = 30,
): ContainerRunResult {
    $run = ScriptRun::query()->create([
        'tenant_id' => $tenantId,
        'actor_user_id' => $userId,
        'runner_kind' => 'user_script',
        'state' => 'queued',
        'command' => $command,
        'source' => $source,
        'metadata' => [],
    ]);
    $manifest = new ContainerManifest(
        image: 'docker.io/library/busybox:1.36',
        networkMode: 'none',
        cpus: 0.25,
        memory: '64m',
        pidsLimit: 64,
        readOnlyRoot: true,
        tmpfs: ['/tmp'],
        user: '10001:10001',
        mounts: [],
        environment: [],
        timeoutSeconds: $timeoutSeconds,
    );

    return (new PodmanContainerRuntime(
        new PodmanCommandBuilder($binary),
        new NativeContainerProcessRunner,
        cleanupTimeoutSeconds: 10,
    ))->run(new ContainerRunRequest($run, $manifest));
}
