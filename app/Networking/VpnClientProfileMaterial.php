<?php

declare(strict_types=1);

namespace App\Networking;

final readonly class VpnClientProfileMaterial
{
    public function __construct(
        public string $config,
        public string $certificatePem,
        public string $privateKeyPem,
    ) {}
}
