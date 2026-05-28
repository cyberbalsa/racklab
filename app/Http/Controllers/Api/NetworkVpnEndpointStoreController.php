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
use App\Http\Requests\Api\StoreNetworkVpnEndpointRequest;
use App\Models\Deployment;
use App\Models\Network;
use App\Models\NetworkVpnEndpoint;
use App\Models\Project;
use App\Models\User;
use App\Models\VpnPublicIpPool;
use App\Networking\VpnaasQuotaService;
use App\Networking\VpnEndpointAllocator;
use App\Networking\VpnEndpointPayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class NetworkVpnEndpointStoreController extends Controller
{
    private const string PERMISSION = 'network.vpnaas.endpoint.create';

    public function __invoke(
        StoreNetworkVpnEndpointRequest $request,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        CurrentTokenAbilities $tokenAbilities,
        AuditEventWriter $auditEvents,
        VpnaasQuotaService $quota,
        VpnEndpointAllocator $allocator,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        $project = $this->project($request->string('project_id')->toString());
        $network = $this->network($request->string('network_id')->toString(), $context, $project);
        $pool = $this->pool($context, $request);
        $deployment = $this->deployment($request, $context, $project);

        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission(self::PERMISSION),
            $project,
            $context,
        );

        if (! $tokenAbilities->allows($request, self::PERMISSION) || ! $decision->allowed) {
            $this->audit($auditEvents, $user, $context, $project, 'create', 'denied', [
                'reason' => 'permission_not_granted',
                'network_id' => $network->getKey(),
            ]);

            throw new AuthorizationException('You are not allowed to create VPN endpoints in this project.');
        }

        $quotaLimits = $quota->assertEndpointAvailable($user, $context, $project);
        $bindingLimits = $quota->assertEndpointBindingAvailable($user, $context, $project);

        $endpoint = DB::transaction(function () use ($user, $context, $project, $network, $pool, $deployment, $request, $quotaLimits, $bindingLimits, $quota, $auditEvents, $allocator): NetworkVpnEndpoint {
            /** @var NetworkVpnEndpoint $endpoint */
            $endpoint = NetworkVpnEndpoint::query()->create([
                'tenant_id' => $context->activeTenantId,
                'project_id' => $project->getKey(),
                'deployment_id' => $deployment?->getKey(),
                'network_id' => $network->getKey(),
                'vpn_public_ip_pool_id' => $pool->getKey(),
                'name' => $request->string('name')->toString(),
                'state' => NetworkVpnEndpoint::STATE_PENDING,
                'provider' => $pool->provider,
                'capability' => 'network:vpnaas:openvpn:v1',
                'metadata' => [],
                'sharing_scope' => 'tenant_local',
                'shared_with_tenants' => [],
                'created_by_id' => $user->getKey(),
            ]);

            $quota->consumeForEndpoint($quotaLimits, $endpoint, $user);

            // Allocate the first binding (public_ip + udp_port) and flip the
            // endpoint to running. M5c S6 will scale this to per-hypervisor-node
            // bindings via a placement signal; S3 ships the one-binding path.
            $binding = $allocator->allocate($endpoint);
            $quota->consumeForBinding($bindingLimits, $binding, $endpoint, $user);
            $endpoint->forceFill(['state' => NetworkVpnEndpoint::STATE_RUNNING])->save();

            $this->audit($auditEvents, $user, $context, $project, 'create', 'allowed', [
                'network_vpn_endpoint_id' => $endpoint->resourceId(),
                'network_id' => $network->getKey(),
                'vpn_public_ip_pool_id' => $pool->getKey(),
                'deployment_id' => $deployment?->getKey(),
                'provider' => $pool->provider,
                'binding_id' => $binding->getKey(),
                'public_ip' => $binding->public_ip,
                'udp_port' => $binding->udp_port,
            ]);

            return $endpoint;
        });

        return response()->json(['data' => VpnEndpointPayload::make($endpoint->refresh()->loadMissing('bindings'))], 201);
    }

    private function project(string $projectId): Project
    {
        /** @var Project|null $project */
        $project = Project::query()->whereKey($projectId)->first();

        if (! $project instanceof Project) {
            throw new NotFoundHttpException('Project not found.');
        }

        return $project;
    }

    private function network(string $networkId, TenantContext $context, Project $project): Network
    {
        /** @var Network|null $network */
        $network = Network::query()
            ->where('tenant_id', $context->activeTenantId)
            ->where('project_id', $project->getKey())
            ->whereKey($networkId)
            ->first();

        if (! $network instanceof Network) {
            throw ValidationException::withMessages([
                'network_id' => ['Network must belong to the target project.'],
            ]);
        }

        // PRD §09: VPNaaS endpoints attach only to isolated networks. Routable or
        // NAT-reachable networks already have a management-plane path, and bridging
        // a VPN client onto them would defeat the management-plane isolation
        // contract (see codex M5c S2 P1).
        if ($network->reachability !== 'isolated_no_ingress') {
            throw ValidationException::withMessages([
                'network_id' => ['VPN endpoints can only attach to networks with reachability=isolated_no_ingress.'],
            ]);
        }

        return $network;
    }

    private function pool(TenantContext $context, StoreNetworkVpnEndpointRequest $request): VpnPublicIpPool
    {
        $query = VpnPublicIpPool::query()->where('tenant_id', $context->activeTenantId);
        $poolId = $request->string('vpn_public_ip_pool_id')->toString();

        if ($poolId !== '') {
            $query->whereKey($poolId);
        } else {
            $slug = $request->string('vpn_public_ip_pool_slug')->toString();

            if ($slug === '') {
                throw ValidationException::withMessages([
                    'vpn_public_ip_pool_id' => ['Provide either vpn_public_ip_pool_id or vpn_public_ip_pool_slug.'],
                ]);
            }

            $query->where('slug', $slug);
        }

        /** @var VpnPublicIpPool|null $pool */
        $pool = $query->first();

        if (! $pool instanceof VpnPublicIpPool) {
            throw new NotFoundHttpException('VPN public IP pool not found.');
        }

        return $pool;
    }

    private function deployment(StoreNetworkVpnEndpointRequest $request, TenantContext $context, Project $project): ?Deployment
    {
        $deploymentId = $request->string('deployment_id')->toString();

        if ($deploymentId === '') {
            return null;
        }

        /** @var Deployment|null $deployment */
        $deployment = Deployment::query()
            ->where('tenant_id', $context->activeTenantId)
            ->whereKey($deploymentId)
            ->first();

        if (! $deployment instanceof Deployment || $deployment->project_id !== $project->getKey()) {
            throw ValidationException::withMessages([
                'deployment_id' => ['Deployment must belong to the target project.'],
            ]);
        }

        return $deployment;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(
        AuditEventWriter $auditEvents,
        User $user,
        TenantContext $context,
        Project $project,
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
            'resource_type' => 'project',
            'resource_id' => $project->getKey(),
            'resource_tenant' => $project->tenant_id,
            'target_tenant_set' => [$context->activeTenantId],
            'effective_permissions' => [self::PERMISSION],
            'metadata' => $metadata,
        ]);
    }
}
