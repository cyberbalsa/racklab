<?php

declare(strict_types=1);

namespace App\Runtime;

use App\Models\ScriptRun;

final readonly class PodmanCommandBuilder
{
    /**
     * @param  list<string>  $binary
     */
    public function __construct(private array $binary = ['podman']) {}

    /**
     * @return list<string>
     */
    public function build(ScriptRun $scriptRun, ContainerManifest $manifest): array
    {
        $command = $this->command(
            'run',
            '--rm',
            '--name='.$this->containerName($scriptRun),
            '--label=racklab.kind=script-run',
            '--label=racklab.script_run_id='.$scriptRun->id,
            '--label=racklab.created_at='.time(),
            '--network='.$this->podmanNetwork($manifest->networkMode),
            '--cpus='.$this->formatCpus($manifest->cpus),
            '--memory='.$manifest->memory,
            '--pids-limit='.$manifest->pidsLimit,
            '--user='.$manifest->user,
            '--cap-drop=all',
            '--security-opt=no-new-privileges',
        );

        if ($manifest->readOnlyRoot) {
            $command[] = '--read-only';
        }

        foreach ($manifest->tmpfs as $tmpfs) {
            $command[] = '--tmpfs='.$tmpfs;
        }

        foreach ($manifest->mounts as $mount) {
            $command[] = '-v';
            $command[] = $mount;
        }

        foreach ($manifest->environment as $name => $value) {
            $command[] = '-e';
            $command[] = $name.'='.$value;
        }

        $command[] = $manifest->image;

        foreach ($scriptRun->command as $argument) {
            $command[] = $argument;
        }

        return $command;
    }

    public function containerName(ScriptRun $scriptRun): string
    {
        return sprintf('racklab-script-%s', $scriptRun->id);
    }

    /**
     * @return list<string>
     */
    public function cleanup(ScriptRun $scriptRun): array
    {
        return $this->cleanupByName($this->containerName($scriptRun));
    }

    /**
     * @return list<string>
     */
    public function cleanupByName(string $containerName): array
    {
        return $this->command(
            'rm',
            '-f',
            '--ignore',
            '--time=0',
            $containerName,
        );
    }

    /**
     * @return list<string>
     */
    public function listScriptContainers(): array
    {
        return $this->command(
            'ps',
            '-a',
            '--filter=label=racklab.kind=script-run',
            '--format=json',
        );
    }

    /**
     * @return list<string>
     */
    private function command(string ...$arguments): array
    {
        $command = $this->binary;

        foreach ($arguments as $argument) {
            $command[] = $argument;
        }

        return $command;
    }

    private function podmanNetwork(string $networkMode): string
    {
        return match ($networkMode) {
            'egress-via-proxy' => 'racklab-egress',
            default => 'none',
        };
    }

    private function formatCpus(float $cpus): string
    {
        return rtrim(rtrim(sprintf('%.2F', $cpus), '0'), '.');
    }
}
