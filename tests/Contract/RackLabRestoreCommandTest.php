<?php

declare(strict_types=1);

use App\Backup\BackupArchiveWriter;
use App\Backup\BackupProcessResult;
use App\Backup\BackupProcessRunner;
use App\Backup\RedisBackupClient;
use App\Backup\RedisBackupConnection;
use App\Backup\RedisConnectionConfig;

it('restores a file-backed SQLite database from a verified backup archive', function (): void {
    $databasePath = sys_get_temp_dir().'/racklab-restore-db-'.bin2hex(random_bytes(6)).'.sqlite';
    $archivePath = sys_get_temp_dir().'/racklab-restore-command-'.bin2hex(random_bytes(6)).'.zip';

    file_put_contents($databasePath, 'old sqlite bytes');
    (new BackupArchiveWriter)->write($archivePath, [
        'database.sqlite' => 'restored sqlite bytes',
    ], [
        'database_driver' => 'sqlite',
    ]);

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $databasePath,
    ]);

    try {
        $this->artisan('racklab:restore', ['--from' => $archivePath, '--force' => true])
            ->assertExitCode(0);

        expect(file_get_contents($databasePath))->toBe('restored sqlite bytes');
    } finally {
        foreach ([$databasePath, $archivePath] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
});

it('refuses to overwrite an existing SQLite database without force', function (): void {
    $databasePath = sys_get_temp_dir().'/racklab-restore-db-'.bin2hex(random_bytes(6)).'.sqlite';
    $archivePath = sys_get_temp_dir().'/racklab-restore-command-'.bin2hex(random_bytes(6)).'.zip';

    file_put_contents($databasePath, 'old sqlite bytes');
    (new BackupArchiveWriter)->write($archivePath, [
        'database.sqlite' => 'restored sqlite bytes',
    ], [
        'database_driver' => 'sqlite',
    ]);

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $databasePath,
    ]);

    try {
        $this->artisan('racklab:restore', ['--from' => $archivePath])
            ->assertFailed();

        expect(file_get_contents($databasePath))->toBe('old sqlite bytes');
    } finally {
        foreach ([$databasePath, $archivePath] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
});

it('restores PostgreSQL by rendering pg_restore SQL and applying it with psql', function (): void {
    $archivePath = sys_get_temp_dir().'/racklab-restore-command-'.bin2hex(random_bytes(6)).'.zip';
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

            if (($command[0] ?? null) === 'pg_restore') {
                return new BackupProcessResult(
                    exitCode: 0,
                    stdout: "SET statement_timeout = 0;\nSET transaction_timeout = 0;\ncreate table restored(id integer);\n",
                    stderr: '',
                );
            }

            return new BackupProcessResult(exitCode: 0, stdout: '', stderr: '');
        }
    };

    $this->app->instance(BackupProcessRunner::class, $runner);

    (new BackupArchiveWriter)->write($archivePath, [
        'database.pg_dump' => 'postgres dump bytes',
    ], [
        'database_driver' => 'pgsql',
        'database_dump_format' => 'custom',
    ]);

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
        $this->artisan('racklab:restore', ['--from' => $archivePath, '--force' => true])
            ->assertExitCode(0);

        expect($runner->runs)->toHaveCount(2)
            ->and($runner->runs[0]['command'])->toBe([
                'pg_restore',
                '--clean',
                '--if-exists',
                '--no-owner',
                '--no-privileges',
                '--file=-',
            ])
            ->and($runner->runs[0]['environment'])->toBe([])
            ->and($runner->runs[0]['input'])->toBe('postgres dump bytes')
            ->and($runner->runs[1]['command'])->toBe([
                'psql',
                '--single-transaction',
                '--set=ON_ERROR_STOP=1',
                '--dbname=racklab_test',
                '--host=db.example.test',
                '--port=5433',
                '--username=racklab',
            ])
            ->and($runner->runs[1]['environment'])->toBe([
                'PGPASSWORD' => 'secret',
                'PGSSLMODE' => 'require',
            ])
            ->and($runner->runs[1]['input'])->toBe("SET statement_timeout = 0;\ncreate table restored(id integer);\n");
    } finally {
        if (file_exists($archivePath)) {
            unlink($archivePath);
        }
    }
});

it('refuses PostgreSQL restore without force', function (): void {
    $archivePath = sys_get_temp_dir().'/racklab-restore-command-'.bin2hex(random_bytes(6)).'.zip';
    $runner = new class implements BackupProcessRunner
    {
        public bool $called = false;

        /**
         * @param  list<string>  $command
         * @param  array<string, string>  $environment
         */
        public function run(array $command, array $environment = [], ?string $input = null): BackupProcessResult
        {
            $this->called = true;

            return new BackupProcessResult(exitCode: 0, stdout: '', stderr: '');
        }
    };

    $this->app->instance(BackupProcessRunner::class, $runner);

    (new BackupArchiveWriter)->write($archivePath, [
        'database.pg_dump' => 'postgres dump bytes',
    ], [
        'database_driver' => 'pgsql',
        'database_dump_format' => 'custom',
    ]);

    config([
        'database.default' => 'pgsql',
        'database.connections.pgsql.database' => 'racklab_test',
        'database.connections.pgsql.username' => 'racklab',
    ]);

    try {
        $this->artisan('racklab:restore', ['--from' => $archivePath])
            ->assertFailed();

        expect($runner->called)->toBeFalse();
    } finally {
        if (file_exists($archivePath)) {
            unlink($archivePath);
        }
    }
});

it('restores Redis logical dumps included in a verified archive', function (): void {
    $databasePath = sys_get_temp_dir().'/racklab-restore-db-'.bin2hex(random_bytes(6)).'.sqlite';
    $archivePath = sys_get_temp_dir().'/racklab-restore-command-'.bin2hex(random_bytes(6)).'.zip';

    file_put_contents($databasePath, 'old sqlite bytes');

    $redisDump = json_encode([
        'schema_version' => 1,
        'databases' => [
            [
                'index' => 0,
                'keys' => [
                    [
                        'key' => base64_encode('racklab:queue'),
                        'ttl_ms' => 0,
                        'dump' => base64_encode('redis dump bytes'),
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    (new BackupArchiveWriter)->write($archivePath, [
        'database.sqlite' => 'restored sqlite bytes',
        'redis-logical.json' => $redisDump,
    ], [
        'database_driver' => 'sqlite',
        'redis_included' => true,
        'redis_backup_format' => 'logical-dump-v1',
    ]);

    $redisConnection = new class implements RedisBackupConnection
    {
        /**
         * @var list<list<string>>
         */
        public array $commands = [];

        /**
         * @param  list<string>  $arguments
         */
        public function command(array $arguments): mixed
        {
            $this->commands[] = $arguments;

            return 'OK';
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
    ]);

    try {
        $this->artisan('racklab:restore', ['--from' => $archivePath, '--force' => true])
            ->assertExitCode(0);

        expect(file_get_contents($databasePath))->toBe('restored sqlite bytes')
            ->and($redisConnection->commands)->toBe([
                ['SELECT', '0'],
                ['FLUSHDB'],
                ['RESTORE', 'racklab:queue', '0', 'redis dump bytes', 'REPLACE'],
            ]);
    } finally {
        foreach ([$databasePath, $archivePath] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
});
