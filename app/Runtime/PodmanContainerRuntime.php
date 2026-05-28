<?php

declare(strict_types=1);

namespace App\Runtime;

use App\Contracts\ContainerRuntime;

final readonly class PodmanContainerRuntime implements ContainerRuntime
{
    public function __construct(
        private PodmanCommandBuilder $commands,
        private ContainerProcessRunner $processes,
        private int $cleanupTimeoutSeconds = 30,
    ) {}

    public function run(ContainerRunRequest $request): ContainerRunResult
    {
        $containerName = $this->commands->containerName($request->scriptRun);
        $result = $this->processes->run(
            $this->commands->build($request->scriptRun, $request->manifest),
            $request->manifest->timeoutSeconds,
        );
        $metadata = [
            ...$result->metadata,
            'runtime' => 'podman',
            'container_name' => $containerName,
        ];

        if ($result->timedOut) {
            $cleanup = $this->processes->run(
                $this->commands->cleanup($request->scriptRun),
                $this->cleanupTimeoutSeconds,
            );

            $metadata = [
                ...$metadata,
                'cleanup_exit_code' => $cleanup->exitCode,
                'cleanup_timed_out' => $cleanup->timedOut,
            ];
        }

        return new ContainerRunResult(
            exitCode: $result->exitCode,
            stdout: $result->stdout,
            stderr: $result->stderr,
            metadata: $metadata,
            timedOut: $result->timedOut,
            artifacts: $result->artifacts,
        );
    }
}
