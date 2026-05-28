<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Backup\BackupArchiveReader;
use App\Backup\PostgresBackupService;
use App\Backup\PostgresConnectionConfig;
use App\Backup\RedisConnectionConfig;
use App\Backup\RedisLogicalBackupService;
use Illuminate\Console\Command;
use RuntimeException;

final class RestoreRackLab extends Command
{
    protected $signature = 'racklab:restore {--from= : Path to a RackLab backup archive.} {--force : Overwrite an existing database file.}';

    protected $description = 'Restore a RackLab backup archive.';

    public function handle(BackupArchiveReader $archives, PostgresBackupService $postgres, RedisLogicalBackupService $redis): int
    {
        $archivePath = $this->archivePath();

        if ($archivePath === null) {
            $this->components->error('The --from option is required.');

            return self::FAILURE;
        }

        $archive = $archives->readVerified($archivePath);
        $metadata = $archive->manifest['metadata'] ?? null;

        if (! is_array($metadata)) {
            $this->components->error('RackLab backup archive is missing database metadata.');

            return self::FAILURE;
        }

        $driver = $metadata['database_driver'] ?? null;

        if ($driver === 'pgsql') {
            $exitCode = $this->restorePostgres($archive->files, $postgres);

            return $exitCode === self::SUCCESS ? $this->restoreRedisIfPresent($archive->files, $redis) : $exitCode;
        }

        if ($driver !== 'sqlite') {
            $this->components->error('RackLab restore currently supports SQLite and PostgreSQL backup archives in this build.');

            return self::FAILURE;
        }

        $exitCode = $this->restoreSqlite($archive->files, $archivePath);

        return $exitCode === self::SUCCESS ? $this->restoreRedisIfPresent($archive->files, $redis) : $exitCode;
    }

    /**
     * @param  array<string, string>  $files
     */
    private function restoreSqlite(array $files, string $archivePath): int
    {
        $databaseContents = $files['database.sqlite'] ?? null;

        if (! is_string($databaseContents)) {
            $this->components->error('RackLab SQLite backup archive is missing database.sqlite.');

            return self::FAILURE;
        }

        $connection = config('database.default');

        if ($connection !== 'sqlite') {
            $this->components->error('RackLab SQLite backup archives can only be restored into a SQLite connection.');

            return self::FAILURE;
        }

        $databasePath = config('database.connections.sqlite.database');

        if (! is_string($databasePath) || $databasePath === '' || $databasePath === ':memory:') {
            $this->components->error('RackLab restore requires a file-backed SQLite database path.');

            return self::FAILURE;
        }

        if (file_exists($databasePath) && $this->option('force') !== true) {
            $this->components->error(sprintf('SQLite database file [%s] already exists. Re-run with --force to overwrite it.', $databasePath));

            return self::FAILURE;
        }

        $temporaryPath = $databasePath.'.restore-tmp';

        if (file_put_contents($temporaryPath, $databaseContents) === false) {
            $this->components->error(sprintf('Unable to write temporary restore file [%s].', $temporaryPath));

            return self::FAILURE;
        }

        if (! rename($temporaryPath, $databasePath)) {
            @unlink($temporaryPath);
            $this->components->error(sprintf('Unable to replace SQLite database file [%s].', $databasePath));

            return self::FAILURE;
        }

        $this->components->info(sprintf('RackLab backup restored from [%s].', $archivePath));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $files
     */
    private function restorePostgres(array $files, PostgresBackupService $postgres): int
    {
        if ($this->option('force') !== true) {
            $this->components->error('PostgreSQL restore is destructive. Re-run with --force to restore into the configured database.');

            return self::FAILURE;
        }

        $connectionName = config('database.default');

        if ($connectionName !== 'pgsql') {
            $this->components->error('RackLab PostgreSQL backup archives can only be restored into a PostgreSQL connection.');

            return self::FAILURE;
        }

        $dump = $files['database.pg_dump'] ?? null;

        if (! is_string($dump)) {
            $this->components->error('RackLab PostgreSQL backup archive is missing database.pg_dump.');

            return self::FAILURE;
        }

        try {
            $postgres->restore(PostgresConnectionConfig::fromLaravelConfig(config('database.connections.pgsql')), $dump);
        } catch (RuntimeException $runtimeException) {
            $this->components->error($runtimeException->getMessage());

            return self::FAILURE;
        }

        $this->components->info('RackLab PostgreSQL backup restored.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $files
     */
    private function restoreRedisIfPresent(array $files, RedisLogicalBackupService $redis): int
    {
        $dump = $files['redis-logical.json'] ?? null;

        if ($dump === null) {
            return self::SUCCESS;
        }

        if ($this->option('force') !== true) {
            $this->components->error('Redis restore is destructive. Re-run with --force to restore included Redis data.');

            return self::FAILURE;
        }

        try {
            $redis->restore(RedisConnectionConfig::fromLaravelConfig(config('database.redis.default')), $dump);
        } catch (RuntimeException $runtimeException) {
            $this->components->error($runtimeException->getMessage());

            return self::FAILURE;
        }

        $this->components->info('RackLab Redis backup restored.');

        return self::SUCCESS;
    }

    private function archivePath(): ?string
    {
        $path = $this->option('from');

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        return $path;
    }
}
