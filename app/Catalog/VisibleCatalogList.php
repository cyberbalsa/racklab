<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Models\CatalogItem;
use App\Models\CatalogVersion;
use App\Models\User;

/**
 * Lists catalog items the actor may read (AccessResolver `catalog.read`) that
 * have at least one published version, pairing each with its current
 * (most-recently published) version. Shared by the JSON catalog API and the
 * browser catalog page so both surfaces apply the same tenant policy.
 */
final readonly class VisibleCatalogList
{
    public function __construct(private AccessResolver $accessResolver) {}

    /**
     * @return list<array{item: CatalogItem, version: CatalogVersion}>
     */
    public function forUser(User $user, TenantContext $context): array
    {
        $actor = new ActorIdentity((string) $user->id);
        $permission = new Permission('catalog.read');
        $entries = [];

        /** @var CatalogItem $item */
        foreach (CatalogItem::query()->orderBy('name')->orderBy('id')->get() as $item) {
            $version = $this->currentVersion($item);

            if (! $version instanceof CatalogVersion) {
                continue;
            }

            if ($this->accessResolver->permitted($actor, $permission, $item, $context)->allowed) {
                $entries[] = ['item' => $item, 'version' => $version];
            }
        }

        return $entries;
    }

    private function currentVersion(CatalogItem $item): ?CatalogVersion
    {
        // Same predicate as CatalogItemIndexController::currentVersion so the
        // browser catalog and the JSON API surface the identical current
        // version for an item.
        /** @var CatalogVersion|null $version */
        $version = CatalogVersion::query()
            ->where('catalog_item_id', $item->getKey())
            ->where('state', 'published')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();

        return $version;
    }
}
