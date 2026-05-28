<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('ships the baseline Quadlet topology required by the runtime target', function (): void {
    $quadletDir = base_path('deploy/quadlets');
    $required = [
        'racklab.network',
        'racklab-runtime.target',
        'racklab-plugin-bootstrap.container',
        'racklab-postgres.container',
        'racklab-redis.container',
        'racklab-web.container',
        'racklab-reverb.container',
        'racklab-horizon-app.container',
        'racklab-horizon-runner.container',
        'racklab-scheduler-reconciler@.container',
    ];

    foreach ($required as $file) {
        expect($quadletDir.'/'.$file)->toBeFile();
    }

    foreach (['racklab-provider-worker', 'racklab-script-worker', 'racklab-console-worker', 'racklab-notification-worker'] as $legacy) {
        expect(file_exists($quadletDir.'/'.$legacy.'@.container'))->toBeFalse(sprintf('legacy %s Quadlet must not ship in the repo', $legacy));
    }

    $target = (string) file_get_contents($quadletDir.'/racklab-runtime.target');
    $web = (string) file_get_contents($quadletDir.'/racklab-web.container');
    $horizonApp = (string) file_get_contents($quadletDir.'/racklab-horizon-app.container');
    $horizonRunner = (string) file_get_contents($quadletDir.'/racklab-horizon-runner.container');
    $reverb = (string) file_get_contents($quadletDir.'/racklab-reverb.container');
    $scheduler = (string) file_get_contents($quadletDir.'/racklab-scheduler-reconciler@.container');
    $bootstrap = (string) file_get_contents($quadletDir.'/racklab-plugin-bootstrap.container');

    expect($target)
        ->toContain('racklab-postgres.service')
        ->toContain('racklab-redis.service')
        ->toContain('racklab-web.service')
        ->toContain('racklab-reverb.service')
        ->toContain('racklab-horizon-app.service')
        ->toContain('racklab-horizon-runner.service')
        ->toContain('racklab-scheduler-reconciler@1.service')
        ->not->toContain('racklab-provider-worker@1.service')
        ->not->toContain('racklab-script-worker@1.service')
        ->not->toContain('racklab-console-worker@1.service')
        ->not->toContain('racklab-notification-worker@1.service');

    expect($horizonApp)
        ->toContain('Image=ghcr.io/cyberbalsa/racklab/horizon:main')
        ->toContain('Environment=RACKLAB_HORIZON_POOL_GROUP=app')
        ->toContain('Exec=php artisan horizon')
        ->toContain('StopTimeout=3700')
        ->toContain('TimeoutStopSec=3730')
        ->toContain('Volume=/var/lib/racklab/plugins:/var/lib/racklab/plugins:ro,Z')
        ->not->toContain('podman.sock');

    expect($horizonRunner)
        ->toContain('Image=ghcr.io/cyberbalsa/racklab/horizon:main')
        ->toContain('Environment=RACKLAB_HORIZON_POOL_GROUP=runner')
        ->toContain('Exec=php artisan horizon')
        ->toContain('StopTimeout=3700')
        ->toContain('TimeoutStopSec=3730')
        ->toContain('Volume=/var/lib/racklab/plugins:/var/lib/racklab/plugins:ro,Z')
        ->toContain('Volume=/run/podman/podman.sock:/run/podman/podman.sock')
        ->toContain('Environment=CONTAINER_HOST=unix:///run/podman/podman.sock');

    // Plugin volume must be read-only on every runtime container — only
    // plugin-bootstrap retains write access. codex v2 P2 hardening.
    expect($web)->toContain('Volume=/var/lib/racklab/plugins:/var/lib/racklab/plugins:ro,Z');
    expect($reverb)->toContain('Volume=/var/lib/racklab/plugins:/var/lib/racklab/plugins:ro,Z');
    expect($scheduler)->toContain('Volume=/var/lib/racklab/plugins:/var/lib/racklab/plugins:ro,Z');
    expect($bootstrap)
        ->toContain('Volume=/var/lib/racklab/plugins:/var/lib/racklab/plugins:Z')
        ->not->toContain('Volume=/var/lib/racklab/plugins:/var/lib/racklab/plugins:ro,Z');

    expect($web)
        ->toContain('Image=ghcr.io/cyberbalsa/racklab/web:main')
        ->toContain('EnvironmentFile=/etc/racklab/racklab.env')
        ->toContain('Exec=php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=8000 --https --http-redirect --caddyfile=/etc/racklab/Caddyfile')
        ->toContain('PublishPort=0.0.0.0:443:8000');
});

it('dry-runs the baseline installer without mutating target directories', function (): void {
    $root = tempDirectory('racklab-install-dry-run-');
    $unitDir = $root.'/units';
    $configDir = $root.'/config';
    $dataDir = $root.'/data';

    $process = baselineInstaller([
        '--dry-run',
        '--non-interactive',
        '--domain=lab.example.edu',
        '--admin-email=ops@example.edu',
        '--tls-mode=self-signed',
        '--accept-license',
        '--unit-dir='.$unitDir,
        '--config-dir='.$configDir,
        '--data-dir='.$dataDir,
        '--skip-systemd-enable',
    ]);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('DRY RUN')
        ->and($process->getOutput())->toContain('write '.$configDir.'/racklab.env')
        ->and($process->getOutput())->toContain('copy deploy/quadlets/racklab-web.container')
        ->and(is_dir($unitDir))->toBeFalse()
        ->and(is_dir($configDir))->toBeFalse()
        ->and(is_dir($dataDir))->toBeFalse();
});

