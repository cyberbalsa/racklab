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
use App\Http\Requests\Api\StoreFloatingIpRequest;
use App\Models\DeploymentNetworkBinding;
use App\Models\FloatingIp;
use App\Models\FloatingIpPool;
use App\Models\Project;
use App\Models\User;
use App\Networking\FloatingIpAllocator;
use App\Networking\FloatingIpPayload;
use App\Networking\NetworkQuotaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class FloatingIpStoreController extends Controller
{
    private const string PERMISSION = 'network.allocate_public_ip';

    public function __invoke(
        StoreFloatingIpRequest $request,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        CurrentTokenAbilities $tokenAbilities,
        AuditEventWriter $auditEvents,
        NetworkQuotaService $networkQuota,
        FloatingIpAllocator $allocator,
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
        $pool = $this->pool($context, $request);
        $binding = $this->binding($request, $context, $project);
        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission(self::PERMISSION),
            $project,
            $context,
        );

        if (! $tokenAbilities->allows($request, self::PERMISSION) || ! $decision->allowed) {
            $this->audit($auditEvents, $user, $context, $project, 'allocate', 'denied', [
                'floating_ip_pool_id' => $pool->getKey(),
                'reason' => 'permission_not_granted',
            ]);

            throw new AuthorizationException('You are not allowed to allocate floating IPs.');
        }

        $quotaLimits = $networkQuota->assertFloatingIpAvailable($user, $context, $project);

        $floatingIp = DB::transaction(function () use ($request, $context, $user, $project, $pool, $binding, $allocator, $quotaLimits, $networkQuota, $auditEvents): FloatingIp {
            $address = $allocator->allocate($pool);

            /** @var FloatingIp $floatingIp */
            $floatingIp = FloatingIp::query()->create([
                'tenant_id' => $context->activeTenantId,
                'project_id' => $project->getKey(),
                'floating_ip_pool_id' => $pool->getKey(),
                'deployment_network_binding_id' => $binding?->getKey(),
                'allocated_by_id' => $user->getKey(),
                'address' => $address,
                'state' => 'allocated',
                'provider' => $pool->provider,
                'provider_binding' => [
                    'provider' => $pool->provider,
                    'pool_id' => $pool->getKey(),
                    'address' => $address,
                    'deployment_network_binding_id' => $binding?->getKey(),
                    'mode' => $pool->provider === 'fake' ? 'fake-floating-ip' : 'pending-provider-realization',
                ],
                'metadata' => $this->stringKeyedArray($request->input('metadata')),
                'released_at' => null,
            ]);

            $networkQuota->consumeForFloatingIp($quotaLimits, $floatingIp, $user);
            $this->audit($auditEvents, $user, $context, $project, 'allocate', 'allowed', [
                'floating_ip_id' => $floatingIp->getKey(),
                'floating_ip_pool_id' => $pool->getKey(),
                'deployment_network_binding_id' => $binding?->getKey(),
                'address' => $address,
                'provider' => $pool->provider,
            ]);

            return $floatingIp;
        });

        return response()->json(['data' => FloatingIpPayload::make($floatingIp->refresh())], 201);
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

    private function pool(TenantContext $context, StoreFloatingIpRequest $request): FloatingIpPool
    {
        $query = FloatingIpPool::query()->where('tenant_id', $context->activeTenantId);
        $poolId = $request->string('floating_ip_pool_id')->toString();

        if ($poolId !== '') {
            $query->whereKey($poolId);
        } else {
            $query->where('slug', $request->string('floating_ip_pool_slug')->toString());
        }

        /** @var FloatingIpPool|null $pool */
        $pool = $query->first();

        if (! $pool instanceof FloatingIpPool) {
            throw new NotFoundHttpException('Floating IP pool not found.');
        }

        return $pool;
    }

    private function binding(StoreFloatingIpRequest $request, TenantContext $context, Project $project): ?DeploymentNetworkBinding
    {
        $bindingId = $request->string('deployment_network_binding_id')->toString();

        if ($bindingId === '') {
            return null;
        }

        /** @var DeploymentNetworkBinding|null $binding */
        $binding = DeploymentNetworkBinding::query()
            ->with('deployment')
            ->where('tenant_id', $context->activeTenantId)
            ->whereKey($bindingId)
            ->first();

        if (! $binding instanceof DeploymentNetworkBinding || $binding->deployment?->project_id !== $project->getKey()) {
            throw ValidationException::withMessages([
                'deployment_network_binding_id' => ['Deployment network binding must belong to the target project.'],
            ]);
        }

        if ($binding->state !== 'attached') {
            throw ValidationException::withMessages([
                'deployment_network_binding_id' => ['Deployment network binding must be attached before mapping a floating IP.'],
            ]);
        }

        return $binding;
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
            'event_type' => 'network.floating_ip',
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

    /**
     * @return array<string, mixed>
     */
    private function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $normalized[$key] = $item;
            }
        }

        return $normalized;
    }
}
