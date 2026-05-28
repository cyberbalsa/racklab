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
 * @property string $network_vpn_endpoint_id
 * @property int $user_id
 * @property string $common_name
 * @property string $config_ciphertext
 * @property string $private_key_ciphertext
 * @property string|null $certificate_pem
 * @property string $state
 * @property int|null $revoked_by_id
 * @property string|null $revoked_reason
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $downloaded_at
 */
#[Fillable([
    'tenant_id',
    'network_vpn_endpoint_id',
    'user_id',
    'common_name',
    'config_ciphertext',
    'private_key_ciphertext',
    'certificate_pem',
    'state',
    'revoked_by_id',
    'revoked_reason',
    'revoked_at',
    'expires_at',
    'downloaded_at',
])]
class VpnClientProfile extends Model implements TenantScopedResource
{
    use BelongsToTenant;
    use HasUlids;

    public const string STATE_ACTIVE = 'active';

    public const string STATE_REVOKED = 'revoked';

    public const string STATE_EXPIRED = 'expired';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return BelongsTo<NetworkVpnEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(NetworkVpnEndpoint::class, 'network_vpn_endpoint_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<VpnSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(VpnSession::class);
    }

    public function isActive(): bool
    {
        if ($this->state !== self::STATE_ACTIVE || $this->revoked_at !== null) {
            return false;
        }

        // Profiles whose expires_at has elapsed are not yet flagged as `expired`
        // in storage (a maintenance job does that), so reject here so download /
        // connect guards do not accept stale credentials.
        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    protected function tenantResourceTypeName(): string
    {
        return 'vpn_client_profile';
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
            'expires_at' => 'datetime',
            'downloaded_at' => 'datetime',
        ];
    }
}
