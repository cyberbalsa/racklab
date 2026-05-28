<?php

declare(strict_types=1);

namespace App\Docs\Refs\Resolving\Core;

use App\Docs\Refs\Resolving\RefResolutionContext;
use App\Docs\Refs\Resolving\RefResolver;
use App\Docs\Refs\Resolving\ResolvedRef;
use App\Models\PluginInstallation;

/**
 * Core resolver for `[[plugin:slug]]` cross-links.
 *
 * `PluginInstallation` is `#[Untenanted]` — plugin lifecycle state is
 * global to the RackLab install and is non-sensitive operational
 * metadata (a plugin's name and enabled/disabled state). It carries no
 * tenant data, so the resolver does not apply a per-tenant RBAC check;
 * any authenticated docs reader who can load the page may see whether a
 * plugin is enabled.
 *
 * Cross-link ids cannot contain `/` (see `RackLabRef`), but plugin slugs
 * are vendor-prefixed (`racklab/docs-plugin`). Authors therefore write
 * the package short name — `[[plugin:docs-plugin]]` — which matches a
 * slug exactly or by its trailing `/<short-name>` segment.
 */
final readonly class PluginRefResolver implements RefResolver
{
    public function kind(): string
    {
        return 'plugin';
    }

    public function resolve(RefResolutionContext $context, string $id): ResolvedRef
    {
        $plugin = $this->find($id);

        if (! $plugin instanceof PluginInstallation) {
            return ResolvedRef::notFound($this->kind(), $id);
        }

        // No public plugin detail page yet (M10a); render a non-link pill.
        return ResolvedRef::resolved($this->kind(), $id, $plugin->name, null, $plugin->state);
    }

    /**
     * Match an exact slug or a vendor-prefixed slug by its trailing
     * `/<short-name>` segment. The suffix match is done in PHP rather than
     * a SQL `LIKE` so a `_` in the (grammar-validated) id cannot act as a
     * `LIKE` wildcard. The plugin table is tiny, so the scan is cheap.
     */
    private function find(string $id): ?PluginInstallation
    {
        /** @var PluginInstallation|null $exact */
        $exact = PluginInstallation::query()->whereKey($id)->first();

        if ($exact instanceof PluginInstallation) {
            return $exact;
        }

        $suffix = '/'.$id;

        return PluginInstallation::query()
            ->get()
            ->first(static fn (PluginInstallation $plugin): bool => str_ends_with($plugin->slug, $suffix));
    }
}
