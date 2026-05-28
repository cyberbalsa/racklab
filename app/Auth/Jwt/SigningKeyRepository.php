<?php

declare(strict_types=1);

namespace App\Auth\Jwt;

use App\Models\SigningKey;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class SigningKeyRepository
{
    public function current(): SigningKey
    {
        /** @var SigningKey|null $key */
        $key = SigningKey::query()
            ->where('status', 'current')
            ->whereNull('revoked_at')
            ->latest('created_at')
            ->first();

        return $key ?? $this->createCurrent();
    }

    public function createCurrent(): SigningKey
    {
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($privateKey === false) {
            throw new RuntimeException('Unable to generate RackLab JWT signing key.');
        }

        $privateKeyPem = '';

        if (! openssl_pkey_export($privateKey, $privateKeyPem)) {
            throw new RuntimeException('Unable to export RackLab JWT private key.');
        }

        $details = openssl_pkey_get_details($privateKey);

        if (! is_array($details) || ! isset($details['key']) || ! is_string($details['key'])) {
            throw new RuntimeException('Unable to export RackLab JWT public key.');
        }

        /** @var SigningKey $key */
        $key = SigningKey::query()->create([
            'kid' => (string) Str::ulid(),
            'algorithm' => 'RS256',
            'status' => 'current',
            'public_key_pem' => $details['key'],
            'private_key_pem' => $privateKeyPem,
            'not_before' => now(),
        ]);

        return $key;
    }

    /**
     * @return list<SigningKey>
     */
    public function publicVerificationKeys(): array
    {
        return array_values(SigningKey::query()
            ->whereIn('status', ['current', 'previous'])
            ->whereNull('revoked_at')
            ->orderByDesc('created_at')
            ->get()
            ->all());
    }
}
