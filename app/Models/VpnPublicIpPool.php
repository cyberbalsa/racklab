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
 * @property string $name
 * @property string $slug
 * @property string $provider
 * @property string $cidr
 * @property int $port_range_min
 * @property int $port_range_max
 * @property array<string, mixed>|null $metadata
 * @property string $sharing_scope
 * @property list<string>|null $shared_with_tenants
 */
#[Fillable([
    'tenant_id',
    'name',
    'slug',
    'provider',
    'cidr',
    'port_range_min',
    'port_range_max',
    'metadata',
    'sharing_scope',
    'shared_with_tenants',
])]
class VpnPublicIpPool extends Model implements TenantScopedResource
{
    use BelongsToTenant;
    use HasUlids;

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
     * @return HasMany<NetworkVpnEndpoint, $this>
     */
    public function endpoints(): HasMany
    {
        return $this->hasMany(NetworkVpnEndpoint::class);
    }

    protected function tenantResourceTypeName(): string
    {
        return 'vpn_public_ip_pool';
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'port_range_min' => 'integer',
            'port_range_max' => 'integer',
            'metadata' => 'array',
            'shared_with_tenants' => 'array',
        ];
    }
}
