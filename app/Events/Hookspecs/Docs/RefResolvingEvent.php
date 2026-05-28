<?php

declare(strict_types=1);

namespace App\Events\Hookspecs\Docs;

/**
 * Hookspec the docs plugin defines so other plugins can contribute
 * cross-link resolvers for new object kinds.
 *
 * Dispatched (Resolver style — first non-null wins) by
 * `RefResolverRegistry` when a `[[kind:id]]` reference uses a `kind`
 * that core RackLab does not ship a built-in resolver for. A plugin
 * listener inspects `$kind` and returns a
 * `App\Docs\Refs\Resolving\RefResolver` instance (or `null` if it does
 * not handle that kind).
 *
 * This is the rarer half of the plugin contract: the docs plugin is an
 * extension *point*, not just an extender.
 */
final readonly class RefResolvingEvent
{
    public function __construct(
        public string $kind,
    ) {}
}
