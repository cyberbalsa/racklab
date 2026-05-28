<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Models\CatalogItem;
use App\Models\CatalogVersion;
use App\Models\StackDefinition;

final readonly class CatalogPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function item(CatalogItem $item, ?CatalogVersion $currentVersion): array
    {
        return [
            'id' => $item->getKey(),
            'tenant_id' => $item->tenant_id,
            'name' => $item->name,
            'slug' => $item->slug,
            'description' => $item->description,
            'sharing_scope' => $item->sharing_scope,
            'current_version' => $currentVersion instanceof CatalogVersion
                ? self::versionSummary($currentVersion)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function version(CatalogVersion $version): array
    {
        $version->loadMissing('stackDefinition');

        /** @var StackDefinition $stack */
        $stack = $version->stackDefinition;

        return [
            ...self::versionSummary($version),
            'stack_definition' => [
                'id' => $stack->getKey(),
                'name' => $stack->name,
                'slug' => $stack->slug,
                'definition' => $stack->definition ?? [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function versionSummary(CatalogVersion $version): array
    {
        return [
            'id' => $version->getKey(),
            'catalog_item_id' => $version->catalog_item_id,
            'stack_definition_id' => $version->stack_definition_id,
            'version' => $version->version,
            'state' => $version->state,
            'summary' => $version->summary,
            'published_at' => $version->published_at?->toJSON(),
        ];
    }
}
