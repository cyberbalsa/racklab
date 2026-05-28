<?php

declare(strict_types=1);

namespace App\Docs\Refs\Resolving;

/**
 * Resolves `[[kind:id]]` cross-link references of a single `kind` into a
 * live, RBAC-filtered `ResolvedRef`.
 *
 * Core RackLab ships resolvers for deployment / project / course /
 * network / script / plugin. Plugins contribute resolvers for their own
 * object kinds via the `App\Events\Hookspecs\Docs\RefResolvingEvent`
 * hookspec (Resolver style — first non-null wins).
 *
 * Implementations MUST NOT leak existence or status of a target the
 * actor cannot read: return `ResolvedRef::redacted()` when the target
 * exists but the actor lacks permission, and `ResolvedRef::notFound()`
 * when no such target exists in the active tenant.
 */
interface RefResolver
{
    /**
     * The reference kind this resolver handles, e.g. `deployment`.
     */
    public function kind(): string;

    /**
     * Resolve the reference `$id` for the given actor/tenant context.
     */
    public function resolve(RefResolutionContext $context, string $id): ResolvedRef;
}
