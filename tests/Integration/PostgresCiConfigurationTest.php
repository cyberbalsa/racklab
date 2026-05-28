<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('defines a PostgreSQL-backed CI smoke for migrations and persistence contracts', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/code-ci.yml'));
    $job = $workflow['jobs']['postgres-smoke'];
    $env = $job['env'];
    $service = $job['services']['postgres'];
    $steps = array_column($job['steps'], 'run', 'name');

    expect($job['runs-on'])->toBe('ubuntu-24.04')
        ->and($service['image'])->toBe('postgres:16')
        ->and($service['env']['POSTGRES_DB'])->toBe('racklab_ci')
        ->and($env['DB_CONNECTION'])->toBe('pgsql')
        ->and($env['DB_HOST'])->toBe('127.0.0.1')
        ->and($env['DB_DATABASE'])->toBe('racklab_ci')
        ->and($env['DB_USERNAME'])->toBe('racklab')
        ->and($env['DB_PASSWORD'])->toBe('racklab')
        ->and($steps['Wait for PostgreSQL'])->toContain('pg_isready')
        ->and($steps['Run RackLab migrations'])->toBe('php artisan racklab:migrate')
        ->and($steps['PostgreSQL contract smoke'])->toBe('composer pest:contract -- tests/Contract/TenancyPersistenceTest.php tests/Contract/RbacPersistenceTest.php tests/Contract/AuditHashChainTest.php tests/Contract/TenantScopeAndCrossTenantFetchTest.php');
});
