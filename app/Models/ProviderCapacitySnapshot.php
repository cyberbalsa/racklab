<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $provider
 * @property string|null $provider_cluster
 * @property string $node
 * @property bool $healthy
 * @property bool $maintenance_mode
 * @property int $available_vcpus
 * @property int $available_memory_mb
 * @property int $available_storage_gb
 * @property int $job_pressure
 * @property list<int>|null $templates
 * @property list<string>|null $tags
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon $observed_at
 */
#[Fillable([
    'tenant_id',
    'provider',
    'provider_cluster',
    'node',
    'healthy',
    'maintenance_mode',
    'available_vcpus',
    'available_memory_mb',
    'available_storage_gb',
    'job_pressure',
    'templates',
    'tags',
    'metadata',
    'observed_at',
])]
class ProviderCapacitySnapshot extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /**
     * @var bool
     */
    public $incrementing = false;

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
            'available_memory_mb' => 'integer',
            'available_storage_gb' => 'integer',
            'available_vcpus' => 'integer',
            'healthy' => 'boolean',
            'job_pressure' => 'integer',
            'maintenance_mode' => 'boolean',
            'metadata' => 'array',
            'observed_at' => 'datetime',
            'tags' => 'array',
            'templates' => 'array',
        ];
    }
}
