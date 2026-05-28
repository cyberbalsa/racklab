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
 * @property string $name
 * @property string $slug
 * @property string $provider
 * @property string|null $provider_cluster
 * @property string $network_type
 * @property string $external_id
 * @property string|null $bridge
 * @property int|null $vlan_tag
 * @property array<string, mixed>|null $metadata
 * @property string $sharing_scope
 * @property list<string>|null $shared_with_tenants
 */
#[Fillable([
    'tenant_id',
    'name',
    'slug',
    'provider',
    'provider_cluster',
    'network_type',
    'external_id',
    'bridge',
    'vlan_tag',
    'metadata',
    'sharing_scope',
    'shared_with_tenants',
])]
class ProviderNetwork extends Model implements TenantScopedResource
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

    protected function tenantResourceTypeName(): string
    {
        return 'provider_network';
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
            'vlan_tag' => 'integer',
        ];
    }
}
