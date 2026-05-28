<?php

declare(strict_types=1);

namespace App\Backup;

use RuntimeException;

final class NativeRedisBackupConnection implements RedisBackupConnection
{
    /**
     * @param  resource  $socket
     */
    public function __construct(private $socket) {}

    /**
     * @param  list<string>  $arguments
     */
    public function command(array $arguments): mixed
    {
        $written = fwrite($this->socket, $this->encode($arguments));

        if ($written === false) {
            throw new RuntimeException('Unable to write Redis backup command.');
        }

        return $this->readValue();
    }

    public function close(): void
    {
        fclose($this->socket);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function encode(array $arguments): string
    {
        $payload = '*'.count($arguments)."\r\n";

        foreach ($arguments as $argument) {
            $payload .= '$'.strlen($argument)."\r\n".$argument."\r\n";
        }

        return $payload;
    }

    private function readValue(): mixed
    {
        $prefix = fread($this->socket, 1);

        if ($prefix === false || $prefix === '') {
            throw new RuntimeException('Redis closed the backup connection.');
        }

        return match ($prefix) {
            '+' => $this->readLine(),
            '-' => throw new RuntimeException('Redis backup command failed: '.$this->readLine()),
            ':' => (int) $this->readLine(),
            '$' => $this->readBulkString(),
            '*' => $this->readArray(),
            default => throw new RuntimeException(sprintf('Redis returned unsupported RESP prefix [%s].', $prefix)),
        };
    }

    private function readBulkString(): ?string
    {
        $length = (int) $this->readLine();

        if ($length === -1) {
            return null;
        }

        $value = '';

        while (strlen($value) < $length) {
            $remaining = $length - strlen($value);

            if ($remaining < 1) {
                break;
            }

            $chunk = fread($this->socket, $remaining);

            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Redis closed the backup connection while reading a bulk string.');
            }

            $value .= $chunk;
        }

        $terminator = fread($this->socket, 2);

        if ($terminator !== "\r\n") {
            throw new RuntimeException('Redis returned a malformed bulk string terminator.');
        }

        return $value;
    }

    /**
     * @return list<mixed>|null
     */
    private function readArray(): ?array
    {
        $count = (int) $this->readLine();

        if ($count === -1) {
            return null;
        }

        $values = [];

        for ($index = 0; $index < $count; $index++) {
            $values[] = $this->readValue();
        }

        return $values;
    }

    private function readLine(): string
    {
        $line = fgets($this->socket);

        if ($line === false) {
            throw new RuntimeException('Unable to read Redis backup response.');
        }

        return rtrim($line, "\r\n");
    }
}
