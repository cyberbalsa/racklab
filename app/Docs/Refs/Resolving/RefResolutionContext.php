<?php

declare(strict_types=1);

namespace App\Docs\Refs\Resolving;

use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;

/**
 * The per-request inputs a `RefResolver` needs to resolve a reference.
 *
 * Deliberately decoupled from `Illuminate\Http\Request`: resolvers
 * authorize through `AccessResolver`, which only needs the actor and
 * active tenant. Keeping the contract HTTP-free makes resolvers unit
 * testable and reusable from non-HTTP call sites (e.g. the related-docs
 * index builder in M8 S5).
 */
final readonly class RefResolutionContext
{
    public function __construct(
        public ActorIdentity $actor,
        public TenantContext $tenant,
    ) {}
}
