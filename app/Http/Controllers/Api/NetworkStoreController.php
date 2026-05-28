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
use App\Http\Requests\Api\StoreNetworkRequest;
use App\Models\Network;
use App\Models\NetworkOffering;
use App\Models\Project;
use App\Models\ProviderNetwork;
use App\Models\Subnet;
use App\Models\SubnetPool;
use App\Models\User;
use App\Networking\NetworkPayload;
use App\Networking\NetworkQuotaService;
use App\Networking\SubnetPoolAllocator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class NetworkStoreController extends Controller
{
    public function __invoke(
        StoreNetworkRequest $request,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        CurrentTokenAbilities $tokenAbilities,
        AuditEventWriter $auditEvents,
        NetworkQuotaService $networkQuota,
        SubnetPoolAllocator $subnetPoolAllocator,
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
        $offering = $this->offering($context, $request);
        $subnetPool = $this->subnetPool($context, $request);
        $permission = $this->permissionForOffering($offering);
        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission($permission),
            $project,
            $context,
        );

        if (! $tokenAbilities->allows($request, $permission) || ! $decision->allowed) {
            $this->audit($auditEvents, $user, $context, $project, 'create', 'denied', [$permission], [
                'network_offering_id' => $offering->getKey(),
                'offering_type' => $offering->offering_type,
                'reason' => 'permission_not_granted',
            ]);

            throw new AuthorizationException('You are not allowed to create this network.');
        }

        $quotaLimits = $networkQuota->assertPrivateNetworkAvailable($user, $context, $project);

        $network = DB::transaction(function () use ($request, $context, $user, $project, $offering, $subnetPool, $subnetPoolAllocator, $quotaLimits, $networkQuota, $auditEvents, $permission): Network {
            /** @var ProviderNetwork $providerNetwork */
            $providerNetwork = ProviderNetwork::query()->whereKey($offering->provider_network_id)->firstOrFail();
            $subnetInput = $this->stringKeyedArray($request->input('subnet'));
            $cidr = $this->subnetCidr($request, $context, $subnetPool, $subnetPoolAllocator);

            /** @var Network $network */
            $network = Network::query()->create([
                'tenant_id' => $context->activeTenantId,
                'project_id' => $project->getKey(),
                'network_offering_id' => $offering->getKey(),
                'name' => $request->string('name')->toString(),
                'slug' => $request->string('slug')->toString(),
                'state' => $providerNetwork->provider === 'fake' ? 'active' : 'pending_realization',
                'provider' => $providerNetwork->provider,
                'reachability' => $offering->reachability,
                'metadata' => [
                    ...$this->stringKeyedArray($request->input('metadata')),
                    'offering_type' => $offering->offering_type,
                    'provider_network_id' => $providerNetwork->getKey(),
                ],
                'sharing_scope' => 'tenant_local',
                'shared_with_tenants' => [],
            ]);

            Subnet::query()->create([
                'tenant_id' => $context->activeTenantId,
                'project_id' => $project->getKey(),
                'network_id' => $network->getKey(),
                'subnet_pool_id' => $subnetPool?->getKey(),
                'cidr' => $cidr,
                'ip_version' => 4,
                'gateway_ip' => is_string($subnetInput['gateway_ip'] ?? null) ? $subnetInput['gateway_ip'] : null,
                'dhcp_enabled' => (bool) ($subnetInput['dhcp_enabled'] ?? true),
                'allocation_pools' => $this->listOfStringMaps($subnetInput['allocation_pools'] ?? null),
                'dns_nameservers' => $this->stringList($subnetInput['dns_nameservers'] ?? null),
                'host_routes' => $this->listOfStringMaps($subnetInput['host_routes'] ?? null),
                'metadata' => [],
            ]);

            $networkQuota->consumeForNetwork($quotaLimits, $network, $user);
            $this->audit($auditEvents, $user, $context, $project, 'create', 'allowed', [$permission], [
                'network_id' => $network->getKey(),
                'network_offering_id' => $offering->getKey(),
                'provider_network_id' => $providerNetwork->getKey(),
                'offering_type' => $offering->offering_type,
                'cidr' => $cidr,
                'subnet_pool_id' => $subnetPool?->getKey(),
            ]);

            return $network;
        });

        return response()->json(['data' => NetworkPayload::make($network->refresh())], 201);
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

    private function offering(TenantContext $context, StoreNetworkRequest $request): NetworkOffering
    {
        $query = NetworkOffering::query()->where('tenant_id', $context->activeTenantId);
        $offeringId = $request->string('network_offering_id')->toString();

        if ($offeringId !== '') {
            $query->whereKey($offeringId);
        } else {
            $query->where('slug', $request->string('network_offering_slug')->toString());
        }

        /** @var NetworkOffering|null $offering */
        $offering = $query->first();

        if (! $offering instanceof NetworkOffering) {
            throw new NotFoundHttpException('Network offering not found.');
        }

        return $offering;
    }

    private function subnetPool(TenantContext $context, StoreNetworkRequest $request): ?SubnetPool
    {
        $subnetPoolId = $request->string('subnet.subnet_pool_id')->toString();
        $subnetPoolSlug = $request->string('subnet.subnet_pool_slug')->toString();

        if ($subnetPoolId === '' && $subnetPoolSlug === '') {
            return null;
        }

        $query = SubnetPool::query()->where('tenant_id', $context->activeTenantId);

        if ($subnetPoolId !== '') {
            $query->whereKey($subnetPoolId);
        } else {
            $query->where('slug', $subnetPoolSlug);
        }

        /** @var SubnetPool|null $pool */
        $pool = $query->first();

        if (! $pool instanceof SubnetPool) {
            throw new NotFoundHttpException('Subnet pool not found.');
        }

        return $pool;
    }

    private function subnetCidr(
        StoreNetworkRequest $request,
        TenantContext $context,
        ?SubnetPool $subnetPool,
        SubnetPoolAllocator $allocator,
    ): string {
        $explicitCidr = $request->string('subnet.cidr')->toString();

        if ($explicitCidr !== '') {
            return $explicitCidr;
        }

        if (! $subnetPool instanceof SubnetPool) {
            throw new NotFoundHttpException('Subnet pool not found.');
        }

        $rawPrefixLength = $request->input('subnet.prefix_length');
        $prefixLength = is_numeric($rawPrefixLength) ? (int) $rawPrefixLength : null;

        return $allocator->allocate($subnetPool, $context, $prefixLength);
    }

    private function permissionForOffering(NetworkOffering $offering): string
    {
        return match ($offering->offering_type) {
            'private-nat', 'double-nat' => 'network.create_nat',
            'private-isolated', 'template-defined' => 'network.create_private',
            default => 'network.attach_provider',
        };
    }

    /**
     * @param  list<string>  $effectivePermissions
     * @param  array<string, mixed>  $metadata
     */
    private function audit(
        AuditEventWriter $auditEvents,
        User $user,
        TenantContext $context,
        Project $project,
        string $action,
        string $result,
        array $effectivePermissions,
        array $metadata,
    ): void {
        $auditEvents->append([
            'event_type' => 'network.create',
            'action' => $action,
            'result' => $result,
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => 'project',
            'resource_id' => $project->getKey(),
            'resource_tenant' => $project->tenant_id,
            'target_tenant_set' => [$context->activeTenantId],
            'effective_permissions' => $effectivePermissions,
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
     * @return list<array<string, string>>
     */
    private function listOfStringMaps(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $normalized = [];

            foreach ($item as $key => $entry) {
                if (is_string($key) && is_string($entry)) {
                    $normalized[$key] = $entry;
                }
            }

            if ($normalized !== []) {
                $items[] = $normalized;
            }
        }

        return $items;
    }
}
