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
 * @property string $network_vpn_endpoint_id
 * @property string|null $node
 * @property string $public_ip
 * @property int $udp_port
 * @property string $state
 * @property array<string, mixed>|null $provider_binding
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'tenant_id',
    'network_vpn_endpoint_id',
    'node',
    'public_ip',
    'udp_port',
    'state',
    'provider_binding',
    'metadata',
])]
class NetworkVpnEndpointBinding extends Model implements TenantScopedResource
{
    use BelongsToTenant;
    use HasUlids;

    public const string STATE_PENDING = 'pending';

    public const string STATE_ACTIVE = 'active';

    public const string STATE_RELEASED = 'released';

    public const string STATE_FAILED = 'failed';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return BelongsTo<NetworkVpnEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(NetworkVpnEndpoint::class, 'network_vpn_endpoint_id');
    }

    protected function tenantResourceTypeName(): string
    {
        return 'network_vpn_endpoint_binding';
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'udp_port' => 'integer',
            'provider_binding' => 'array',
            'metadata' => 'array',
        ];
    }
}
