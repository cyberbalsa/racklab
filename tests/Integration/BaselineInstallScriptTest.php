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
        'racklab-provider-worker@.container',
        'racklab-script-worker@.container',
        'racklab-console-worker@.container',
        'racklab-scheduler-reconciler@.container',
        'racklab-notification-worker@.container',
    ];

    foreach ($required as $file) {
        expect($quadletDir.'/'.$file)->toBeFile();
    }

    $target = (string) file_get_contents($quadletDir.'/racklab-runtime.target');
    $web = (string) file_get_contents($quadletDir.'/racklab-web.container');
    $providerWorker = (string) file_get_contents($quadletDir.'/racklab-provider-worker@.container');
    $scriptWorker = (string) file_get_contents($quadletDir.'/racklab-script-worker@.container');

    expect($target)
        ->toContain('racklab-postgres.service')
        ->toContain('racklab-redis.service')
        ->toContain('racklab-web.service')
        ->toContain('racklab-reverb.service')
        ->toContain('racklab-provider-worker@1.service')
        ->and($web)
        ->toContain('Image=ghcr.io/cyberbalsa/racklab/web:main')
        ->toContain('EnvironmentFile=/etc/racklab/racklab.env')
        ->toContain('Volume=/var/lib/racklab/plugins:/var/lib/racklab/plugins:Z')
        ->toContain('Exec=php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=8000 --https --http-redirect --caddyfile=/etc/racklab/Caddyfile')
        ->toContain('PublishPort=0.0.0.0:443:8000')
        ->and($providerWorker)
        ->toContain('DefaultInstance=1')
        ->toContain('Exec=php artisan queue:work redis --queue=provider,default --sleep=1 --tries=1 --timeout=300 --max-time=3600')
        ->and($scriptWorker)
        ->toContain('Volume=/run/podman/podman.sock:/run/podman/podman.sock')
        ->toContain('Environment=CONTAINER_HOST=unix:///run/podman/podman.sock');
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
        ->and($unitDir.'/racklab-web.container')->toBeFile();

    $env = (string) file_get_contents($configDir.'/racklab.env');
    $toml = (string) file_get_contents($configDir.'/racklab.toml');
    $web = (string) file_get_contents($unitDir.'/racklab-web.container');

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
        ->toContain('Volume='.$dataDir.'/plugins:/var/lib/racklab/plugins:Z')
        ->toContain('PublishPort=127.0.0.1:8443:8000');
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
