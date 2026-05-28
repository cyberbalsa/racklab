<?php

declare(strict_types=1);

namespace App\Backup;

use RuntimeException;

final readonly class RedisConnectionConfig
{
    public function __construct(
        public string $host,
        public string $port,
        public ?string $username,
        public ?string $password,
    ) {}

    public static function fromLaravelConfig(mixed $config): self
    {
        if (! is_array($config)) {
            throw new RuntimeException('Redis backup/restore requires the default Redis connection to be configured.');
        }

        $url = self::optionalString($config, 'url');

        if ($url !== null) {
            return self::fromUrl($url);
        }

        return new self(
            host: self::optionalString($config, 'host') ?? '127.0.0.1',
            port: self::optionalString($config, 'port') ?? '6379',
            username: self::optionalString($config, 'username'),
            password: self::optionalString($config, 'password'),
        );
    }

    public static function databaseIndex(mixed $config): ?int
    {
        if (! is_array($config)) {
            return null;
        }

        $database = self::optionalString($config, 'database');

        if ($database === null || ! ctype_digit($database)) {
            return null;
        }

        return (int) $database;
    }

    private static function fromUrl(string $url): self
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! is_string($parts['host'] ?? null)) {
            throw new RuntimeException('Redis backup/restore received an invalid Redis URL.');
        }

        $password = self::urlPart($parts, 'pass');
        $username = self::urlPart($parts, 'user');

        return new self(
            host: $parts['host'],
            port: isset($parts['port']) ? (string) $parts['port'] : '6379',
            username: $username,
            password: $password,
        );
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private static function urlPart(array $parts, string $key): ?string
    {
        $value = $parts[$key] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        return rawurldecode($value);
    }

    /**
     * @param  array<mixed, mixed>  $config
     */
    private static function optionalString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
