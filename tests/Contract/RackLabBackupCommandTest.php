<?php

declare(strict_types=1);

use App\Backup\BackupArchiveVerifier;
use App\Backup\BackupProcessResult;
use App\Backup\BackupProcessRunner;
use App\Backup\RedisBackupClient;
use App\Backup\RedisBackupConnection;
use App\Backup\RedisConnectionConfig;

it('backs up a file-backed SQLite database into a verified archive', function (): void {
    $databasePath = sys_get_temp_dir().'/racklab-backup-db-'.bin2hex(random_bytes(6)).'.sqlite';
    $archivePath = sys_get_temp_dir().'/racklab-backup-command-'.bin2hex(random_bytes(6)).'.zip';
    file_put_contents($databasePath, 'sqlite database bytes');

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $databasePath,
    ]);

    try {
        $this->artisan('racklab:backup', ['--to' => $archivePath])
            ->assertExitCode(0);

        $zip = new ZipArchive;
        expect($zip->open($archivePath))->toBeTrue()
            ->and($zip->getFromName('database.sqlite'))->toBe('sqlite database bytes');

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $files = [
            'database.sqlite' => (string) $zip->getFromName('database.sqlite'),
        ];

        $zip->close();

        expect($manifest['metadata']['database_driver'])->toBe('sqlite')
            ->and((new BackupArchiveVerifier)->verify($manifest, $files)->valid)->toBeTrue();
    } finally {
        foreach ([$databasePath, $archivePath] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
});

it('refuses to back up an in-memory SQLite database', function (): void {
    $archivePath = sys_get_temp_dir().'/racklab-backup-command-'.bin2hex(random_bytes(6)).'.zip';

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => ':memory:',
    ]);

    try {
        $this->artisan('racklab:backup', ['--to' => $archivePath])
            ->assertFailed();

        expect(file_exists($archivePath))->toBeFalse();
    } finally {
        if (file_exists($archivePath)) {
            unlink($archivePath);
        }
    }
});

it('backs up PostgreSQL with pg_dump into a verified archive', function (): void {
    $archivePath = sys_get_temp_dir().'/racklab-backup-command-'.bin2hex(random_bytes(6)).'.zip';
    $runner = new class implements BackupProcessRunner
    {
        /**
         * @var list<array{command: list<string>, environment: array<string, string>, input: ?string}>
         */
        public array $runs = [];

        /**
         * @param  list<string>  $command
         * @param  array<string, string>  $environment
         */
        public function run(array $command, array $environment = [], ?string $input = null): BackupProcessResult
        {
            $this->runs[] = [
                'command' => $command,
                'environment' => $environment,
                'input' => $input,
            ];

            return new BackupProcessResult(exitCode: 0, stdout: 'postgres dump bytes', stderr: '');
        }
    };

    $this->app->instance(BackupProcessRunner::class, $runner);

    config([
        'database.default' => 'pgsql',
        'database.connections.pgsql.host' => 'db.example.test',
        'database.connections.pgsql.port' => '5433',
        'database.connections.pgsql.database' => 'racklab_test',
        'database.connections.pgsql.username' => 'racklab',
        'database.connections.pgsql.password' => 'secret',
        'database.connections.pgsql.sslmode' => 'require',
    ]);

    try {
        $this->artisan('racklab:backup', ['--to' => $archivePath])
            ->assertExitCode(0);

        $zip = new ZipArchive;
        expect($zip->open($archivePath))->toBeTrue()
            ->and($zip->getFromName('database.pg_dump'))->toBe('postgres dump bytes');

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $files = [
            'database.pg_dump' => (string) $zip->getFromName('database.pg_dump'),
        ];

        $zip->close();

        expect($manifest['metadata']['database_driver'])->toBe('pgsql')
            ->and($manifest['metadata']['database_dump_format'])->toBe('custom')
            ->and((new BackupArchiveVerifier)->verify($manifest, $files)->valid)->toBeTrue()
            ->and($runner->runs)->toHaveCount(1)
            ->and($runner->runs[0]['command'])->toBe([
                'pg_dump',
                '--format=custom',
                '--no-owner',
                '--no-privileges',
                '--dbname=racklab_test',
                '--host=db.example.test',
                '--port=5433',
                '--username=racklab',
            ])
            ->and($runner->runs[0]['environment'])->toBe([
                'PGPASSWORD' => 'secret',
                'PGSSLMODE' => 'require',
            ]);
    } finally {
        if (file_exists($archivePath)) {
            unlink($archivePath);
        }
    }
});

it('can include a Redis logical dump in the backup archive', function (): void {
    $databasePath = sys_get_temp_dir().'/racklab-backup-db-'.bin2hex(random_bytes(6)).'.sqlite';
    $archivePath = sys_get_temp_dir().'/racklab-backup-command-'.bin2hex(random_bytes(6)).'.zip';
    file_put_contents($databasePath, 'sqlite database bytes');

    $redisConnection = new class implements RedisBackupConnection
    {
        /**
         * @param  list<string>  $arguments
         */
        public function command(array $arguments): mixed
        {
            return match ($arguments[0]) {
                'SELECT' => 'OK',
                'SCAN' => ['0', ['racklab:queue']],
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

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $databasePath,
        'database.redis.default.database' => '0',
        'database.redis.cache.database' => '1',
    ]);

    try {
        $this->artisan('racklab:backup', ['--to' => $archivePath, '--include-redis' => true])
            ->assertExitCode(0);

        $zip = new ZipArchive;
        expect($zip->open($archivePath))->toBeTrue()
            ->and($zip->getFromName('redis-logical.json'))->toBeString();

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $redisDump = json_decode((string) $zip->getFromName('redis-logical.json'), true, flags: JSON_THROW_ON_ERROR);

        $zip->close();

        expect($manifest['metadata']['redis_included'])->toBeTrue()
            ->and($manifest['metadata']['redis_backup_format'])->toBe('logical-dump-v1')
            ->and($redisDump['databases'])->toHaveCount(2);
    } finally {
        foreach ([$databasePath, $archivePath] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
});
