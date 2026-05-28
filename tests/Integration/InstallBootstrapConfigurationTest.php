<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('runs the RackLab migration command during setup and PostgreSQL smoke', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $workflow = Yaml::parseFile(base_path('.github/workflows/code-ci.yml'));
    $postgresSteps = array_column($workflow['jobs']['postgres-smoke']['steps'], 'run', 'name');

    expect($composer['scripts']['setup'])->toContain('@php artisan racklab:migrate')
        ->and($composer['scripts']['post-create-project-cmd'])->toContain('@php artisan racklab:migrate')
        ->and($postgresSteps['Run RackLab migrations'])->toBe('php artisan racklab:migrate');
});

it('locks Composer dependency resolution to the supported PHP 8.3 floor', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['require']['php'])->toBe('^8.3')
        ->and($composer['config']['platform']['php'])->toBe('8.3.0')
        ->and($composer['require']['symfony/yaml'])->toBe('^7.4 || ^8.0');
});
