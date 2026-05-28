<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Runtime\ContainerManifest;

final class RunUserScript extends RunScriptContainer
{
    public static function containerManifest(): ContainerManifest
    {
        return new ContainerManifest(
            image: 'racklab/user-script:v1',
            networkMode: 'none',
            cpus: 2.0,
            memory: '4g',
            pidsLimit: 512,
            readOnlyRoot: true,
            tmpfs: ['/tmp'],
            user: '10001:10001',
        );
    }
}
