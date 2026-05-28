<?php

declare(strict_types=1);

namespace Racklab\ConsoleProxmox;

final readonly class Manifest
{
    public function slug(): string
    {
        return 'racklab/console-proxmox';
    }

    public function name(): string
    {
        return 'RackLab Proxmox Console';
    }

    public function description(): string
    {
        return 'Provides the Proxmox console capability (console:proxmox:v1).';
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return ['console:proxmox:v1'];
    }
}
