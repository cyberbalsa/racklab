<?php

declare(strict_types=1);

namespace App\Auth\Jwt;

use App\Models\SigningKey;
use Firebase\JWT\JWT;
use RuntimeException;

final readonly class JwksKeySet
{
    public function __construct(private SigningKeyRepository $keys) {}

    /**
     * @return array{keys: list<array<string, string>>}
     */
    public function toArray(): array
    {
        return [
            'keys' => array_map(
                $this->toJwk(...),
                $this->keys->publicVerificationKeys(),
            ),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function toJwk(SigningKey $key): array
    {
        $publicKey = openssl_pkey_get_public($key->public_key_pem);

        if ($publicKey === false) {
            throw new RuntimeException('Unable to read RackLab JWT public key.');
        }

        $details = openssl_pkey_get_details($publicKey);
        $rsa = is_array($details) ? ($details['rsa'] ?? null) : null;

        if (! is_array($rsa) || ! isset($rsa['n'], $rsa['e']) || ! is_string($rsa['n']) || ! is_string($rsa['e'])) {
            throw new RuntimeException('RackLab JWT public key is not an RSA key.');
        }

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'kid' => $key->kid,
            'alg' => $key->algorithm,
            'n' => JWT::urlsafeB64Encode($rsa['n']),
            'e' => JWT::urlsafeB64Encode($rsa['e']),
        ];
    }
}
