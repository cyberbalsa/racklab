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
use App\Networking\VpnClientProfilePayload;
use App\Networking\VpnClientProfileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class VpnClientProfileRevokeController extends Controller
{
    private const string PERMISSION = 'network.vpnaas.profile.revoke';

    public function __invoke(
        Request $request,
        string $profile,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        CurrentTokenAbilities $tokenAbilities,
        AuditEventWriter $auditEvents,
        VpnClientProfileService $profiles,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        $model = $this->profile($profile, $context);
        /** @var Project|null $project */
        $project = $model->endpoint()->first()?->project()->first();

        if (! $project instanceof Project) {
            throw new NotFoundHttpException('VPN endpoint project missing.');
        }

        // Token scope is the OUTER gate — even an owner needs a token that carries
        // `network.vpnaas.profile.revoke` because revocation is destructive and
        // closes any open VPN sessions. A read-only or download-only token must
        // not be usable to revoke (codex M5c S4 P1).
        if (! $tokenAbilities->allows($request, self::PERMISSION)) {
            throw new AuthorizationException('The current token does not include network.vpnaas.profile.revoke.');
        }

        // Owners can revoke their own; admins/support/instructor can revoke any.
        $isOwner = $model->user_id === $user->id;
        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission(self::PERMISSION),
            $project,
            $context,
        );

        if (! $decision->allowed && ! $isOwner) {
            $auditEvents->append([
                'event_type' => 'network.vpnaas.profile',
                'action' => 'revoke',
                'result' => 'denied',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'actor_tenant' => $context->activeTenantId,
                'resource_type' => $model->resourceType(),
                'resource_id' => $model->resourceId(),
                'resource_tenant' => $model->tenant_id,
                'target_tenant_set' => [$context->activeTenantId, $model->tenant_id],
                'effective_permissions' => [self::PERMISSION],
                'metadata' => ['reason' => 'permission_not_granted'],
            ]);

            throw new AuthorizationException('You are not allowed to revoke VPN client profiles in this project.');
        }

        $reason = $request->string('reason')->toString() !== ''
            ? $request->string('reason')->toString()
            : ($isOwner ? 'owner_initiated' : 'admin_revoked');

        $profiles->revoke($user, $context, $model, $reason);

        return response()->json(['data' => VpnClientProfilePayload::make($model->refresh())]);
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
