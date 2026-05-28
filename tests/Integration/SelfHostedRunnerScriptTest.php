<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('declares the required runner labels in the documented order', function (): void {
    $script = (string) file_get_contents(base_path('scripts/dev/register-host-runner.sh'));

    foreach (['self-hosted', 'linux', 'podman', 'cgroup-delegated'] as $label) {
        expect($script)->toContain($label);
    }

    expect($script)->toContain('self-hosted,linux,podman,cgroup-delegated');
});

it('refuses the --token= flag with exit code 2 (use --token-file or stdin instead)', function (): void {
    $scriptPath = base_path('scripts/dev/register-host-runner.sh');
    expect($scriptPath)->toBeFile();
    expect(is_executable($scriptPath))->toBeTrue();

    $process = new Process([$scriptPath, '--token=leakysecret']);
    $process->run();

    expect($process->getExitCode())->toBe(2);
    expect($process->getErrorOutput())->toContain('--token=');
    expect($process->getErrorOutput())->toContain('shell history');

    // Static guarantee: the script reads tokens via stdin or --token-file, never via a --token= argv flag.
    $script = (string) file_get_contents($scriptPath);
    expect($script)->toMatch('/--token-file/');
    expect($script)->toMatch('/read .*TOKEN/');
});

it('verifies the runner archive sha256 before extraction', function (): void {
    $script = (string) file_get_contents(base_path('scripts/dev/register-host-runner.sh'));

    expect($script)->toContain('sha256sum');
    expect($script)->toContain('.tar.gz.sha256');
});

it('refuses to overwrite an existing runner config without --reconfigure', function (): void {
    $script = (string) file_get_contents(base_path('scripts/dev/register-host-runner.sh'));

    expect($script)->toContain('--reconfigure');
    expect($script)->toMatch('/Re-run with --reconfigure/');
});

it('pins RUNNER_VERSION to a 2.319.x (or later) release', function (): void {
    $script = (string) file_get_contents(base_path('scripts/dev/register-host-runner.sh'));

    expect($script)->toMatch('/RUNNER_VERSION="2\.(319|3[2-9]\d|[4-9]\d{2})\.\d+"/');
});

it('targets the cyberbalsa/racklab repository', function (): void {
    $script = (string) file_get_contents(base_path('scripts/dev/register-host-runner.sh'));

    expect($script)->toContain('https://github.com/cyberbalsa/racklab');
});

it('publishes a systemd-user unit template that restarts and lives under default.target', function (): void {
    $template = (string) file_get_contents(base_path('scripts/dev/racklab-self-hosted-runner.service.template'));

    expect($template)->toContain('[Service]');
    expect($template)->toContain('Type=simple');
    expect($template)->toContain('Restart=always');
    expect($template)->toContain('RestartSec=10');
    expect($template)->toContain('WantedBy=default.target');
    expect($template)->toContain('RACKLAB_RUNNER_HOME');
    expect($template)->toContain('%h/.racklab/actions-runner');
});
