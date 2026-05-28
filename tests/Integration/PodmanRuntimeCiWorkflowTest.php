<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('defines a hard-fail Podman runtime workflow for cgroup-delegated self-hosted runners', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/podman-runtime-ci.yml'));
    $job = $workflow['jobs']['podman-runtime'];
    $env = $workflow['env'];
    $steps = array_column($job['steps'], 'run', 'name');

    expect($job['runs-on'])->toBe(['self-hosted', 'linux', 'podman', 'cgroup-delegated'])
        ->and($env['RACKLAB_REQUIRE_PODMAN_INTEGRATION'])->toBe('1')
        ->and($env['RACKLAB_PODMAN_BINARY'])->toBe('${{ inputs.podman-binary }}')
        ->and($steps['Show Podman host info'])->toBe('${{ inputs.podman-binary }} info')
        ->and($steps['Hardened Podman integration'])->toBe('composer pest:integration -- tests/Integration/PodmanRuntimeIntegrationTest.php');
});
