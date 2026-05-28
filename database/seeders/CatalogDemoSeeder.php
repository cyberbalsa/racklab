<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\CatalogItem;
use App\Models\CatalogVersion;
use App\Models\StackDefinition;
use App\Models\Tenant;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Publishes a small set of starter catalog items so a fresh install has a
 * browsable, deployable catalog. Items are single-VM fake-provider stacks
 * (provider defaults to the fake lifecycle) and are tenant-local to the
 * default tenant. Idempotent per (tenant, slug).
 */
class CatalogDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * @var list<array{slug: string, name: string, description: string, version: string, summary: string, cpus: int, memory_mb: int, disk_gb: int}>
     */
    private const array ITEMS = [
        [
            'slug' => 'ubuntu-2204',
            'name' => 'Ubuntu Server 22.04',
            'description' => 'A clean Ubuntu Server 22.04 LTS virtual machine. Good default starting point for general lab work.',
            'version' => '1.0.0',
            'summary' => 'Single Ubuntu 22.04 LTS VM, 2 vCPU / 2 GB RAM / 20 GB disk.',
            'cpus' => 2,
            'memory_mb' => 2048,
            'disk_gb' => 20,
        ],
        [
            'slug' => 'debian-12',
            'name' => 'Debian 12 (Bookworm)',
            'description' => 'A minimal Debian 12 virtual machine for lightweight services and scripting exercises.',
            'version' => '1.0.0',
            'summary' => 'Single Debian 12 VM, 1 vCPU / 1 GB RAM / 20 GB disk.',
            'cpus' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 20,
        ],
        [
            'slug' => 'kali-rolling',
            'name' => 'Kali Linux (Rolling)',
            'description' => 'A Kali Linux workstation pre-positioned for offensive-security coursework in an isolated network.',
            'version' => '1.0.0',
            'summary' => 'Single Kali Linux VM, 2 vCPU / 4 GB RAM / 40 GB disk.',
            'cpus' => 2,
            'memory_mb' => 4096,
            'disk_gb' => 40,
        ],
    ];

    public function run(): void
    {
        $slug = config('racklab.default_tenant_slug', 'default');
        $slug = is_string($slug) && trim($slug) !== '' ? $slug : 'default';

        $tenant = Tenant::query()->where('slug', $slug)->first();

        if (! $tenant instanceof Tenant) {
            return;
        }

        $store = app(TenantContextStore::class);
        $store->set(new TenantContext(activeTenantId: $tenant->getKey()));
        $tenant->makeCurrent();

        try {
            foreach (self::ITEMS as $spec) {
                $this->seedItem($tenant, $spec);
            }
        } finally {
            $store->forget();
            Tenant::forgetCurrent();
        }
    }

    /**
     * @param  array{slug: string, name: string, description: string, version: string, summary: string, cpus: int, memory_mb: int, disk_gb: int}  $spec
     */
    private function seedItem(Tenant $tenant, array $spec): void
    {
        $stack = StackDefinition::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'project_id' => null,
                'slug' => $spec['slug'],
            ],
            [
                'name' => $spec['name'],
                'scope' => 'catalog',
                'is_reserved_default' => false,
                'definition' => [
                    'version' => 1,
                    'provider' => 'fake',
                    'components' => [
                        [
                            'key' => 'vm',
                            'kind' => 'vm',
                            'resources' => [
                                'vcpus' => $spec['cpus'],
                                'memory_mb' => $spec['memory_mb'],
                                'disk_gb' => $spec['disk_gb'],
                            ],
                        ],
                    ],
                ],
                'sharing_scope' => 'tenant_local',
                'shared_with_tenants' => [],
            ],
        );

        $item = CatalogItem::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'slug' => $spec['slug'],
            ],
            [
                'name' => $spec['name'],
                'description' => $spec['description'],
                'sharing_scope' => 'tenant_local',
                'shared_with_tenants' => [],
            ],
        );

        CatalogVersion::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'catalog_item_id' => $item->getKey(),
                'version' => $spec['version'],
            ],
            [
                'stack_definition_id' => $stack->getKey(),
                'state' => 'published',
                'published_at' => Carbon::now(),
                'summary' => $spec['summary'],
            ],
        );
    }
}
