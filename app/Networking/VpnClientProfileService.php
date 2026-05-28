<?php

declare(strict_types=1);

namespace App\Networking;

use App\Audit\AuditEventWriter;
use App\Domain\Tenancy\TenantContext;
use App\Models\NetworkVpnEndpoint;
use App\Models\NetworkVpnEndpointBinding;
use App\Models\User;
use App\Models\VpnClientProfile;
use App\Models\VpnSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Owns the VPN client profile lifecycle:
 *
 * - issuance: generate material, encrypt at rest, persist, emit audit.
 * - download (owner-only): decrypt, stamp `downloaded_at`, emit audit.
 *   The decrypted material is returned to the caller verbatim; the
 *   caller (controller) renders the response stream.
 * - revocation: flip state, set `revoked_at` + `revoked_by_id` + reason,
 *   close any open VpnSessions, emit audit. The unique constraint on
 *   `(endpoint, user)` means a revoked profile blocks re-issuance until
 *   it's cleaned up; that's intentional — revocation is meant to be
 *   sticky until an explicit reactivation or hard-delete by an admin.
 *
 * Audit events emitted: `network.vpnaas.profile` with actions
 * `issue` / `download` / `revoke` / `download_denied`.
 */
final readonly class VpnClientProfileService
{
    public const string AUDIT_EVENT = 'network.vpnaas.profile';

    public function __construct(
        private AuditEventWriter $auditEvents,
        private VpnaasQuotaService $quota,
        private VpnClientProfileGenerator $generator,
    ) {}

    /**
     * Issue a new profile. Returns the persisted VpnClientProfile.
     */
    public function issue(
        User $actor,
        TenantContext $context,
        NetworkVpnEndpoint $endpoint,
        User $owner,
        ?CarbonImmutable $expiresAt = null,
    ): VpnClientProfile {
        if ($endpoint->state !== NetworkVpnEndpoint::STATE_RUNNING) {
            throw ValidationException::withMessages([
                'network_vpn_endpoint_id' => ['VPN endpoint must be running to issue profiles.'],
            ]);
        }

        $existing = VpnClientProfile::query()
            ->where('network_vpn_endpoint_id', $endpoint->getKey())
            ->where('user_id', $owner->getKey())
            ->first();

        if ($existing instanceof VpnClientProfile) {
            throw ValidationException::withMessages([
                'user_id' => ['User already has a VPN client profile for this endpoint.'],
            ]);
        }

        /** @var NetworkVpnEndpointBinding|null $binding */
        $binding = $endpoint->bindings()->whereIn('state', [
            NetworkVpnEndpointBinding::STATE_PENDING,
            NetworkVpnEndpointBinding::STATE_ACTIVE,
        ])->first();

        if (! $binding instanceof NetworkVpnEndpointBinding) {
            throw new RuntimeException('VPN endpoint has no active binding to render a profile against.');
        }

        $ownerKey = $owner->getKey();
        $commonName = sprintf(
            '%s-%s',
            $endpoint->resourceId(),
            is_string($ownerKey) || is_int($ownerKey) ? (string) $ownerKey : 'unknown',
        );
        $material = $this->generator->generate($endpoint, $binding, $owner, $commonName);

        return DB::transaction(function () use ($actor, $context, $endpoint, $owner, $expiresAt, $commonName, $material): VpnClientProfile {
            $quotaLimits = $this->quota->assertClientProfileAvailable($actor, $context, $endpoint->project()->firstOrFail());

            /** @var VpnClientProfile $profile */
            $profile = VpnClientProfile::query()->create([
                'tenant_id' => $endpoint->tenant_id,
                'network_vpn_endpoint_id' => $endpoint->getKey(),
                'user_id' => $owner->getKey(),
                'common_name' => $commonName,
                'config_ciphertext' => Crypt::encryptString($material->config),
                'private_key_ciphertext' => Crypt::encryptString($material->privateKeyPem),
                'certificate_pem' => $material->certificatePem,
                'state' => VpnClientProfile::STATE_ACTIVE,
                'revoked_by_id' => null,
                'revoked_reason' => null,
                'revoked_at' => null,
                'expires_at' => $expiresAt,
                'downloaded_at' => null,
            ]);

            $this->quota->consumeForProfile($quotaLimits, $profile, $actor);

            $this->audit($actor, $context, $endpoint, $owner, $profile, 'issue', 'allowed', [
                'network_vpn_endpoint_id' => $endpoint->resourceId(),
                'vpn_client_profile_id' => $profile->resourceId(),
                'common_name' => $commonName,
                'expires_at' => $expiresAt?->toIso8601String(),
            ]);

            return $profile;
        });
    }

    /**
     * Decrypt and return the rendered .ovpn config for an active profile.
     * Caller must already have verified ownership + permission; this method
     * stamps `downloaded_at`, emits the audit row, and returns the bytes.
     */
    public function downloadConfig(User $actor, TenantContext $context, VpnClientProfile $profile): string
    {
        if (! $profile->isActive()) {
            $this->audit($actor, $context, null, null, $profile, 'download_denied', 'denied', [
                'reason' => 'profile_not_active',
                'vpn_client_profile_id' => $profile->resourceId(),
                'state' => $profile->state,
            ]);

            throw ValidationException::withMessages([
                'vpn_client_profile_id' => ['VPN client profile is not active.'],
            ]);
        }

        // Codex M5c S4 P2: a profile is only as live as its endpoint. After
        // the endpoint is released the underlying public_ip:udp_port binding
        // is gone, so the .ovpn it contains is useless and must not download.
        // The endpoint-release path also revokes attached profiles via
        // `revokeAllForEndpoint()`, but this guard is the inner defense.
        $endpoint = NetworkVpnEndpoint::query()->whereKey($profile->network_vpn_endpoint_id)->first();
        if (! $endpoint instanceof NetworkVpnEndpoint || $endpoint->state !== NetworkVpnEndpoint::STATE_RUNNING) {
            $this->audit($actor, $context, null, null, $profile, 'download_denied', 'denied', [
                'reason' => 'endpoint_not_running',
                'vpn_client_profile_id' => $profile->resourceId(),
                'endpoint_state' => $endpoint?->state,
            ]);

            throw ValidationException::withMessages([
                'vpn_client_profile_id' => ['VPN endpoint must be running to download a client profile.'],
            ]);
        }

        $config = Crypt::decryptString((string) $profile->config_ciphertext);
        $profile->forceFill(['downloaded_at' => now()])->save();

        $this->audit($actor, $context, null, null, $profile, 'download', 'allowed', [
            'vpn_client_profile_id' => $profile->resourceId(),
        ]);

        return $config;
    }

    /**
     * Revoke every active profile attached to an endpoint. Called by the
     * endpoint destroy path so the endpoint release converges attached
     * profiles to `revoked` (codex M5c S4 P2).
     */
    public function revokeAllForEndpoint(User $actor, TenantContext $context, NetworkVpnEndpoint $endpoint, string $reason): void
    {
        /** @var list<VpnClientProfile> $profiles */
        $profiles = VpnClientProfile::query()
            ->where('network_vpn_endpoint_id', $endpoint->getKey())
            ->where('state', VpnClientProfile::STATE_ACTIVE)
            ->get()
            ->all();

        foreach ($profiles as $profile) {
            $this->revoke($actor, $context, $profile, $reason);
        }
    }

    /**
     * Revoke a profile. Closes any open sessions for this profile and
     * flips state to revoked. Idempotent — revoking an already-revoked
     * profile is a no-op + audit only.
     */
    public function revoke(
        User $actor,
        TenantContext $context,
        VpnClientProfile $profile,
        string $reason,
    ): void {
        if ($profile->state === VpnClientProfile::STATE_REVOKED) {
            return;
        }

        DB::transaction(function () use ($actor, $context, $profile, $reason): void {
            $profile->forceFill([
                'state' => VpnClientProfile::STATE_REVOKED,
                'revoked_by_id' => $actor->getKey(),
                'revoked_reason' => $reason,
                'revoked_at' => now(),
            ])->save();

            // Close open sessions, but keep them in the ledger for audit.
            VpnSession::query()
                ->where('vpn_client_profile_id', $profile->getKey())
                ->where('state', VpnSession::STATE_ACTIVE)
                ->update([
                    'state' => VpnSession::STATE_CLOSED,
                    'disconnected_at' => now(),
                    'disconnect_reason' => 'profile_revoked',
                ]);

            $this->quota->releaseForProfile($profile, $actor);

            $this->audit($actor, $context, null, null, $profile, 'revoke', 'allowed', [
                'vpn_client_profile_id' => $profile->resourceId(),
                'reason' => $reason,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(
        User $actor,
        TenantContext $context,
        ?NetworkVpnEndpoint $endpoint,
        ?User $owner,
        VpnClientProfile $profile,
        string $action,
        string $result,
        array $metadata,
    ): void {
        $this->auditEvents->append([
            'event_type' => self::AUDIT_EVENT,
            'action' => $action,
            'result' => $result,
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => $profile->resourceType(),
            'resource_id' => $profile->resourceId(),
            'resource_tenant' => $profile->tenant_id,
            'target_tenant_set' => [$context->activeTenantId, $profile->tenant_id],
            'effective_permissions' => match ($action) {
                'issue' => ['network.vpnaas.profile.create'],
                'download', 'download_denied' => ['network.vpnaas.profile.download'],
                'revoke' => ['network.vpnaas.profile.revoke'],
                default => [],
            },
            'metadata' => $metadata + [
                'network_vpn_endpoint_id' => $profile->network_vpn_endpoint_id,
                'owner_user_id' => $owner?->getKey() ?? $profile->user_id,
            ],
        ]);

        unset($endpoint);
    }
}
