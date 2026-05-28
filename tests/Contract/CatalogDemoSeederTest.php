<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\CatalogItem;
use App\Models\CatalogVersion;
use App\Models\StackDefinition;
use App\Models\Tenant;
use Database\Seeders\CatalogDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('publishes idempotent demo catalog items in the default tenant', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Default Tenant',
        'slug' => config('racklab.default_tenant_slug', 'default'),
    ]);

    $this->seed(CatalogDemoSeeder::class);
    $this->seed(CatalogDemoSeeder::class); // idempotent re-run

    app(TenantContextStore::class)->set(new TenantContext(activeTenantId: $tenant->getKey()));
    $tenant->makeCurrent();

    $items = CatalogItem::query()->get();

    expect($items->count())->toBeGreaterThanOrEqual(2);

    foreach ($items as $item) {
        $published = CatalogVersion::query()
            ->where('catalog_item_id', $item->getKey())
            ->where('state', 'published')
            ->whereNotNull('published_at')
            ->get();

        expect($published->count())->toBe(1);

        $stack = StackDefinition::query()->find($published->first()?->stack_definition_id);

        expect($stack)->not->toBeNull()
            ->and($stack?->scope)->toBe('catalog')
            ->and($stack?->definition['components'] ?? [])->not->toBe([]);
    }

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();
});
