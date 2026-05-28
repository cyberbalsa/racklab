<?php

declare(strict_types=1);

use App\Backup\RedisBackupClient;
use App\Backup\RedisBackupConnection;
use App\Backup\RedisConnectionConfig;
use App\Backup\RedisLogicalBackupService;

it('exports selected Redis databases as a binary-safe logical dump', function (): void {
    $connection = new class implements RedisBackupConnection
    {
        public int $selectedDatabase = 0;

        /**
         * @var array<int, array<string, array{dump: string, ttl: int}>>
         */
        public array $databases = [
            0 => [
                'racklab:queue' => ['dump' => "queue\0bytes", 'ttl' => -1],
            ],
            1 => [
                'racklab:cache' => ['dump' => 'cache-bytes', 'ttl' => 5000],
            ],
        ];

        /**
         * @param  list<string>  $arguments
         */
        public function command(array $arguments): mixed
        {
            return match ($arguments[0]) {
                'SELECT' => $this->selectedDatabase = (int) $arguments[1],
                'SCAN' => ['0', array_keys($this->databases[$this->selectedDatabase] ?? [])],
                'DUMP' => $this->databases[$this->selectedDatabase][$arguments[1]]['dump'] ?? null,
                'PTTL' => $this->databases[$this->selectedDatabase][$arguments[1]]['ttl'] ?? -2,
                default => null,
            };
        }

        public function close(): void {}
    };

    $client = new readonly class($connection) implements RedisBackupClient
    {
        public function __construct(private RedisBackupConnection $connection) {}

        public function connect(RedisConnectionConfig $connection): RedisBackupConnection
        {
            return $this->connection;
        }
    };

    $dump = (new RedisLogicalBackupService($client))->dump(new RedisConnectionConfig('127.0.0.1', '6379', null, null), [0, 1]);
    $payload = json_decode($dump, true, flags: JSON_THROW_ON_ERROR);

    expect($payload['schema_version'])->toBe(1)
        ->and($payload['databases'][0]['index'])->toBe(0)
        ->and($payload['databases'][0]['keys'][0]['key'])->toBe(base64_encode('racklab:queue'))
        ->and($payload['databases'][0]['keys'][0]['dump'])->toBe(base64_encode("queue\0bytes"))
        ->and($payload['databases'][0]['keys'][0]['ttl_ms'])->toBe(0)
        ->and($payload['databases'][1]['index'])->toBe(1)
        ->and($payload['databases'][1]['keys'][0]['ttl_ms'])->toBe(5000);
});

it('restores a Redis logical dump by flushing selected databases and replacing keys', function (): void {
    $connection = new class implements RedisBackupConnection
    {
        /**
         * @var list<list<string>>
         */
        public array $commands = [];

        /**
         * @param  list<string>  $arguments
         */
        public function command(array $arguments): mixed
        {
            $this->commands[] = $arguments;

            return 'OK';
        }

        public function close(): void {}
    };

    $client = new readonly class($connection) implements RedisBackupClient
    {
        public function __construct(private RedisBackupConnection $connection) {}

        public function connect(RedisConnectionConfig $connection): RedisBackupConnection
        {
            return $this->connection;
        }
    };

    $payload = json_encode([
        'schema_version' => 1,
        'databases' => [
            [
                'index' => 0,
                'keys' => [
                    [
                        'key' => base64_encode('racklab:queue'),
                        'ttl_ms' => 0,
                        'dump' => base64_encode("queue\0bytes"),
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    (new RedisLogicalBackupService($client))->restore(new RedisConnectionConfig('127.0.0.1', '6379', null, null), $payload);

    expect($connection->commands)->toBe([
        ['SELECT', '0'],
        ['FLUSHDB'],
        ['RESTORE', 'racklab:queue', '0', "queue\0bytes", 'REPLACE'],
    ]);
});
