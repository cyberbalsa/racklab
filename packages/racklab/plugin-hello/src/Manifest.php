<?php

declare(strict_types=1);

namespace Racklab\PluginHello;

final readonly class Manifest
{
    public function slug(): string
    {
        return 'racklab/plugin-hello';
    }

    public function name(): string
    {
        return 'RackLab Hello Plugin';
    }

    public function description(): string
    {
        return 'A reference plugin for RackLab plugin lifecycle and contract tests.';
    }
}
