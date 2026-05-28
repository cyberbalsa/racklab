<?php

declare(strict_types=1);

namespace App\Runtime;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final readonly class NativeContainerProcessRunner implements ContainerProcessRunner
{
    /**
     * @param  list<string>  $command
     */
    public function run(array $command, int $timeoutSeconds): ContainerRunResult
    {
        $process = new Process($command);
        $process->setTimeout($timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new ContainerRunResult(
                exitCode: 124,
                stdout: $process->getOutput(),
                stderr: $this->timeoutStderr($process, $timeoutSeconds),
                metadata: [
                    'timed_out' => true,
                    'timeout_seconds' => $timeoutSeconds,
                ],
                timedOut: true,
            );
        }

        return new ContainerRunResult(
            exitCode: $process->getExitCode() ?? 1,
            stdout: $process->getOutput(),
            stderr: $process->getErrorOutput(),
            metadata: [],
        );
    }

    private function timeoutStderr(Process $process, int $timeoutSeconds): string
    {
        $stderr = $process->getErrorOutput();

        if ($stderr !== '') {
            return $stderr;
        }

        return sprintf("Process timed out after %d seconds.\n", $timeoutSeconds);
    }
}
