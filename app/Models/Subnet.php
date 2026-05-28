<?php

declare(strict_types=1);

namespace App\Models;

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
 * @property string $network_id
 * @property string|null $subnet_pool_id
 * @property string $cidr
 * @property int $ip_version
 * @property string|null $gateway_ip
 * @property bool $dhcp_enabled
 * @property list<array<string, string>>|null $allocation_pools
 * @property list<string>|null $dns_nameservers
 * @property list<array<string, string>>|null $host_routes
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'tenant_id',
    'project_id',
    'network_id',
    'subnet_pool_id',
    'cidr',
    'ip_version',
    'gateway_ip',
    'dhcp_enabled',
    'allocation_pools',
    'dns_nameservers',
    'host_routes',
    'metadata',
])]
class Subnet extends Model
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
     * @return BelongsTo<Network, $this>
     */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    /**
     * @return BelongsTo<SubnetPool, $this>
     */
    public function subnetPool(): BelongsTo
    {
        return $this->belongsTo(SubnetPool::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'ip_version' => 'integer',
            'dhcp_enabled' => 'boolean',
            'allocation_pools' => 'array',
            'dns_nameservers' => 'array',
            'host_routes' => 'array',
            'metadata' => 'array',
        ];
    }
}
