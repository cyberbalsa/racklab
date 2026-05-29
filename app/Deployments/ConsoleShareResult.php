<?php

declare(strict_types=1);

namespace App\Deployments;

/**
 * Outcome of a console share: how many guests were newly granted, how many
 * already had access, and which emails matched no tenant member.
 */
final readonly class ConsoleShareResult
{
    /**
     * @param  list<string>  $missing
     */
    public function __construct(
        public int $shared,
        public int $alreadyShared,
        public array $missing,
    ) {}
}
