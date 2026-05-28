<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('defines one multi-target Containerfile for the Baseline image family', function (): void {
    $containerfile = (string) file_get_contents(base_path('Containerfile'));

    expect($containerfile)
        ->toContain('FROM dunglas/frankenphp:php8.3-bookworm AS runtime')
        ->toContain('COPY --from=assets /app/public/build ./public/build')
        ->toContain('install-php-extensions')
        ->toContain('postgresql-client')
        ->toContain('redis-tools')
        ->toContain('podman')
        ->toContain('composer install --no-dev')
        ->toContain('FROM runtime AS web')
        ->toContain('FROM runtime AS reverb')
        ->toContain('FROM runtime AS provider-worker')
        ->toContain('FROM runtime AS script-worker')
        ->toContain('FROM runtime AS console-worker')
        ->toContain('FROM runtime AS scheduler-reconciler')
        ->toContain('FROM runtime AS notification-worker');

    expect(strpos($containerfile, 'mkdir -p'))
        ->toBeLessThan(strpos($containerfile, 'php artisan package:discover --ansi'));

    expect($containerfile)
        ->toContain('BROADCAST_CONNECTION=null php artisan package:discover --ansi');
});

it('keeps local-only files out of the production image build context', function (): void {
    $dockerignore = (string) file_get_contents(base_path('.dockerignore'));

    expect($dockerignore)
        ->toContain('.env')
        ->toContain('node_modules/')
        ->toContain('vendor/')
        ->toContain('tests/')
        ->toContain('storage/logs/')
        ->toContain('public/build/');
});

it('builds and publishes every Baseline image with SBOM and license gates', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/build-images.yml'));
    $job = $workflow['jobs']['build-images'];
    $matrix = $job['strategy']['matrix']['include'];
    $stepNames = array_column($job['steps'], 'name');
    $steps = array_column($job['steps'], 'run', 'name');
    $uses = array_column($job['steps'], 'uses', 'name');
    $licensePolicy = (string) file_get_contents(base_path('scripts/ci/check-image-licenses.sh'));

    $images = array_column($matrix, 'image');
    $targets = array_column($matrix, 'target');

    expect($workflow['permissions']['contents'])->toBe('read')
        ->and($workflow['permissions']['packages'])->toBe('write')
        ->and($workflow['env']['SYFT_VERSION'])->toBe('v1.44.0')
        ->and($job['runs-on'])->toBe('ubuntu-24.04')
        ->and($images)->toBe([
            'web',
            'reverb',
            'provider-worker',
            'script-worker',
            'console-worker',
            'scheduler-reconciler',
            'notification-worker',
        ])
        ->and($targets)->toBe($images)
        ->and($uses['Checkout'])->toBe('actions/checkout@v6')
        ->and($uses['Set up Docker Buildx'])->toBe('docker/setup-buildx-action@v3')
        ->and($uses['Upload image SBOM'])->toBe('actions/upload-artifact@v4')
        ->and($steps['Build local image'])->toContain('docker build')
        ->and($steps['Composer audit inside image'])->toContain('composer audit')
        ->and($steps['Artisan smoke inside image'])->toContain('-e BROADCAST_CONNECTION=null')
        ->and($steps['Install Syft'])->toContain('anchore/syft')
        ->and($steps['Generate CycloneDX SBOM'])->toContain('cyclonedx-json')
        ->and(array_search('Upload image SBOM', $stepNames, true))->toBeLessThan(array_search('License policy gate', $stepNames, true))
        ->and($licensePolicy)->toContain('GPL-3.0')
        ->and($licensePolicy)->toContain('AGPL-3.0')
        ->and($steps['License policy gate'])->toContain('scripts/ci/check-image-licenses.sh')
        ->and($steps['Publish image'])->toContain('ghcr.io/cyberbalsa/racklab/${IMAGE}');
});

it('allows documented runtime image license exceptions only', function (): void {
    $sbom = buildImagesWorkflowTemporarySbom([
        [
            'name' => 'nette/utils',
            'licenses' => ['BSD-3-Clause', 'GPL-3.0-only'],
        ],
        [
            'name' => 'sysvinit-utils',
            'licenses' => ['GPL-3.0'],
        ],
    ]);

    exec(
        sprintf(
            'bash %s %s %s 2>&1',
            escapeshellarg(base_path('scripts/ci/check-image-licenses.sh')),
            escapeshellarg($sbom),
            escapeshellarg(base_path('.github/license-policy.allowlist.json')),
        ),
        $output,
        $exitCode,
    );

    @unlink($sbom);

    expect($exitCode)->toBe(0)
        ->and(implode("\n", $output))->toBe('');
});

it('rejects unallowlisted forbidden runtime image licenses', function (): void {
    $sbom = buildImagesWorkflowTemporarySbom([
        [
            'name' => 'example/forbidden',
            'licenses' => [
                ['value' => 'AGPL-3.0-only'],
            ],
        ],
    ]);

    exec(
        sprintf(
            'bash %s %s %s 2>&1',
            escapeshellarg(base_path('scripts/ci/check-image-licenses.sh')),
            escapeshellarg($sbom),
            escapeshellarg(base_path('.github/license-policy.allowlist.json')),
        ),
        $output,
        $exitCode,
    );

    @unlink($sbom);

    expect($exitCode)->toBe(1)
        ->and(implode("\n", $output))->toContain('example/forbidden')
        ->and(implode("\n", $output))->toContain('AGPL-3.0-only');
});

/**
 * @param  list<array{name: string, licenses: list<mixed>}>  $artifacts
 */
function buildImagesWorkflowTemporarySbom(array $artifacts): string
{
    $path = tempnam(sys_get_temp_dir(), 'racklab-sbom-');

    if ($path === false) {
        throw new RuntimeException('Unable to create temporary SBOM file.');
    }

    file_put_contents($path, json_encode(['artifacts' => $artifacts], JSON_THROW_ON_ERROR));

    return $path;
}
