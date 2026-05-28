<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Audit\AuditEventWriter;
use App\Auth\Tokens\CurrentTokenAbilities;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreVpnClientProfileRequest;
use App\Models\NetworkVpnEndpoint;
use App\Models\Project;
use App\Models\TenantMembership;
use App\Models\User;
use App\Networking\VpnaasCapabilityGate;
use App\Networking\VpnClientProfilePayload;
use App\Networking\VpnClientProfileService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class VpnClientProfileStoreController extends Controller
{
    private const string PERMISSION = 'network.vpnaas.profile.create';

    public function __invoke(
        StoreVpnClientProfileRequest $request,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        CurrentTokenAbilities $tokenAbilities,
        AuditEventWriter $auditEvents,
        VpnClientProfileService $profiles,
        VpnaasCapabilityGate $gate,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        // Codex M5c S6 P2-1: capability gate. Refuse profile issuance when
        // the racklab/network-vpnaas-openvpn plugin is not enabled.
        if (! $gate->isEnabled()) {
            throw new ServiceUnavailableHttpException(retryAfter: null, message: 'VPNaaS capability is not enabled. Run `racklab plugin enable racklab/network-vpnaas-openvpn`.');
        }

        $endpoint = $this->endpoint($request->string('network_vpn_endpoint_id')->toString(), $context);
        /** @var Project|null $project */
        $project = $endpoint->project()->first();

        if (! $project instanceof Project) {
            throw new NotFoundHttpException('VPN endpoint project missing.');
        }

        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission(self::PERMISSION),
            $project,
            $context,
        );

        if (! $tokenAbilities->allows($request, self::PERMISSION) || ! $decision->allowed) {
            $auditEvents->append([
                'event_type' => 'network.vpnaas.profile',
                'action' => 'issue',
                'result' => 'denied',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'actor_tenant' => $context->activeTenantId,
                'resource_type' => 'project',
                'resource_id' => $project->getKey(),
                'resource_tenant' => $project->tenant_id,
                'target_tenant_set' => [$context->activeTenantId],
                'effective_permissions' => [self::PERMISSION],
                'metadata' => [
                    'reason' => 'permission_not_granted',
                    'network_vpn_endpoint_id' => $endpoint->resourceId(),
                ],
            ]);

            throw new AuthorizationException('You are not allowed to issue VPN client profiles in this project.');
        }

        $owner = $this->owner($request, $user, $context, $auditEvents, $endpoint, $project);
        $expiresAt = $this->expiresAt($request);

        $profile = $profiles->issue($user, $context, $endpoint, $owner, $expiresAt);

        return response()->json(['data' => VpnClientProfilePayload::make($profile->refresh())], 201);
    }

    private function endpoint(string $endpointId, TenantContext $context): NetworkVpnEndpoint
    {
        /** @var NetworkVpnEndpoint|null $endpoint */
        $endpoint = NetworkVpnEndpoint::query()
            ->where('tenant_id', $context->activeTenantId)
            ->whereKey($endpointId)
            ->first();

        if (! $endpoint instanceof NetworkVpnEndpoint) {
            throw new NotFoundHttpException('VPN endpoint not found.');
        }

        return $endpoint;
    }

    private function owner(
        StoreVpnClientProfileRequest $request,
        User $actor,
        TenantContext $context,
        AuditEventWriter $auditEvents,
        NetworkVpnEndpoint $endpoint,
        Project $project,
    ): User {
        $ownerId = $request->integer('user_id');

        if ($ownerId === 0 || $ownerId === $actor->id) {
            return $actor;
        }

        // Codex M5c S4 P2: cross-user issuance must verify (a) the target user
        // is a member of the active tenant — otherwise an external account could
        // be assigned a profile and consume the tenant's quota — and (b) the
        // actor has the broader `profile.create` permission, which the
        // controller already gated above. The owner-only download enforcement
        // still applies, so admins cannot use this path to read another user's
        // private key material.
        /** @var User|null $owner */
        $owner = User::query()->whereKey($ownerId)->first();

        if (! $owner instanceof User) {
            throw new NotFoundHttpException('Profile owner not found.');
        }

        $isTenantMember = TenantMembership::query()
            ->where('tenant_id', $context->activeTenantId)
            ->where('user_id', $owner->id)
            ->exists();

        if (! $isTenantMember) {
            $auditEvents->append([
                'event_type' => 'network.vpnaas.profile',
                'action' => 'issue',
                'result' => 'denied',
                'actor_type' => 'user',
                'actor_id' => (string) $actor->id,
                'actor_tenant' => $context->activeTenantId,
                'resource_type' => 'project',
                'resource_id' => $project->getKey(),
                'resource_tenant' => $project->tenant_id,
                'target_tenant_set' => [$context->activeTenantId],
                'effective_permissions' => ['network.vpnaas.profile.create'],
                'metadata' => [
                    'reason' => 'target_user_not_tenant_member',
                    'target_user_id' => $owner->id,
                    'network_vpn_endpoint_id' => $endpoint->resourceId(),
                ],
            ]);

            throw new NotFoundHttpException('Profile owner is not a member of this tenant.');
        }

        return $owner;
    }

    private function expiresAt(StoreVpnClientProfileRequest $request): ?CarbonImmutable
    {
        $value = $request->input('expires_at');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}
