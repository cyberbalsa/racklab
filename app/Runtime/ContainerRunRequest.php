<?php

declare(strict_types=1);

namespace App\Runtime;

use App\Models\ScriptRun;

final readonly class ContainerRunRequest
{
    public function __construct(
        public ScriptRun $scriptRun,
        public ContainerManifest $manifest,
    ) {}
}
