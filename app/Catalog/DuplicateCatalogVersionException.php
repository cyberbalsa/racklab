<?php

declare(strict_types=1);

namespace App\Catalog;

use RuntimeException;

/**
 * Thrown when publishing a version label that already exists for a catalog item
 * (the `catalog_versions` unique `(catalog_item_id, version)` constraint). Lets
 * callers surface a friendly validation error instead of a raw DB 500.
 */
final class DuplicateCatalogVersionException extends RuntimeException {}
