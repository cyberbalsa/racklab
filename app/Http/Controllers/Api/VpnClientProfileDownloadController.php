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
use App\Models\Project;
use App\Models\User;
use App\Models\VpnClientProfile;
use App\Networking\VpnClientProfileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Owner-only profile download. PRD §09:
 * "Administrative permissions can rotate or revoke another user's profile,
 *  but they never expose that user's private client key material."
 */
final class VpnClientProfileDownloadController extends Controller
{
    private const string PERMISSION = 'network.vpnaas.profile.download';

    public function __invoke(
        Request $request,
        string $profile,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        CurrentTokenAbilities $tokenAbilities,
        AuditEventWriter $auditEvents,
        VpnClientProfileService $profiles,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        $model = $this->profile($profile, $context);

        // Owner-only download. An admin trying to download another user's profile
        // gets a 403 + audit event. Revocation is still admin-allowed via the
        // revoke endpoint; only the private key material is locked to the owner.
        if ($model->user_id !== $user->id) {
            $auditEvents->append([
                'event_type' => 'network.vpnaas.profile',
                'action' => 'download_denied',
                'result' => 'denied',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'actor_tenant' => $context->activeTenantId,
                'resource_type' => $model->resourceType(),
                'resource_id' => $model->resourceId(),
                'resource_tenant' => $model->tenant_id,
                'target_tenant_set' => [$context->activeTenantId, $model->tenant_id],
                'effective_permissions' => [self::PERMISSION],
                'metadata' => [
                    'reason' => 'owner_only_download',
                    'owner_user_id' => $model->user_id,
                ],
            ]);

            throw new AuthorizationException('VPN client profile downloads are owner-only.');
        }

        /** @var Project|null $project */
        $project = $model->endpoint()->first()?->project()->first();

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
            throw new AuthorizationException('You are not allowed to download VPN client profiles.');
        }

        $config = $profiles->downloadConfig($user, $context, $model);

        return response($config, 200, [
            'Content-Type' => 'application/x-openvpn-profile',
            'Content-Disposition' => sprintf('attachment; filename="%s.ovpn"', $model->common_name),
        ]);
    }

    private function profile(string $profileId, TenantContext $context): VpnClientProfile
    {
        /** @var VpnClientProfile|null $profile */
        $profile = VpnClientProfile::query()
            ->where('tenant_id', $context->activeTenantId)
            ->whereKey($profileId)
            ->first();

        if (! $profile instanceof VpnClientProfile) {
            throw new NotFoundHttpException('VPN client profile not found.');
        }

        return $profile;
    }
}
