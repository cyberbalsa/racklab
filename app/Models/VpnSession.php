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
 * @property string $vpn_client_profile_id
 * @property string $network_vpn_endpoint_id
 * @property string|null $peer_ip
 * @property string $state
 * @property int $bytes_in
 * @property int $bytes_out
 * @property \Illuminate\Support\Carbon|null $connected_at
 * @property \Illuminate\Support\Carbon|null $disconnected_at
 * @property string|null $disconnect_reason
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'tenant_id',
    'vpn_client_profile_id',
    'network_vpn_endpoint_id',
    'peer_ip',
    'state',
    'bytes_in',
    'bytes_out',
    'connected_at',
    'disconnected_at',
    'disconnect_reason',
    'metadata',
])]
class VpnSession extends Model implements TenantScopedResource
{
    use BelongsToTenant;
    use HasUlids;

    public const string STATE_ACTIVE = 'active';

    public const string STATE_CLOSED = 'closed';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return BelongsTo<VpnClientProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(VpnClientProfile::class, 'vpn_client_profile_id');
    }

    /**
     * @return BelongsTo<NetworkVpnEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(NetworkVpnEndpoint::class, 'network_vpn_endpoint_id');
    }

    protected function tenantResourceTypeName(): string
    {
        return 'vpn_session';
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'bytes_in' => 'integer',
            'bytes_out' => 'integer',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
