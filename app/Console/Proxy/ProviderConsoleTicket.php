<?php

declare(strict_types=1);

namespace App\Console\Proxy;

use App\Domain\Console\ConsoleKind;
use Carbon\CarbonImmutable;

final readonly class ProviderConsoleTicket
{
    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public function __construct(
        public string $ticket,
        public string $websocketUrl,
        public ConsoleKind $consoleKind,
        public CarbonImmutable $expiresAt,
        public array $metadata = [],
    ) {}
}
