<?php

declare(strict_types=1);

namespace App\Auth\Jwt;

use App\Domain\Console\ConsoleAccessGrant;
use App\Domain\Console\ConsoleKind;
use Carbon\CarbonImmutable;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Auth\AuthenticationException;
use Throwable;
use ValueError;

final readonly class ConsoleAccessGrantVerifier
{
    public function __construct(
        private TrackAJwtVerifier $trackAVerifier,
        private JwksKeySet $jwks,
    ) {}

    public function verify(string $jwt): ConsoleAccessGrant
    {
        $claims = $this->trackAVerifier->verify($jwt);

        if ($claims->tokenType !== ConsoleAccessGrantIssuer::TOKEN_TYPE) {
            throw new AuthenticationException('Track A JWT is not a console grant.');
        }

        if ($claims->resourceType !== 'deployment') {
            throw new AuthenticationException('Console grant resource is not a deployment.');
        }

        if (! in_array(ConsoleAccessGrantIssuer::CONNECT_PERMISSION, $claims->permissions, strict: true)) {
            throw new AuthenticationException('Console grant is missing the connect permission.');
        }

        $payload = $this->decodePayloadForExtraClaims($jwt);
        $consoleKind = $this->readConsoleKind($payload);
        $deploymentId = $this->readDeploymentId($payload);

        if ($deploymentId !== $claims->resourceId) {
            throw new AuthenticationException('Console grant deployment claim does not match the resource id.');
        }

        if (CarbonImmutable::now()->getTimestamp() >= $claims->expiresAt->getTimestamp()) {
            throw new AuthenticationException('Console grant has expired.');
        }

        return new ConsoleAccessGrant(
            grantId: $claims->grantId,
            jti: $claims->jti,
            tenantId: $claims->tenantId,
            deploymentId: $deploymentId,
            consoleKind: $consoleKind,
            expiresAt: $claims->expiresAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayloadForExtraClaims(string $jwt): array
    {
        try {
            $decoded = JWT::decode($jwt, JWK::parseKeySet($this->jwks->toArray(), 'RS256'));
        } catch (Throwable) {
            throw new AuthenticationException('Console grant JWT is invalid.');
        }

        /** @var array<string, mixed> $payload */
        $payload = get_object_vars($decoded);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function readConsoleKind(array $payload): ConsoleKind
    {
        $kind = $payload['console_kind'] ?? null;

        if (! is_string($kind) || trim($kind) === '') {
            throw new AuthenticationException('Console grant is missing the console_kind claim.');
        }

        try {
            return ConsoleKind::fromName($kind);
        } catch (ValueError) {
            throw new AuthenticationException('Console grant console_kind claim is invalid.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function readDeploymentId(array $payload): string
    {
        $deploymentId = $payload['deployment_id'] ?? null;

        if (! is_string($deploymentId) || trim($deploymentId) === '') {
            throw new AuthenticationException('Console grant is missing the deployment_id claim.');
        }

        return $deploymentId;
    }
}
