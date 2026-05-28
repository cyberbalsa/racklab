<?php

declare(strict_types=1);

namespace App\Provisioning;

final readonly class SshPublicKeyDetails
{
    public function __construct(
        public string $keyType,
        public string $publicKey,
        public string $fingerprint,
    ) {}
}
