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
 * @property string $cidr
 * @property int $ip_version
 * @property int $default_prefix_length
 * @property int $min_prefix_length
 * @property int $max_prefix_length
 * @property array<string, mixed>|null $metadata
 * @property string $sharing_scope
 * @property list<string>|null $shared_with_tenants
 */
#[Fillable([
    'tenant_id',
    'name',
    'slug',
    'cidr',
    'ip_version',
    'default_prefix_length',
    'min_prefix_length',
    'max_prefix_length',
    'metadata',
    'sharing_scope',
    'shared_with_tenants',
])]
class SubnetPool extends Model implements TenantScopedResource
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
     * @return HasMany<Subnet, $this>
     */
    public function subnets(): HasMany
    {
        return $this->hasMany(Subnet::class);
    }

    protected function tenantResourceTypeName(): string
    {
        return 'subnet_pool';
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'ip_version' => 'integer',
            'default_prefix_length' => 'integer',
            'min_prefix_length' => 'integer',
            'max_prefix_length' => 'integer',
            'metadata' => 'array',
            'shared_with_tenants' => 'array',
        ];
    }
}
