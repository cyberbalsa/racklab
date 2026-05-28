<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('defines a manual PostgreSQL-backed browser release smoke workflow', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/release-smoke-ci.yml'));
    $job = $workflow['jobs']['release-smoke'];
    $env = $job['env'];
    $postgres = $job['services']['postgres'];
    $redis = $job['services']['redis'];
    $steps = array_column($job['steps'], 'run', 'name');
    $uses = array_column($job['steps'], 'uses', 'name');
    $with = array_column($job['steps'], 'with', 'name');

    expect($workflow['on'])->toHaveKey('workflow_dispatch')
        ->and($job['runs-on'])->toBe('ubuntu-24.04')
        ->and($postgres['image'])->toBe('postgres:16')
        ->and($redis['image'])->toBe('redis:7')
        ->and($env['DB_CONNECTION'])->toBe('pgsql')
        ->and($env['REDIS_HOST'])->toBe('127.0.0.1')
        ->and($env['SESSION_DRIVER'])->toBe('file')
        ->and($env['RACKLAB_CONTAINER_RUNTIME'])->toBe('fake')
        ->and($with['Setup PHP']['extensions'])->toContain('redis')
        ->and($steps['Install PostgreSQL and Redis client tools'])->toContain('postgresql-client redis-tools')
        ->and($steps['Run RackLab migrations'])->toBe('php artisan racklab:migrate')
        ->and($steps['Baseline ops smoke'])->toContain('php artisan racklab:ops-smoke --cycles=3 --backup-dir=/tmp/racklab-ops-smoke-backups --include-redis-backup')
        ->and($steps['PostgreSQL and Redis backup restore smoke'])->toContain('php artisan racklab:backup --to=/tmp/racklab-release-smoke.zip --include-redis')
        ->and($steps['PostgreSQL and Redis backup restore smoke'])->toContain('php artisan migrate:fresh --force')
        ->and($steps['PostgreSQL and Redis backup restore smoke'])->toContain('php artisan racklab:restore --from=/tmp/racklab-release-smoke.zip --force')
        ->and($steps['PostgreSQL and Redis backup restore smoke'])->toContain('select count(*) from deployments where state = ?')
        ->and($steps['PostgreSQL and Redis backup restore smoke'])->toContain('redis-cli')
        ->and($steps['PostgreSQL and Redis backup restore smoke'])->toContain('php artisan racklab:verify-audit-chain')
        ->and($steps['RackLab security config'])->toBe('composer security:racklab')
        ->and($steps['Wait for Laravel server'])->toContain('/readyz')
        ->and($steps['Pest browser smoke'])->toBe('composer pest:browser')
        ->and($steps['Pa11y smoke'])->toBe('npm run a11y')
        ->and($uses['Upload release-smoke failure artifacts'])->toBe('actions/upload-artifact@v4');
});
