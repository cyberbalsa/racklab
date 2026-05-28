<?php

declare(strict_types=1);

namespace App\Networking;

use App\Audit\AuditEventWriter;
use App\Domain\Tenancy\TenantContext;
use App\Models\NetworkVpnEndpoint;
use App\Models\User;
use App\Models\VpnClientProfile;
use App\Models\VpnSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records VPN connect/disconnect lifecycle events into the VpnSession
 * ledger. Connect events are gated by an active client profile; disconnect
 * events flip the session to closed and stamp the reason.
 *
 * Audit events emitted: `network.vpnaas.profile` with actions
 * `session_connect` / `session_disconnect`, scoped to the profile.
 */
final readonly class VpnSessionService
{
    public function __construct(
        private AuditEventWriter $auditEvents,
    ) {}

    public function recordConnect(
        User $actor,
        TenantContext $context,
        VpnClientProfile $profile,
        ?string $peerIp,
    ): VpnSession {
        if (! $profile->isActive()) {
            throw ValidationException::withMessages([
                'vpn_client_profile_id' => ['Profile must be active to record a connection.'],
            ]);
        }

        /** @var NetworkVpnEndpoint|null $endpoint */
        $endpoint = NetworkVpnEndpoint::query()->whereKey($profile->network_vpn_endpoint_id)->first();

        if (! $endpoint instanceof NetworkVpnEndpoint || $endpoint->state !== NetworkVpnEndpoint::STATE_RUNNING) {
            throw ValidationException::withMessages([
                'vpn_client_profile_id' => ['VPN endpoint must be running to record connections.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $context, $profile, $endpoint, $peerIp): VpnSession {
            /** @var VpnSession $session */
            $session = VpnSession::query()->create([
                'tenant_id' => $profile->tenant_id,
                'vpn_client_profile_id' => $profile->getKey(),
                'network_vpn_endpoint_id' => $endpoint->getKey(),
                'peer_ip' => $peerIp,
                'state' => VpnSession::STATE_ACTIVE,
                'bytes_in' => 0,
                'bytes_out' => 0,
                'connected_at' => now(),
                'metadata' => [],
            ]);

            $this->audit($actor, $context, $profile, $session, 'session_connect', 'allowed', [
                'vpn_session_id' => $session->resourceId(),
                'peer_ip' => $peerIp,
            ]);

            return $session;
        });
    }

    public function recordDisconnect(
        User $actor,
        TenantContext $context,
        VpnSession $session,
        string $reason,
        int $bytesIn = 0,
        int $bytesOut = 0,
    ): VpnSession {
        if ($session->state === VpnSession::STATE_CLOSED) {
            return $session;
        }

        /** @var VpnClientProfile $profile */
        $profile = VpnClientProfile::query()->whereKey($session->vpn_client_profile_id)->firstOrFail();

        return DB::transaction(function () use ($actor, $context, $session, $profile, $reason, $bytesIn, $bytesOut): VpnSession {
            $session->forceFill([
                'state' => VpnSession::STATE_CLOSED,
                'disconnected_at' => now(),
                'disconnect_reason' => $reason,
                'bytes_in' => $bytesIn,
                'bytes_out' => $bytesOut,
            ])->save();

            $this->audit($actor, $context, $profile, $session, 'session_disconnect', 'allowed', [
                'vpn_session_id' => $session->resourceId(),
                'reason' => $reason,
                'bytes_in' => $bytesIn,
                'bytes_out' => $bytesOut,
            ]);

            return $session;
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(
        User $actor,
        TenantContext $context,
        VpnClientProfile $profile,
        VpnSession $session,
        string $action,
        string $result,
        array $metadata,
    ): void {
        $this->auditEvents->append([
            'event_type' => 'network.vpnaas.profile',
            'action' => $action,
            'result' => $result,
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => $session->resourceType(),
            'resource_id' => $session->resourceId(),
            'resource_tenant' => $session->tenant_id,
            'target_tenant_set' => [$context->activeTenantId, $session->tenant_id],
            'effective_permissions' => ['network.vpnaas.session.read'],
            'metadata' => $metadata + [
                'vpn_client_profile_id' => $profile->resourceId(),
                'network_vpn_endpoint_id' => $session->network_vpn_endpoint_id,
                'owner_user_id' => $profile->user_id,
            ],
        ]);
    }
}
