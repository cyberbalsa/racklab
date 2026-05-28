<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('configures the browser CI job with a shared database and persistent sessions', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/code-ci.yml'));
    $browser = $workflow['jobs']['browser'];
    $env = $browser['env'];
    $steps = array_column($browser['steps'], 'name');
    $runs = array_column($browser['steps'], 'run', 'name');

    expect($env['APP_URL'])->toBe('http://127.0.0.1:8000')
        ->and($env['DB_CONNECTION'])->toBe('sqlite')
        ->and($env['DB_DATABASE'])->toBe('${{ github.workspace }}/database/dusk.sqlite')
        ->and($env['SESSION_DRIVER'])->toBe('file')
        ->and($env['RACKLAB_CONTAINER_RUNTIME'])->toBe('fake')
        ->and($steps)->toContain('Prepare browser database')
        ->and($runs['Run browser migrations'])->toBe('php artisan racklab:migrate --skip-plugins')
        ->and($steps)->toContain('Pest browser')
        ->and($steps)->toContain('Pa11y CI')
        ->and($steps)->toContain('Upload browser failure artifacts')
        ->and($runs['Wait for Laravel server'])->toContain('/readyz');
});
