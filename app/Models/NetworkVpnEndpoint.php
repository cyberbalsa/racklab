<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\TenantScopedResource;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $project_id
 * @property string|null $deployment_id
 * @property string $network_id
 * @property string $vpn_public_ip_pool_id
 * @property string $name
 * @property string $state
 * @property string $provider
 * @property string $capability
 * @property array<string, mixed>|null $metadata
 * @property string $sharing_scope
 * @property list<string>|null $shared_with_tenants
 * @property int|null $created_by_id
 */
#[Fillable([
    'tenant_id',
    'project_id',
    'deployment_id',
    'network_id',
    'vpn_public_ip_pool_id',
    'name',
    'state',
    'provider',
    'capability',
    'metadata',
    'sharing_scope',
    'shared_with_tenants',
    'created_by_id',
])]
class NetworkVpnEndpoint extends Model implements TenantScopedResource
{
    use BelongsToTenant;
    use HasUlids;

    public const string STATE_PENDING = 'pending';

    public const string STATE_RUNNING = 'running';

    public const string STATE_STOPPED = 'stopped';

    public const string STATE_RELEASED = 'released';

    public const string STATE_FAILED = 'failed';

    public $incrementing = false;

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
     * @return BelongsTo<Network, $this>
     */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    /**
     * @return BelongsTo<VpnPublicIpPool, $this>
     */
    public function publicIpPool(): BelongsTo
    {
        return $this->belongsTo(VpnPublicIpPool::class, 'vpn_public_ip_pool_id');
    }

    /**
     * @return HasMany<NetworkVpnEndpointBinding, $this>
     */
    public function bindings(): HasMany
    {
        return $this->hasMany(NetworkVpnEndpointBinding::class);
    }

    /**
     * @return HasMany<VpnClientProfile, $this>
     */
    public function clientProfiles(): HasMany
    {
        return $this->hasMany(VpnClientProfile::class);
    }

    protected function tenantResourceTypeName(): string
    {
        return 'network_vpn_endpoint';
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'shared_with_tenants' => 'array',
        ];
    }
}
