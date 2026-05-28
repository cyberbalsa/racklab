<?php

declare(strict_types=1);

namespace App\Auth\Jwt;

use App\Domain\Console\ConsoleAccessGrant;

final readonly class ConsoleAccessGrantIssue
{
    public function __construct(
        public ConsoleAccessGrant $grant,
        public string $jwt,
        public string $kid,
    ) {}
}
