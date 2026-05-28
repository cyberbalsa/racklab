<?php

declare(strict_types=1);

namespace App\Backup;

use RuntimeException;

final readonly class NativeRedisBackupClient implements RedisBackupClient
{
    public function connect(RedisConnectionConfig $connection): RedisBackupConnection
    {
        $socket = @stream_socket_client(
            address: sprintf('tcp://%s:%s', $connection->host, $connection->port),
            error_code: $errorCode,
            error_message: $errorMessage,
            timeout: 5.0,
        );

        if ($socket === false) {
            throw new RuntimeException(sprintf('Unable to connect to Redis at [%s:%s]: %s (%d).', $connection->host, $connection->port, $errorMessage, $errorCode));
        }

        stream_set_timeout($socket, 30);

        $session = new NativeRedisBackupConnection($socket);

        if ($connection->password !== null && $connection->username !== null) {
            $session->command(['AUTH', $connection->username, $connection->password]);
        } elseif ($connection->password !== null) {
            $session->command(['AUTH', $connection->password]);
        }

        return $session;
    }
}
