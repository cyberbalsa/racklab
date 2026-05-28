<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use DateTimeInterface;
use JsonException;

final readonly class AuditHash
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    public function calculate(?string $previousHash, array $payload): string
    {
        return hash(
            'sha256',
            ($previousHash ?? '').json_encode(
                $this->canonicalize($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value);

        return array_map($this->canonicalize(...), $value);
    }
}
