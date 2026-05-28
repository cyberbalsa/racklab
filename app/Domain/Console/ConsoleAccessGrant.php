<?php

declare(strict_types=1);

namespace App\Domain\Console;

use Carbon\CarbonImmutable;

final readonly class ConsoleAccessGrant
{
    public function __construct(
        public string $grantId,
        public string $jti,
        public string $tenantId,
        public string $deploymentId,
        public ConsoleKind $consoleKind,
        public CarbonImmutable $expiresAt,
    ) {}

    public function isExpired(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $this->expiresAt->getTimestamp() <= $now->getTimestamp();
    }
}
