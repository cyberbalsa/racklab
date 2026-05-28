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
 * @property string $provider_network_id
 * @property string $name
 * @property string $slug
 * @property string $cidr
 * @property int $ip_version
 * @property string $provider
 * @property array<string, mixed>|null $metadata
 * @property string $sharing_scope
 * @property list<string>|null $shared_with_tenants
 */
#[Fillable([
    'tenant_id',
    'provider_network_id',
    'name',
    'slug',
    'cidr',
    'ip_version',
    'provider',
    'metadata',
    'sharing_scope',
    'shared_with_tenants',
])]
class FloatingIpPool extends Model implements TenantScopedResource
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
     * @return BelongsTo<ProviderNetwork, $this>
     */
    public function providerNetwork(): BelongsTo
    {
        return $this->belongsTo(ProviderNetwork::class);
    }

    /**
     * @return HasMany<FloatingIp, $this>
     */
    public function floatingIps(): HasMany
    {
        return $this->hasMany(FloatingIp::class);
    }

    protected function tenantResourceTypeName(): string
    {
        return 'floating_ip_pool';
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'ip_version' => 'integer',
            'metadata' => 'array',
            'shared_with_tenants' => 'array',
        ];
    }
}
