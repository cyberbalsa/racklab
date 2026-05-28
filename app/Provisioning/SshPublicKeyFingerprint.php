<?php

declare(strict_types=1);

namespace App\Provisioning;

use InvalidArgumentException;

final readonly class SshPublicKeyFingerprint
{
    public function parse(string $publicKey): SshPublicKeyDetails
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $publicKey) ?? '');
        $parts = explode(' ', $normalized);

        if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException('SSH public key must include a key type and base64 body.');
        }

        $decoded = base64_decode($parts[1], strict: true);

        if ($decoded === false || $decoded === '') {
            throw new InvalidArgumentException('SSH public key body is not valid base64.');
        }

        return new SshPublicKeyDetails(
            keyType: $parts[0],
            publicKey: $normalized,
            fingerprint: 'SHA256:'.rtrim(strtr(base64_encode(hash('sha256', $decoded, binary: true)), '+/', '-_'), '='),
        );
    }
}
