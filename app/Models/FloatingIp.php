<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\TenantScopedResource;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $project_id
 * @property string $floating_ip_pool_id
 * @property string|null $deployment_network_binding_id
 * @property int|null $allocated_by_id
 * @property string $address
 * @property string $state
 * @property string $provider
 * @property array<string, mixed>|null $provider_binding
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $released_at
 */
#[Fillable([
    'tenant_id',
    'project_id',
    'floating_ip_pool_id',
    'deployment_network_binding_id',
    'allocated_by_id',
    'address',
    'state',
    'provider',
    'provider_binding',
    'metadata',
    'released_at',
])]
class FloatingIp extends Model implements TenantScopedResource
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
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<FloatingIpPool, $this>
     */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(FloatingIpPool::class, 'floating_ip_pool_id');
    }

    /**
     * @return BelongsTo<DeploymentNetworkBinding, $this>
     */
    public function deploymentNetworkBinding(): BelongsTo
    {
        return $this->belongsTo(DeploymentNetworkBinding::class);
    }

    protected function tenantResourceTypeName(): string
    {
        return 'floating_ip';
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'allocated_by_id' => 'integer',
            'metadata' => 'array',
            'provider_binding' => 'array',
            'released_at' => 'datetime',
        ];
    }
}
