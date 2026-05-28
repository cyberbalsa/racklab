<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Attributes\Untenanted;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $slug
 * @property string $package_name
 * @property string $version
 * @property string $state
 * @property string $service_provider
 * @property string|null $manifest_class
 * @property string $name
 * @property string|null $description
 * @property \Illuminate\Support\Carbon $installed_at
 * @property \Illuminate\Support\Carbon|null $migrated_at
 * @property \Illuminate\Support\Carbon|null $enabled_at
 * @property \Illuminate\Support\Carbon|null $disabled_at
 */
#[Untenanted(reason: 'plugin lifecycle state is global to the RackLab install')]
#[Fillable([
    'slug',
    'package_name',
    'version',
    'state',
    'service_provider',
    'manifest_class',
    'name',
    'description',
    'installed_at',
    'migrated_at',
    'enabled_at',
    'disabled_at',
])]
class PluginInstallation extends Model
{
    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $primaryKey = 'slug';

    /**
     * @var string
     */
    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'disabled_at' => 'datetime',
            'enabled_at' => 'datetime',
            'installed_at' => 'datetime',
            'migrated_at' => 'datetime',
        ];
    }
}
