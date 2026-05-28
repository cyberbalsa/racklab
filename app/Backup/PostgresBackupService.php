<?php

declare(strict_types=1);

namespace App\Backup;

use RuntimeException;

final readonly class PostgresBackupService
{
    public function __construct(private BackupProcessRunner $processes) {}

    public function dump(PostgresConnectionConfig $connection): string
    {
        $result = $this->processes->run([
            'pg_dump',
            '--format=custom',
            '--no-owner',
            '--no-privileges',
            ...$connection->connectionArguments(),
        ], $connection->environment());

        if (! $result->successful()) {
            throw new RuntimeException($this->message('pg_dump failed', $result));
        }

        return $result->stdout;
    }

    public function restore(PostgresConnectionConfig $connection, string $dump): void
    {
        $render = $this->processes->run([
            'pg_restore',
            '--clean',
            '--if-exists',
            '--no-owner',
            '--no-privileges',
            '--file=-',
        ], input: $dump);

        if (! $render->successful()) {
            throw new RuntimeException($this->message('pg_restore failed', $render));
        }

        $result = $this->processes->run([
            'psql',
            '--single-transaction',
            '--set=ON_ERROR_STOP=1',
            ...$connection->connectionArguments(),
        ], $connection->environment(), $this->sanitizeRestoreSql($render->stdout));

        if (! $result->successful()) {
            throw new RuntimeException($this->message('psql restore failed', $result));
        }
    }

    private function sanitizeRestoreSql(string $sql): string
    {
        return (string) preg_replace('/^SET transaction_timeout = 0;\R/m', '', $sql);
    }

    private function message(string $prefix, BackupProcessResult $result): string
    {
        $details = trim($result->stderr) !== '' ? trim($result->stderr) : trim($result->stdout);

        if ($details === '') {
            return sprintf('%s with exit code %d.', $prefix, $result->exitCode);
        }

        return sprintf('%s with exit code %d: %s', $prefix, $result->exitCode, $details);
    }
}
