<?php

declare(strict_types=1);

namespace App\Plugins;

final readonly class PluginDescriptor
{
    public function __construct(
        public string $slug,
        public string $packageName,
        public string $version,
        public string $serviceProvider,
        public ?string $manifestClass,
        public string $name,
        public ?string $description,
    ) {}
}
