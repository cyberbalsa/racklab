<?php

declare(strict_types=1);

namespace App\Docs\Refs\Resolving;

use App\Events\Hookspecs\Docs\RefResolvingEvent;
use App\Plugins\HookDispatcher;

/**
 * Selects the `RefResolver` for a reference kind.
 *
 * Built-in core resolvers take precedence — a plugin cannot hijack
 * resolution of a first-class core kind (deployment, project, …). For
 * any other kind, the registry dispatches the `RefResolvingEvent`
 * hookspec (Resolver style) so plugins can contribute resolvers for
 * their own object kinds. Returns `null` when no resolver handles the
 * kind, which the endpoint surfaces as an `unsupported` reference.
 */
final readonly class RefResolverRegistry
{
    /**
     * @param  list<RefResolver>  $coreResolvers
     */
    public function __construct(
        private HookDispatcher $dispatcher,
        private array $coreResolvers,
    ) {}

    public function resolverFor(string $kind): ?RefResolver
    {
        foreach ($this->coreResolvers as $resolver) {
            if ($resolver->kind() === $kind) {
                return $resolver;
            }
        }

        $contributed = $this->dispatcher->resolve(new RefResolvingEvent($kind));

        return $contributed instanceof RefResolver ? $contributed : null;
    }
}
