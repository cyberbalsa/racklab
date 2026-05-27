<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

final readonly class AccessDecision
{
    /**
     * @param  list<string>  $provenance
     */
    public function __construct(
        public bool $allowed,
        public ?AccessDenyReason $denyReason,
        public array $provenance,
    ) {}
}
