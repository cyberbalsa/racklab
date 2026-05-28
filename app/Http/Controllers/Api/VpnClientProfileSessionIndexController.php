<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

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
use App\Models\VpnSession;
use App\Networking\VpnSessionPayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class VpnClientProfileSessionIndexController extends Controller
{
    private const string PERMISSION = 'network.vpnaas.session.read';

    public function __invoke(
        Request $request,
        string $profile,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        CurrentTokenAbilities $tokenAbilities,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        if (! $tokenAbilities->allows($request, self::PERMISSION)) {
            throw new AuthorizationException(sprintf('The current token does not include %s.', self::PERMISSION));
        }

        $model = $this->profile($profile, $context);
        /** @var Project|null $project */
        $project = $model->endpoint()->first()?->project()->first();

        if (! $project instanceof Project) {
            throw new NotFoundHttpException('VPN endpoint project missing.');
        }

        $isOwner = $model->user_id === $user->id;
        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission(self::PERMISSION),
            $project,
            $context,
        );

        // Owners always see their own session ledger; admin/support/instructor
        // can see any profile's sessions in their project scope.
        if (! $isOwner && ! $decision->allowed) {
            throw new AuthorizationException('You are not allowed to read VPN session history for this profile.');
        }

        /** @var list<VpnSession> $sessions */
        $sessions = VpnSession::query()
            ->where('vpn_client_profile_id', $model->getKey())
            ->orderByDesc('connected_at')
            ->limit(100)
            ->get()
            ->all();

        return response()->json([
            'data' => array_map(
                VpnSessionPayload::make(...),
                $sessions,
            ),
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
