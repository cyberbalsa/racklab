<?php

declare(strict_types=1);

use App\Backup\RedisBackupClient;
use App\Backup\RedisBackupConnection;
use App\Backup\RedisConnectionConfig;
use App\Models\Deployment;
use App\Models\DeploymentOperation;
use App\Models\DeploymentResource;
use App\Models\ProviderTask;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->artisan('migrate:fresh', ['--force' => true])
        ->assertExitCode(0);
});

it('runs the fake-provider worker restart smoke without leaving stuck provider tasks', function (): void {
    $this->artisan('racklab:ops-smoke', ['--cycles' => '2'])
        ->assertExitCode(0);

    expect(Deployment::query()->where('state', 'running')->count())->toBe(1)
        ->and(DeploymentOperation::query()->where('state', 'complete')->count())->toBe(2)
        ->and(DeploymentResource::query()->where('state', 'running')->count())->toBe(2)
        ->and(ProviderTask::query()->where('state', 'complete')->count())->toBe(2)
        ->and(ProviderTask::query()->whereIn('state', ['pending', 'running'])->count())->toBe(0);
});

it('can write a per-cycle backup archive during the ops smoke', function (): void {
    $databasePath = sys_get_temp_dir().'/racklab-ops-smoke-'.bin2hex(random_bytes(6)).'.sqlite';
    $backupDir = sys_get_temp_dir().'/racklab-ops-smoke-backups-'.bin2hex(random_bytes(6));

    touch($databasePath);

    $originalConnection = config('database.default');
    $originalDatabase = config('database.connections.sqlite.database');

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $databasePath,
    ]);
    DB::purge();

    try {
        $this->artisan('migrate:fresh', ['--force' => true])
            ->assertExitCode(0);

        $this->artisan('racklab:ops-smoke', ['--cycles' => '1', '--backup-dir' => $backupDir])
            ->assertExitCode(0);

        expect($backupDir.'/racklab-ops-smoke-cycle-001.zip')->toBeFile();
    } finally {
        config([
            'database.default' => $originalConnection,
            'database.connections.sqlite.database' => $originalDatabase,
        ]);
        DB::purge();

        foreach (glob($backupDir.'/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($backupDir)) {
            rmdir($backupDir);
        }

        if (file_exists($databasePath)) {
            unlink($databasePath);
        }
    }
});

it('can include Redis logical dumps in per-cycle ops-smoke backups', function (): void {
    $databasePath = sys_get_temp_dir().'/racklab-ops-smoke-'.bin2hex(random_bytes(6)).'.sqlite';
    $backupDir = sys_get_temp_dir().'/racklab-ops-smoke-backups-'.bin2hex(random_bytes(6));

    touch($databasePath);

    $redisConnection = new class implements RedisBackupConnection
    {
        /**
         * @param  list<string>  $arguments
         */
        public function command(array $arguments): mixed
        {
            return match ($arguments[0]) {
                'SELECT' => 'OK',
                'SCAN' => ['0', ['racklab:ops-smoke']],
                'PTTL' => -1,
                'DUMP' => 'redis dump bytes',
                default => null,
            };
        }

        public function close(): void {}
    };

    $this->app->instance(RedisBackupClient::class, new readonly class($redisConnection) implements RedisBackupClient
    {
        public function __construct(private RedisBackupConnection $connection) {}

        public function connect(RedisConnectionConfig $connection): RedisBackupConnection
        {
            return $this->connection;
        }
    });

    $originalConnection = config('database.default');
    $originalDatabase = config('database.connections.sqlite.database');

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $databasePath,
        'database.redis.default.database' => '0',
        'database.redis.cache.database' => '1',
    ]);
    DB::purge();

    try {
        $this->artisan('migrate:fresh', ['--force' => true])
            ->assertExitCode(0);

        $this->artisan('racklab:ops-smoke', [
            '--cycles' => '1',
            '--backup-dir' => $backupDir,
            '--include-redis-backup' => true,
        ])->assertExitCode(0);

        $zip = new ZipArchive;

        expect($zip->open($backupDir.'/racklab-ops-smoke-cycle-001.zip'))->toBeTrue()
            ->and($zip->getFromName('redis-logical.json'))->toBeString();

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $zip->close();

        expect($manifest['metadata']['redis_included'])->toBeTrue();
    } finally {
        config([
            'database.default' => $originalConnection,
            'database.connections.sqlite.database' => $originalDatabase,
        ]);
        DB::purge();

        foreach (glob($backupDir.'/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($backupDir)) {
            rmdir($backupDir);
        }

        if (file_exists($databasePath)) {
            unlink($databasePath);
        }
    }
});