it('fails non-interactive installs when required flags are missing', function (): void {
    $root = tempDirectory('racklab-install-missing-');

    $process = baselineInstaller([
        '--non-interactive',
        '--accept-license',
        '--tls-mode=self-signed',
        '--unit-dir='.$root.'/units',
        '--config-dir='.$root.'/config',
        '--data-dir='.$root.'/data',
        '--skip-systemd-enable',
    ]);

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput())->toContain('--domain')
        ->and($process->getErrorOutput())->toContain('--admin-email')
        ->and($process->getErrorOutput())->toContain('--admin-password-stdin');
});

it('renders baseline config and Quadlets into custom directories without enabling systemd', function (): void {
    $root = tempDirectory('racklab-install-render-');
    $unitDir = $root.'/units';
    $configDir = $root.'/config';
    $dataDir = $root.'/data';

    $process = baselineInstaller([
        '--non-interactive',
        '--domain=lab.example.edu',
        '--admin-email=ops@example.edu',
        '--admin-password-stdin',
        '--tenant-slug=rit',
        '--tenant-name=RIT Cyber Range',
        '--tls-mode=self-signed',
        '--accept-license',
        '--image-tag=test-sha',
        '--listen-addr=127.0.0.1',
        '--listen-port=8443',
        '--unit-dir='.$unitDir,
        '--config-dir='.$configDir,
        '--data-dir='.$dataDir,
        '--skip-systemd-enable',
    ], input: 'correct horse battery staple');

    expect($process->getExitCode())->toBe(0)
        ->and($configDir.'/racklab.env')->toBeFile()
        ->and($configDir.'/racklab.toml')->toBeFile()
        ->and($configDir.'/Caddyfile')->toBeFile()
        ->and($dataDir.'/tls/bootstrap.crt')->toBeFile()
        ->and($unitDir.'/racklab-web.container')->toBeFile()
        ->and($unitDir.'/racklab-horizon-app.container')->toBeFile()
        ->and($unitDir.'/racklab-horizon-runner.container')->toBeFile();

    $env = (string) file_get_contents($configDir.'/racklab.env');
    $toml = (string) file_get_contents($configDir.'/racklab.toml');
    $web = (string) file_get_contents($unitDir.'/racklab-web.container');
    $horizonRunner = (string) file_get_contents($unitDir.'/racklab-horizon-runner.container');

    expect($env)
        ->toContain('APP_URL=https://lab.example.edu:8443')
        ->toContain('RACKLAB_DEFAULT_TENANT_SLUG=rit')
        ->toContain('APP_KEY=base64:')
        ->not->toContain('correct horse battery staple')
        ->and($toml)
        ->toContain('tenant_slug = "rit"')
        ->toContain('tenant_name = "RIT Cyber Range"')
        ->and($web)
        ->toContain('Image=ghcr.io/cyberbalsa/racklab/web:test-sha')
        ->toContain('EnvironmentFile='.$configDir.'/racklab.env')
        ->toContain('Volume='.$dataDir.'/plugins:/var/lib/racklab/plugins:ro,Z')
        ->toContain('PublishPort=127.0.0.1:8443:8000')
        ->and($horizonRunner)
        ->toContain('Image=ghcr.io/cyberbalsa/racklab/horizon:test-sha')
        ->toContain('Environment=RACKLAB_HORIZON_POOL_GROUP=runner');
});

it('removes the four legacy worker units cleanly on install (idempotent upgrade path)', function (): void {
    $root = tempDirectory('racklab-install-legacy-cleanup-');
    $unitDir = $root.'/units';
    $configDir = $root.'/config';
    $dataDir = $root.'/data';

    // Seed pre-Horizon legacy unit files (as if a previous baseline install left them).
    mkdir($unitDir, 0o755, recursive: true);
    foreach (['racklab-provider-worker', 'racklab-script-worker', 'racklab-console-worker', 'racklab-notification-worker'] as $legacy) {
        file_put_contents($unitDir.'/'.$legacy.'@.container', "[Container]\nImage=legacy\n");
    }

    $process = baselineInstaller([
        '--non-interactive',
        '--domain=lab.example.edu',
        '--admin-email=ops@example.edu',
        '--admin-password-stdin',
        '--tenant-slug=rit',
        '--tls-mode=self-signed',
        '--accept-license',
        '--image-tag=test-sha',
        '--listen-addr=127.0.0.1',
        '--listen-port=8443',
        '--unit-dir='.$unitDir,
        '--config-dir='.$configDir,
        '--data-dir='.$dataDir,
        '--skip-systemd-enable',
    ], input: 'correct horse battery staple');

    expect($process->getExitCode())->toBe(0);

    foreach (['racklab-provider-worker', 'racklab-script-worker', 'racklab-console-worker', 'racklab-notification-worker'] as $legacy) {
        expect(file_exists($unitDir.'/'.$legacy.'@.container'))->toBeFalse(sprintf('legacy %s unit must be removed on upgrade', $legacy));
    }

    // New Horizon Quadlets present
    expect($unitDir.'/racklab-horizon-app.container')->toBeFile();
    expect($unitDir.'/racklab-horizon-runner.container')->toBeFile();
});

/**
 * @param  list<string>  $arguments
 */
function baselineInstaller(array $arguments, string $input = ''): Process
{
    $process = new Process(['bash', base_path('scripts/baseline-install.sh'), ...$arguments], base_path(), input: $input);
    $process->setTimeout(30);
    $process->run();

    return $process;
}

function tempDirectory(string $prefix): string
{
    $path = tempnam(sys_get_temp_dir(), $prefix);
    expect($path)->toBeString();
    unlink($path);

    return $path;
}
