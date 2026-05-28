<?php

declare(strict_types=1);

namespace App\Providers\Proxmox\Models;

final readonly class ProxmoxVmCloneRequest
{
    public function __construct(
        public string $node,
        public int $templateVmid,
        public int $targetVmid,
        public string $name,
        public bool $fullClone,
        public ?string $storage = null,
    ) {}

    /**
     * @return array<string, int|string>
     */
    public function formParams(): array
    {
        $params = [
            'newid' => $this->targetVmid,
            'name' => $this->name,
            'full' => $this->fullClone ? 1 : 0,
        ];

        if ($this->storage !== null && $this->storage !== '') {
            $params['storage'] = $this->storage;
        }

        return $params;
    }
}
