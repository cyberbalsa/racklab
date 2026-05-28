<?php

declare(strict_types=1);

namespace App\Networking;

final readonly class StackNetworkSpec
{
    public function __construct(
        public string $componentKey,
        public string $key,
        public ?string $offeringId,
        public ?string $offeringSlug,
    ) {}

    /**
     * @return array{component_key: string, key: string, offering_id: ?string, offering_slug: ?string}
     */
    public function toArray(): array
    {
        return [
            'component_key' => $this->componentKey,
            'key' => $this->key,
            'offering_id' => $this->offeringId,
            'offering_slug' => $this->offeringSlug,
        ];
    }
}
