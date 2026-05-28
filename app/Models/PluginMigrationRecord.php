<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Attributes\Untenanted;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property int $id
 * @property string $plugin_slug
 * @property string $direction
 * @property string|null $migration_version
 * @property \Illuminate\Support\Carbon $executed_at
 * @property array<string, mixed>|null $metadata
 */
#[Untenanted(reason: 'plugin migration state is global to the RackLab install')]
#[Fillable([
    'plugin_slug',
    'direction',
    'migration_version',
    'executed_at',
    'metadata',
])]
class PluginMigrationRecord extends Model
{
    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'executed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
