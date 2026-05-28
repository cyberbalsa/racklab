<?php

declare(strict_types=1);

namespace App\Providers\Proxmox\Models;

final readonly class ProxmoxVersion
{
    public function __construct(
        public string $version,
        public string $release,
        public ?string $repoId,
        public int $major,
        public int $minor,
        public int $patch,
    ) {}

    public static function fromStrings(string $version, string $release, ?string $repoId): self
    {
        $parts = array_map(
            static fn (string $part): int => is_numeric($part) ? (int) $part : 0,
            explode('.', preg_replace('/[^0-9.].*$/', '', $version) ?? $version),
        );

        return new self(
            version: $version,
            release: $release,
            repoId: $repoId,
            major: $parts[0] ?? 0,
            minor: $parts[1] ?? 0,
            patch: $parts[2] ?? 0,
        );
    }

    public function supportsAtLeast(int $major, int $minor): bool
    {
        return $this->major > $major || ($this->major === $major && $this->minor >= $minor);
    }
}
