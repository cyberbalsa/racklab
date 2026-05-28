<?php

declare(strict_types=1);

namespace App\Networking;

use App\Models\VpnClientProfile;

final readonly class VpnClientProfilePayload
{
    /**
     * @return array<string, mixed>
     */
    public static function make(VpnClientProfile $profile): array
    {
        return [
            'id' => $profile->getKey(),
            'tenant_id' => $profile->tenant_id,
            'network_vpn_endpoint_id' => $profile->network_vpn_endpoint_id,
            'user_id' => $profile->user_id,
            'common_name' => $profile->common_name,
            'state' => $profile->state,
            'revoked_at' => $profile->revoked_at?->toIso8601String(),
            'revoked_reason' => $profile->revoked_reason,
            'expires_at' => $profile->expires_at?->toIso8601String(),
            'downloaded_at' => $profile->downloaded_at?->toIso8601String(),
            'created_at' => $profile->created_at?->toIso8601String(),
        ];
    }
}
