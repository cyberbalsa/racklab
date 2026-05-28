<?php

declare(strict_types=1);

namespace App\Console\Proxy;

use RuntimeException;

final class ProviderConsoleProxyException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason,
    ) {
        parent::__construct($message);
    }
}
