<?php

declare(strict_types=1);

namespace App\Backup;

use Symfony\Component\Process\Process;

final readonly class NativeBackupProcessRunner implements BackupProcessRunner
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public function run(array $command, array $environment = [], ?string $input = null): BackupProcessResult
    {
        $process = new Process(
            command: $command,
            env: $environment === [] ? null : $environment,
            input: $input,
            timeout: 300,
        );

        $process->run();

        return new BackupProcessResult(
            exitCode: $process->getExitCode() ?? 1,
            stdout: $process->getOutput(),
            stderr: $process->getErrorOutput(),
        );
    }
}
