<?php

declare(strict_types=1);

namespace App\Backup;

final readonly class BackupArchiveVerificationResult
{
    public function __construct(
        public bool $valid,
        public ?string $reason = null,
        public ?string $path = null,
    ) {}

    public static function valid(): self
    {
        return new self(valid: true);
    }

    public static function invalid(string $reason, ?string $path = null): self
    {
        return new self(valid: false, reason: $reason, path: $path);
    }
}
