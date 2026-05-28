<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Runtime\ContainerManifest;

final class RunConsoleScript extends RunScriptContainer
{
    /*
     * Console jobs can drive interactive sessions up to one hour; the parent's
     * $timeout=330 would kill them at 5.5 minutes. 3630 = 3600s console run +
     * 30s cleanup margin. Still strictly less than Redis retry_after=3700 so a
     * crashed worker's job won't be redelivered while the original is still
     * running. See docs/superpowers/specs/2026-05-28-horizon-and-supply-chain-design.md.
     */
    public int $timeout = 3630;

    public function __construct(string $tenantId, string $scriptRunId)
    {
        parent::__construct($tenantId, $scriptRunId);
        $this->onQueue('console-worker');
    }

    public static function containerManifest(): ContainerManifest
    {
        return new ContainerManifest(
            image: 'racklab/console-script:v1',
            networkMode: 'via-console-proxy',
            cpus: 2.0,
            memory: '4g',
            pidsLimit: 512,
            readOnlyRoot: true,
            tmpfs: ['/tmp'],
            user: '10001:10001',
            mounts: ['/run/racklab/console-proxy.sock:/run/console-proxy.sock:ro'],
            timeoutSeconds: 3600,
        );
    }
}
