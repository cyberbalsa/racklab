<?php

declare(strict_types=1);

namespace App\Providers\Proxmox\Exceptions;

use RuntimeException;

final class ProviderTaskWaitTimeout extends RuntimeException
{
    public function __construct(public readonly string $providerTaskId)
    {
        parent::__construct(sprintf('Stopped waiting for Proxmox task %s.', $providerTaskId));
    }
}
