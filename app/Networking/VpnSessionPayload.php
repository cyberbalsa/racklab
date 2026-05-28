<?php

declare(strict_types=1);

namespace App\Networking;

use App\Models\VpnSession;

final readonly class VpnSessionPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function make(VpnSession $session): array
    {
        return [
            'id' => $session->getKey(),
            'tenant_id' => $session->tenant_id,
            'vpn_client_profile_id' => $session->vpn_client_profile_id,
            'network_vpn_endpoint_id' => $session->network_vpn_endpoint_id,
            'peer_ip' => $session->peer_ip,
            'state' => $session->state,
            'bytes_in' => $session->bytes_in,
            'bytes_out' => $session->bytes_out,
            'connected_at' => $session->connected_at?->toIso8601String(),
            'disconnected_at' => $session->disconnected_at?->toIso8601String(),
            'disconnect_reason' => $session->disconnect_reason,
        ];
    }
}
