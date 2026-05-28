<?php

declare(strict_types=1);

namespace App\Auth\Jwt;

use App\Models\JwtRevocation;
use Carbon\CarbonImmutable;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Auth\AuthenticationException;
use Throwable;

final readonly class TrackAJwtVerifier
{
    public function __construct(private JwksKeySet $jwks) {}

    public function verify(string $jwt): TrackAJwtClaims
    {
        try {
            $decoded = JWT::decode($jwt, JWK::parseKeySet($this->jwks->toArray(), 'RS256'));
        } catch (Throwable) {
            throw new AuthenticationException('Invalid Track A JWT.');
        }

        /** @var array<string, mixed> $payload */
        $payload = get_object_vars($decoded);
        $jti = $this->stringClaim($payload, 'jti');

        if (JwtRevocation::query()->where('jti', $jti)->exists()) {
            throw new AuthenticationException('Track A JWT has been revoked.');
        }

        $issuer = $this->stringClaim($payload, 'iss');
        $audience = $this->audienceClaim($payload);

        if ($issuer !== $this->jwtConfigString('issuer')) {
            throw new AuthenticationException('Track A JWT issuer is invalid.');
        }

        if ($audience !== $this->jwtConfigString('audience')) {
            throw new AuthenticationException('Track A JWT audience is invalid.');
        }

        return new TrackAJwtClaims(
            issuer: $issuer,
            audience: $audience,
            subjectUserId: $this->stringClaim($payload, 'sub'),
            jti: $jti,
            grantId: $this->stringClaim($payload, 'grant_id'),
            tenantId: $this->stringClaim($payload, 'tenant_id'),
            scopeType: $this->stringClaim($payload, 'scope_type'),
            resourceType: $this->stringClaim($payload, 'resource_type'),
            resourceId: $this->stringClaim($payload, 'resource_id'),
            permissions: $this->stringListClaim($payload, 'permissions'),
            tokenType: $this->stringClaim($payload, 'token_type'),
            expiresAt: CarbonImmutable::createFromTimestamp($this->integerClaim($payload, 'exp')),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringClaim(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        throw new AuthenticationException(sprintf('Track A JWT claim [%s] is invalid.', $key));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function integerClaim(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        throw new AuthenticationException(sprintf('Track A JWT claim [%s] is invalid.', $key));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function stringListClaim(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;

        if (! is_array($value)) {
            throw new AuthenticationException(sprintf('Track A JWT claim [%s] is invalid.', $key));
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_string($item) && trim($item) !== '',
        ));
    }

    private function jwtConfigString(string $key): string
    {
        $value = config(sprintf('racklab.jwt.%s', $key));

        return is_string($value) && trim($value) !== '' ? $value : 'racklab';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function audienceClaim(array $payload): string
    {
        $audience = $payload['aud'] ?? null;

        if (is_string($audience)) {
            return $audience;
        }

        if (is_array($audience)) {
            $first = reset($audience);

            if (is_string($first)) {
                return $first;
            }
        }

        throw new AuthenticationException('Track A JWT audience is invalid.');
    }
}
