<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Runtime\ContainerManifest;

final class RunConsoleScript extends RunScriptContainer
{
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
        );
    }
}
