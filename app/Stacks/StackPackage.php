<?php

declare(strict_types=1);

namespace App\Stacks;

/**
 * A built RackLab Stack Package ready to stream as a download: the OVA-style
 * zip archive bytes plus the suggested filename.
 */
final readonly class StackPackage
{
    public function __construct(
        public string $filename,
        public string $bytes,
    ) {}
}
