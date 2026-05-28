<?php

declare(strict_types=1);

namespace App\Providers\Proxmox\Models;

use InvalidArgumentException;

final readonly class ProxmoxTermProxyRequest
{
    public function __construct(
        public string $node,
        public int $vmid,
        public ?string $serial = null,
    ) {
        if ($this->serial !== null && ! in_array($this->serial, ['serial0', 'serial1', 'serial2', 'serial3'], strict: true)) {
            throw new InvalidArgumentException('Unsupported Proxmox termproxy serial value.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function formParams(): array
    {
        $params = [];

        if ($this->serial !== null) {
            $params['serial'] = $this->serial;
        }

        return $params;
    }
}
