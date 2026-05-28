<?php

declare(strict_types=1);

namespace App\Providers\Proxmox\Models;

use InvalidArgumentException;

final readonly class ProxmoxUpid
{
    public function __construct(
        public string $raw,
        public string $node,
        public int $pid,
        public string $pstart,
        public int $startTime,
        public string $type,
        public string $id,
        public string $user,
    ) {}

    public static function parse(string $upid): self
    {
        $parts = explode(':', $upid);

        if (count($parts) < 8 || $parts[0] !== 'UPID') {
            throw new InvalidArgumentException('Invalid Proxmox UPID.');
        }

        return new self(
            raw: $upid,
            node: $parts[1],
            pid: self::hexToInt($parts[2]),
            pstart: $parts[3],
            startTime: self::hexToInt($parts[4]),
            type: $parts[5],
            id: $parts[6],
            user: $parts[7],
        );
    }

    private static function hexToInt(string $hex): int
    {
        if ($hex === '' || preg_match('/^[0-9a-fA-F]+$/', $hex) !== 1) {
            throw new InvalidArgumentException('Invalid Proxmox UPID hexadecimal field.');
        }

        return (int) hexdec($hex);
    }
}
