<?php

declare(strict_types=1);

namespace App\Auth\Tokens;

use App\Models\TokenGrant;

final readonly class TrackBTokenIssue
{
    public function __construct(
        public TokenGrant $grant,
        public string $plainTextToken,
    ) {}
}
