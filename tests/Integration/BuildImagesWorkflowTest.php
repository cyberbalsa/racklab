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
    $steps = array_column($job['steps'], 'run', 'name');
    $uses = array_column($job['steps'], 'uses', 'name');

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
        ->and($steps['Install Syft'])->toContain('anchore/syft')
        ->and($steps['Generate CycloneDX SBOM'])->toContain('cyclonedx-json')
        ->and($steps['License policy gate'])->toContain('GPL-3.0')
        ->and($steps['License policy gate'])->toContain('AGPL-3.0')
        ->and($steps['Publish image'])->toContain('ghcr.io/cyberbalsa/racklab/${IMAGE}');
});
