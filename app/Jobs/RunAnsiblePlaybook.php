<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Runtime\ContainerManifest;

final class RunAnsiblePlaybook extends RunScriptContainer
{
    public static function containerManifest(): ContainerManifest
    {
        return new ContainerManifest(
            image: 'racklab/ansible-runner:v1',
            networkMode: 'egress-via-proxy',
            cpus: 2.0,
            memory: '4g',
            pidsLimit: 512,
            readOnlyRoot: true,
            tmpfs: ['/tmp'],
            user: '10001:10001',
        );
    }
}
