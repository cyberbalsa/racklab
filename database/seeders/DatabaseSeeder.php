<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(RbacDefaultsSynchronizer $rbac): void
    {
        $slug = config('racklab.default_tenant_slug', 'default');

        Tenant::query()->firstOrCreate([
            'slug' => is_string($slug) && trim($slug) !== '' ? $slug : 'default',
        ], [
            'name' => 'Default Tenant',
            'is_active' => true,
        ]);

        $rbac->sync();

        if (config('racklab.seed_demo_catalog', false)) {
            $this->call(CatalogDemoSeeder::class);
        }
    }
}
