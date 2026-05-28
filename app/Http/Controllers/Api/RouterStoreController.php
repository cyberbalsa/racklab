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
use App\Http\Requests\Api\StoreRouterRequest;
use App\Models\Network;
use App\Models\Project;
use App\Models\Router;
use App\Models\RouterNetwork;
use App\Models\Subnet;
use App\Models\User;
use App\Networking\NetworkQuotaService;
use App\Networking\RouterPayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RouterStoreController extends Controller
{
    private const string PERMISSION = 'network.create_router';

    public function __invoke(
        StoreRouterRequest $request,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        CurrentTokenAbilities $tokenAbilities,
        AuditEventWriter $auditEvents,
        NetworkQuotaService $networkQuota,
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
        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission(self::PERMISSION),
            $project,
            $context,
        );

        if (! $tokenAbilities->allows($request, self::PERMISSION) || ! $decision->allowed) {
            $this->audit($auditEvents, $user, $context, $project, 'create', 'denied', [
                'reason' => 'permission_not_granted',
            ]);

            throw new AuthorizationException('You are not allowed to create this router.');
        }

        $networks = $this->networks($request, $context, $project);
        $quotaLimits = $networkQuota->assertRouterAvailable($user, $context, $project);

        $router = DB::transaction(function () use ($request, $context, $user, $project, $networks, $quotaLimits, $networkQuota, $auditEvents): Router {
            $provider = $networks[0]->provider;

            /** @var Router $router */
            $router = Router::query()->create([
                'tenant_id' => $context->activeTenantId,
                'project_id' => $project->getKey(),
                'name' => $request->string('name')->toString(),
                'slug' => $request->string('slug')->toString(),
                'state' => $provider === 'fake' ? 'active' : 'pending_realization',
                'provider' => $provider,
                'provider_router_id' => null,
                'metadata' => $this->stringKeyedArray($request->input('metadata')),
                'sharing_scope' => 'tenant_local',
                'shared_with_tenants' => [],
            ]);

            if ($provider === 'fake') {
                $router->forceFill(['provider_router_id' => 'fake-router-'.$router->id])->save();
            }

            foreach ($networks as $network) {
                /** @var Subnet|null $subnet */
                $subnet = $network->subnets->sortBy('cidr')->first();

                RouterNetwork::query()->create([
                    'tenant_id' => $context->activeTenantId,
                    'router_id' => $router->id,
                    'network_id' => $network->id,
                    'subnet_id' => $subnet?->id,
                    'interface_ip' => null,
                    'state' => $provider === 'fake' ? 'active' : 'pending_realization',
                    'provider_binding' => [
                        'provider' => $provider,
                        'router_id' => $router->provider_router_id,
                        'mode' => $provider === 'fake' ? 'fake-sdn-router' : 'pending-provider-realization',
                    ],
                    'metadata' => [],
                ]);
            }

            $networkQuota->consumeForRouter($quotaLimits, $router, $user);
            $this->audit($auditEvents, $user, $context, $project, 'create', 'allowed', [
                'router_id' => $router->id,
                'network_ids' => array_map(static fn (Network $network): string => $network->id, $networks),
                'provider' => $provider,
            ]);

            return $router;
        });

        return response()->json(['data' => RouterPayload::make($router->refresh())], 201);
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

    /**
     * @return list<Network>
     */
    private function networks(StoreRouterRequest $request, TenantContext $context, Project $project): array
    {
        $networkIds = $this->stringList($request->input('network_ids'));

        /** @var list<Network> $networks */
        $networks = Network::query()
            ->with('subnets')
            ->where('tenant_id', $context->activeTenantId)
            ->where('project_id', $project->getKey())
            ->whereIn('id', $networkIds)
            ->get()
            ->all();

        /** @var array<string, Network> $networksById */
        $networksById = [];

        foreach ($networks as $network) {
            $networksById[$network->id] = $network;
        }

        $ordered = [];

        foreach ($networkIds as $networkId) {
            if (! isset($networksById[$networkId])) {
                throw ValidationException::withMessages([
                    'network_ids' => ['Every router interface must reference a network in the target project.'],
                ]);
            }

            $ordered[] = $networksById[$networkId];
        }

        $providers = array_values(array_unique(array_map(
            static fn (Network $network): string => $network->provider,
            $ordered,
        )));

        if (count($providers) !== 1) {
            throw ValidationException::withMessages([
                'network_ids' => ['Router interfaces must use networks from the same provider.'],
            ]);
        }

        return $ordered;
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
            'event_type' => 'network.router',
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
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_string($item) && trim($item) !== '',
        ));
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
