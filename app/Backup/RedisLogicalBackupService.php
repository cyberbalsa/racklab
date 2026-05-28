<?php

declare(strict_types=1);

namespace App\Backup;

use RuntimeException;

final readonly class RedisLogicalBackupService
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(private RedisBackupClient $redis) {}

    /**
     * @param  list<int>  $databases
     */
    public function dump(RedisConnectionConfig $connection, array $databases): string
    {
        $session = $this->redis->connect($connection);

        try {
            $payload = [
                'schema_version' => self::SCHEMA_VERSION,
                'databases' => [],
            ];

            foreach ($databases as $database) {
                $session->command(['SELECT', (string) $database]);

                $payload['databases'][] = [
                    'index' => $database,
                    'keys' => $this->dumpDatabase($session),
                ];
            }

            return json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        } finally {
            $session->close();
        }
    }

    public function restore(RedisConnectionConfig $connection, string $dump): void
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($dump, true, flags: JSON_THROW_ON_ERROR);

        if (($payload['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new RuntimeException('Redis backup uses an unsupported logical dump schema version.');
        }

        $databases = $payload['databases'] ?? null;

        if (! is_array($databases)) {
            throw new RuntimeException('Redis backup is missing database entries.');
        }

        $session = $this->redis->connect($connection);

        try {
            foreach ($databases as $database) {
                if (! is_array($database)) {
                    throw new RuntimeException('Redis backup contains a malformed database entry.');
                }

                $this->restoreDatabase($session, $database);
            }
        } finally {
            $session->close();
        }
    }

    /**
     * @return list<int>
     */
    public static function configuredDatabaseIndexes(): array
    {
        $indexes = [];

        foreach ([config('database.redis.default'), config('database.redis.cache')] as $connection) {
            $index = RedisConnectionConfig::databaseIndex($connection);

            if ($index !== null) {
                $indexes[] = $index;
            }
        }

        $indexes = array_values(array_unique($indexes));
        sort($indexes);

        return $indexes === [] ? [0] : $indexes;
    }

    /**
     * @return list<array{key: string, ttl_ms: int, dump: string}>
     */
    private function dumpDatabase(RedisBackupConnection $session): array
    {
        $cursor = '0';
        $keys = [];

        do {
            $response = $session->command(['SCAN', $cursor]);

            if (! is_array($response) || ! is_string($response[0] ?? null) || ! is_array($response[1] ?? null)) {
                throw new RuntimeException('Redis SCAN returned an unexpected response.');
            }

            $cursor = $response[0];

            foreach ($response[1] as $key) {
                if (! is_string($key)) {
                    continue;
                }

                $entry = $this->dumpKey($session, $key);

                if ($entry !== null) {
                    $keys[] = $entry;
                }
            }
        } while ($cursor !== '0');

        return $keys;
    }

    /**
     * @return array{key: string, ttl_ms: int, dump: string}|null
     */
    private function dumpKey(RedisBackupConnection $session, string $key): ?array
    {
        $ttl = $session->command(['PTTL', $key]);

        if (! is_int($ttl) || $ttl === -2) {
            return null;
        }

        $dump = $session->command(['DUMP', $key]);

        if (! is_string($dump)) {
            return null;
        }

        return [
            'key' => base64_encode($key),
            'ttl_ms' => max(0, $ttl),
            'dump' => base64_encode($dump),
        ];
    }

    /**
     * @param  array<mixed, mixed>  $database
     */
    private function restoreDatabase(RedisBackupConnection $session, array $database): void
    {
        $index = $database['index'] ?? null;
        $keys = $database['keys'] ?? null;

        if (! is_int($index) || ! is_array($keys)) {
            throw new RuntimeException('Redis backup contains a malformed database entry.');
        }

        $session->command(['SELECT', (string) $index]);
        $session->command(['FLUSHDB']);

        foreach ($keys as $key) {
            if (! is_array($key)) {
                throw new RuntimeException('Redis backup contains a malformed key entry.');
            }

            $this->restoreKey($session, $key);
        }
    }

    /**
     * @param  array<mixed, mixed>  $key
     */
    private function restoreKey(RedisBackupConnection $session, array $key): void
    {
        $encodedKey = $key['key'] ?? null;
        $ttl = $key['ttl_ms'] ?? null;
        $encodedDump = $key['dump'] ?? null;

        if (! is_string($encodedKey) || ! is_int($ttl) || ! is_string($encodedDump)) {
            throw new RuntimeException('Redis backup contains a malformed key entry.');
        }

        $decodedKey = base64_decode($encodedKey, strict: true);
        $decodedDump = base64_decode($encodedDump, strict: true);

        if (! is_string($decodedKey) || ! is_string($decodedDump)) {
            throw new RuntimeException('Redis backup contains invalid base64 key data.');
        }

        $session->command(['RESTORE', $decodedKey, (string) $ttl, $decodedDump, 'REPLACE']);
    }
}
