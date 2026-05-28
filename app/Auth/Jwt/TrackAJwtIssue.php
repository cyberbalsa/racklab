<?php

declare(strict_types=1);

namespace App\Auth\Jwt;

use App\Models\TokenGrant;
use Carbon\CarbonImmutable;

final readonly class TrackAJwtIssue
{
    public function __construct(
        public TokenGrant $grant,
        public string $jwt,
        public string $jti,
        public string $kid,
        public CarbonImmutable $expiresAt,
    ) {}
}
