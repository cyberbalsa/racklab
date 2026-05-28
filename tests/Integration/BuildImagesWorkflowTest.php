<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('defines one multi-target Containerfile for the Baseline image family', function (): void {
    $containerfile = (string) file_get_contents(base_path('Containerfile'));

    expect($containerfile)
        ->toContain('FROM docker.io/dunglas/frankenphp:php8.3-bookworm AS runtime')
        ->toContain('COPY --from=assets /app/public/build ./public/build')
        ->toContain('install-php-extensions')
        ->toContain('postgresql-client')
        ->toContain('redis-tools')
        ->toContain('podman')
        ->toContain('composer install --no-dev')
        ->toContain('FROM runtime AS web')
        ->toContain('FROM runtime AS reverb')
        ->toContain('FROM runtime AS horizon')
        ->toContain('FROM runtime AS scheduler-reconciler')
        ->not->toContain('FROM runtime AS provider-worker')
        ->not->toContain('FROM runtime AS script-worker')
        ->not->toContain('FROM runtime AS console-worker')
        ->not->toContain('FROM runtime AS notification-worker');

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
            'horizon',
            'scheduler-reconciler',
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

it('runs the two-scan Grype model (full + fixed-only) and uploads SARIF', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/build-images.yml'));
    $steps = $workflow['jobs']['build-images']['steps'];
    $names = array_column($steps, 'name');

    expect($names)->toContain('Grype full report')
        ->and($names)->toContain('Upload full SARIF')
        ->and($names)->toContain('Grype fixed-CVE failure gate');

    $full = current(array_filter($steps, fn (array $s): bool => ($s['name'] ?? '') === 'Grype full report'));
    expect($full['uses'])->toBe('anchore/scan-action@v7')
        ->and($full['with']['fail-build'])->toBe(false)
        ->and($full['with']['only-fixed'])->toBe(false);

    $gate = current(array_filter($steps, fn (array $s): bool => ($s['name'] ?? '') === 'Grype fixed-CVE failure gate'));
    expect($gate['uses'])->toBe('anchore/scan-action@v7')
        ->and($gate['with']['fail-build'])->toBe(true)
        ->and($gate['with']['only-fixed'])->toBe(true)
        ->and($gate['with']['severity-cutoff'])->toBe('high')
        ->and($gate['with']['config'])->toBe('.grype.yaml');

    $upload = current(array_filter($steps, fn (array $s): bool => ($s['name'] ?? '') === 'Upload full SARIF'));
    expect($upload['uses'])->toBe('github/codeql-action/upload-sarif@v4');
});

it('declares security-events: write permission for SARIF upload', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/build-images.yml'));

    expect($workflow['permissions']['security-events'] ?? null)->toBe('write');
});

it('places .grype.yaml at repo root with an empty initial allowlist', function (): void {
    expect(file_exists(base_path('.grype.yaml')))->toBeTrue();

    $config = Yaml::parseFile(base_path('.grype.yaml'));

    expect($config['ignore'] ?? null)->toBe([]);
});

it('publishes legacy mirror tags from the horizon image for one release cycle', function (): void {
    // The four legacy per-pool image names (provider-worker, script-worker,
    // console-worker, notification-worker) must continue to publish from the
    // horizon image so external consumers don't break instantly. The publish
    // step gates on `matrix.image == 'horizon'` so it only fires once.
    $workflow = Yaml::parseFile(base_path('.github/workflows/build-images.yml'));
    $steps = $workflow['jobs']['build-images']['steps'];

    $mirror = current(array_filter($steps, fn (array $s): bool => ($s['name'] ?? '') === 'Tag legacy aliases for one release cycle'));
    expect($mirror)->not->toBeFalse();
    expect($mirror['if'])->toContain("matrix.image == 'horizon'");

    foreach (['provider-worker', 'script-worker', 'console-worker', 'notification-worker'] as $alias) {
        expect($mirror['run'])->toContain($alias);
    }
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
