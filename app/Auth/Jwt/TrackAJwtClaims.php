<?php

declare(strict_types=1);

namespace App\Auth\Jwt;

use Carbon\CarbonImmutable;

final readonly class TrackAJwtClaims
{
    public const string REQUEST_ATTRIBUTE = 'racklab.track_a_claims';

    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        public string $issuer,
        public string $audience,
        public string $subjectUserId,
        public string $jti,
        public string $grantId,
        public string $tenantId,
        public string $scopeType,
        public string $resourceType,
        public string $resourceId,
        public array $permissions,
        public string $tokenType,
        public CarbonImmutable $expiresAt,
    ) {}
}
