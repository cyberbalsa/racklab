<?php

declare(strict_types=1);

namespace App\Docs\Refs\Resolving\Core;

use App\Docs\Refs\Resolving\RefResolutionContext;
use App\Docs\Refs\Resolving\RefResolver;
use App\Docs\Refs\Resolving\ResolvedRef;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Models\Network;

/**
 * Core resolver for `[[network:id]]` cross-links. Gated on `network.read`.
 */
final readonly class NetworkRefResolver implements RefResolver
{
    public function __construct(private AccessResolver $access) {}

    public function kind(): string
    {
        return 'network';
    }

    public function resolve(RefResolutionContext $context, string $id): ResolvedRef
    {
        /** @var Network|null $network */
        $network = Network::query()->whereKey($id)->first();

        if (! $network instanceof Network) {
            return ResolvedRef::notFound($this->kind(), $id);
        }

        $decision = $this->access->permitted(
            $context->actor,
            new Permission('network.read'),
            $network,
            $context->tenant,
        );

        if (! $decision->allowed) {
            return ResolvedRef::redacted($this->kind(), $id);
        }

        // No public network detail page yet (M10a); render a non-link pill.
        return ResolvedRef::resolved($this->kind(), $id, $network->name, null, $network->state);
    }
}
