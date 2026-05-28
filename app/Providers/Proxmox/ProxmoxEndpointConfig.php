<?php

declare(strict_types=1);

namespace App\Providers\Proxmox;

use InvalidArgumentException;

final readonly class ProxmoxEndpointConfig
{
    public function __construct(
        public string $baseUri,
        public string $apiTokenId,
        public string $apiTokenSecret,
        public bool|string $verifySsl,
        public float $connectTimeoutSeconds,
        public float $readTimeoutSeconds,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $baseUri = self::requiredString($config, 'base_uri');
        $apiTokenId = self::requiredString($config, 'api_token_id');
        $apiTokenSecret = self::requiredString($config, 'api_token_secret');
        $verifySsl = self::verifySsl($config);

        if ($verifySsl === false && ! app()->environment('local')) {
            throw new InvalidArgumentException('verify_ssl=false is only allowed in local development.');
        }

        return new self(
            baseUri: rtrim($baseUri, '/'),
            apiTokenId: $apiTokenId,
            apiTokenSecret: $apiTokenSecret,
            verifySsl: $verifySsl,
            connectTimeoutSeconds: self::floatValue($config['connect_timeout'] ?? 5.0),
            readTimeoutSeconds: self::floatValue($config['read_timeout'] ?? 30.0),
        );
    }

    public function authorizationHeader(): string
    {
        return sprintf('PVEAPIToken=%s=%s', $this->apiTokenId, $this->apiTokenSecret);
    }

    /**
     * @return array<string, mixed>
     */
    public function guzzleOptions(): array
    {
        return [
            'connect_timeout' => $this->connectTimeoutSeconds,
            'timeout' => $this->readTimeoutSeconds,
            'verify' => $this->verifySsl,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => $this->authorizationHeader(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function requiredString(array $config, string $key): string
    {
        $value = $config[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Missing Proxmox %s configuration.', $key));
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function verifySsl(array $config): bool|string
    {
        $caBundle = $config['ca_bundle'] ?? null;

        if (is_string($caBundle) && trim($caBundle) !== '') {
            return trim($caBundle);
        }

        $verifySsl = $config['verify_ssl'] ?? true;

        if (is_bool($verifySsl)) {
            return $verifySsl;
        }

        return filter_var($verifySsl, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }

    private static function floatValue(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }
}
