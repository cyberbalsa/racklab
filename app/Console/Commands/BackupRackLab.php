<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Backup\BackupArchiveWriter;
use App\Backup\PostgresBackupService;
use App\Backup\PostgresConnectionConfig;
use App\Backup\RedisConnectionConfig;
use App\Backup\RedisLogicalBackupService;
use Illuminate\Console\Command;
use RuntimeException;

final class BackupRackLab extends Command
{
    protected $signature = 'racklab:backup {--to= : Path to write the RackLab backup archive.} {--include-redis : Include a logical Redis keyspace backup.}';

    protected $description = 'Create a RackLab backup archive.';

    public function handle(BackupArchiveWriter $archives, PostgresBackupService $postgres, RedisLogicalBackupService $redis): int
    {
        $archivePath = $this->archivePath();

        if ($archivePath === null) {
            $this->components->error('The --to option is required.');

            return self::FAILURE;
        }

        $connection = config('database.default');

        if (! is_string($connection)) {
            $this->components->error('RackLab backup requires a named database connection.');

            return self::FAILURE;
        }

        $extraFiles = [];
        $extraMetadata = [];

        if ($this->option('include-redis') === true) {
            try {
                $extraFiles['redis-logical.json'] = $redis->dump(
                    RedisConnectionConfig::fromLaravelConfig(config('database.redis.default')),
                    RedisLogicalBackupService::configuredDatabaseIndexes(),
                );
                $extraMetadata['redis_included'] = true;
                $extraMetadata['redis_backup_format'] = 'logical-dump-v1';
            } catch (RuntimeException $runtimeException) {
                $this->components->error($runtimeException->getMessage());

                return self::FAILURE;
            }
        }

        if ($connection === 'pgsql') {
            return $this->backupPostgres($archives, $postgres, $archivePath, $extraFiles, $extraMetadata);
        }

        if ($connection !== 'sqlite') {
            $this->components->error(sprintf('RackLab backup currently supports SQLite and PostgreSQL in this build; [%s] is not supported yet.', $connection));

            return self::FAILURE;
        }

        return $this->backupSqlite($archives, $archivePath, $extraFiles, $extraMetadata);
    }

    /**
     * @param  array<string, string>  $extraFiles
     * @param  array<string, mixed>  $extraMetadata
     */
    private function backupSqlite(BackupArchiveWriter $archives, string $archivePath, array $extraFiles, array $extraMetadata): int
    {
        $databasePath = config('database.connections.sqlite.database');

        if (! is_string($databasePath) || $databasePath === '' || $databasePath === ':memory:') {
            $this->components->error('RackLab backup requires a file-backed SQLite database; :memory: cannot be backed up.');

            return self::FAILURE;
        }

        if (! is_file($databasePath)) {
            $this->components->error(sprintf('SQLite database file [%s] does not exist.', $databasePath));

            return self::FAILURE;
        }

        $databaseContents = file_get_contents($databasePath);

        if ($databaseContents === false) {
            $this->components->error(sprintf('Unable to read SQLite database file [%s].', $databasePath));

            return self::FAILURE;
        }

        $archives->write($archivePath, [
            'database.sqlite' => $databaseContents,
            ...$extraFiles,
        ], [
            'database_driver' => 'sqlite',
            ...$extraMetadata,
        ]);

        $this->components->info(sprintf('RackLab backup written to [%s].', $archivePath));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $extraFiles
     * @param  array<string, mixed>  $extraMetadata
     */
    private function backupPostgres(BackupArchiveWriter $archives, PostgresBackupService $postgres, string $archivePath, array $extraFiles, array $extraMetadata): int
    {
        try {
            $dump = $postgres->dump(PostgresConnectionConfig::fromLaravelConfig(config('database.connections.pgsql')));
        } catch (RuntimeException $runtimeException) {
            $this->components->error($runtimeException->getMessage());

            return self::FAILURE;
        }

        $archives->write($archivePath, [
            'database.pg_dump' => $dump,
            ...$extraFiles,
        ], [
            'database_driver' => 'pgsql',
            'database_dump_format' => 'custom',
            ...$extraMetadata,
        ]);

        $this->components->info(sprintf('RackLab backup written to [%s].', $archivePath));

        return self::SUCCESS;
    }

    private function archivePath(): ?string
    {
        $path = $this->option('to');

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        return $path;
    }
}
