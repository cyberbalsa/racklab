<?php

declare(strict_types=1);

namespace App\Providers\Proxmox\Exceptions;

use RuntimeException;

final class ProviderTaskFailed extends RuntimeException
{
    public function __construct(public readonly string $providerTaskId, public readonly string $exitStatus)
    {
        parent::__construct(sprintf('Proxmox task %s failed with exit status %s.', $providerTaskId, $exitStatus));
    }
}
