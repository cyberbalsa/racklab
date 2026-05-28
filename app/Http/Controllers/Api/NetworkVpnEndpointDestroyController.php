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
use App\Models\NetworkVpnEndpoint;
use App\Models\NetworkVpnEndpointBinding;
use App\Models\Project;
use App\Models\User;
use App\Networking\VpnaasQuotaService;
use App\Networking\VpnClientProfileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class NetworkVpnEndpointDestroyController extends Controller
{
    private const string PERMISSION = 'network.vpnaas.endpoint.delete';

    public function __invoke(
        Request $request,
        string $endpoint,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        CurrentTokenAbilities $tokenAbilities,
        AuditEventWriter $auditEvents,
        VpnaasQuotaService $quota,
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

        $model = $this->endpoint($endpoint, $context);
        $project = $model->project;

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
            $this->audit($auditEvents, $user, $context, $project, $model, 'release', 'denied', [
                'reason' => 'permission_not_granted',
            ]);

            throw new AuthorizationException('You are not allowed to release VPN endpoints in this project.');
        }

        DB::transaction(function () use ($model, $user, $quota, $context, $project, $auditEvents, $profiles): void {
            // Codex M5c S4 P2: revoke attached client profiles BEFORE flipping
            // the endpoint state so each profile's audit row captures the
            // endpoint_release reason. revoke() closes any open VpnSessions
            // and releases the profile quota.
            $profiles->revokeAllForEndpoint($user, $context, $model, 'endpoint_release');

            $model->forceFill(['state' => NetworkVpnEndpoint::STATE_RELEASED])->save();

            /** @var list<NetworkVpnEndpointBinding> $bindings */
            $bindings = NetworkVpnEndpointBinding::query()
                ->where('network_vpn_endpoint_id', $model->getKey())
                ->get()
                ->all();

            foreach ($bindings as $binding) {
                $binding->forceFill(['state' => NetworkVpnEndpointBinding::STATE_RELEASED])->save();
                $quota->releaseForBinding($binding, $user);
            }

            $quota->releaseForEndpoint($model, $user);

            $this->audit($auditEvents, $user, $context, $project, $model, 'release', 'allowed', [
                'network_vpn_endpoint_id' => $model->resourceId(),
                'binding_count' => count($bindings),
            ]);
        });

        return response()->noContent();
    }

    private function endpoint(string $endpointId, TenantContext $context): NetworkVpnEndpoint
    {
        /** @var NetworkVpnEndpoint|null $endpoint */
        $endpoint = NetworkVpnEndpoint::query()
            ->with('project')
            ->where('tenant_id', $context->activeTenantId)
            ->whereKey($endpointId)
            ->first();

        if (! $endpoint instanceof NetworkVpnEndpoint) {
            throw new NotFoundHttpException('VPN endpoint not found.');
        }

        return $endpoint;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(
        AuditEventWriter $auditEvents,
        User $user,
        TenantContext $context,
        Project $project,
        NetworkVpnEndpoint $endpoint,
        string $action,
        string $result,
        array $metadata,
    ): void {
        $auditEvents->append([
            'event_type' => 'network.vpnaas.endpoint',
            'action' => $action,
            'result' => $result,
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => $endpoint->resourceType(),
            'resource_id' => $endpoint->resourceId(),
            'resource_tenant' => $endpoint->tenant_id,
            'target_tenant_set' => [$context->activeTenantId],
            'effective_permissions' => [self::PERMISSION],
            'metadata' => $metadata + ['project_id' => $project->getKey()],
        ]);
    }
}
